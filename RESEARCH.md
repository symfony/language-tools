# Symfony Language Server Implementation Research

Status: Draft

Research date: July 29, 2026

## Executive summary

The proposed architecture is feasible, but one assumption in the RFP needs to
be refined. Symfony's normal development cache contains most of the effective
application model, yet the useful access path is generally to boot the cached
kernel or execute an official debug command. The LSP should not parse generated
cache files directly.

The initial bridge can obtain high-quality machine-readable data for routes,
services, autowiring types, effective configuration, events, Twig metadata, and
forms across all currently supported stable Symfony branches. Translation
catalogues also have a suitable public runtime API. Source locations for
individual routes, service declarations, translation entries, and configuration
nodes are not consistently preserved by these runtime APIs. The source index
must provide those locations, and Symfony should gain focused public export APIs
where runtime-to-source reconciliation is otherwise ambiguous.

The recommended implementation choices are:

* support the branches returned by `https://symfony.com/releases.json` rather
  than hard-coding a version list;
* ship a self-contained server and a small bridge compatible with the minimum
  PHP requirement of every supported Symfony branch;
* invoke a bundled bridge entry point through the configured project PHP command
  instead of requiring a project package;
* boot the selected environment's cached kernel for public runtime APIs and use
  official JSON debug output where it is already complete;
* run `cache:clear` when relevant saved resources change, and `cache:warmup` only
  when an existing built container merely needs optional warmers;
* use Phpactor's tolerant PHP parser fork after upstreaming or pinning a stable
  release suitable for Symfony;
* use a bundled native Tree-sitter runtime and only the PHP, Twig, and YAML
  grammars for error-tolerant Twig and YAML parsing;
* parse dotenv files with a small position-aware lexer, using Symfony Dotenv only
  for semantic validation;
* model a runtime snapshot and per-document overlays as immutable generations;
* represent incomplete rename coverage with annotated edits requiring
  confirmation when the client supports them, and otherwise ask for
  confirmation before returning the edit;
* build standalone binaries with `static-php-cli`, following the proven Laravel
  LSP approach.

The first upstream Symfony work should be a public, versioned metadata export
command or service. It should consolidate safe LSP metadata without exposing
parameter or environment values and without making the LSP depend on internal
debug command classes.

## Research method

The investigation covered:

* Symfony's current release metadata and PHP requirements;
* FrameworkBundle, Routing, DependencyInjection, Translation, Twig, Config,
  Messenger, EventDispatcher, Form, Security, Validator, and Serializer APIs;
* official debug commands and JSON descriptors on Symfony 6.4, 7.4, 8.0, and
  8.1;
* warmed `var/cache/dev` contents from temporary applications on those four
  branches;
* Laravel LSP's parser, runtime indexing, and binary distribution model;
* candidate PHP, Twig, YAML, and dotenv parsers;
* LSP 3.17 rename and workspace-edit capabilities;
* `static-php-cli` and current cross-platform packaging practice.

Temporary Symfony fixtures were created inside this repository, exercised, and
removed. No fixture or dependency artifact remains in the working tree.

## Supported versions

### Current source of truth

Symfony publishes machine-readable lifecycle data at:

```text
https://symfony.com/releases.json
```

At the time of research it reports these supported stable branches:

| Symfony | Minimum PHP |
| --- | --- |
| 6.4 | 8.1 |
| 7.4 | 8.2 |
| 8.0 | 8.4 |
| 8.1 | 8.4 |

The endpoint also distinguishes supported, maintained development, and
security-only branches. The LSP should consume this endpoint during its own
release process to generate a tested compatibility matrix. Runtime operation
must remain offline and use the matrix embedded in the binary.

### Recommendation

The project bridge should have a PHP 8.1 syntax baseline while Symfony 6.4 is
supported. The standalone server can use its own current PHP runtime. The
server should reject unsupported Symfony branches with a clear status message
and continue in static-only mode.

The bridge should inspect `Composer\InstalledVersions` after loading the
project autoloader. It should validate `symfony/framework-bundle`, not only a
single component, because FrameworkBundle is an explicit product requirement.

Support removal should be an ordinary minor release of Symfony LSP coordinated
with Symfony's maintenance lifecycle. CI fixtures should be generated from the
embedded matrix, not maintained as a separate policy.

## Effective metadata available today

### Cache inventory

A warmed development cache on all tested branches contained:

* the compiled container and its resource freshness metadata;
* the debug container dump used by `debug:container`;
* compiled route matcher and generator data;
* translation catalogue caches;
* compiled Twig templates;
* generated Config builder classes where supported;
* warmed validator and serializer metadata;
* compiler and deprecation logs;
* removed service IDs and generated service factories.

Symfony 7.4 and later also materialize JSON freshness metadata and a serialized
debug `ContainerBuilder`. Symfony 8.1 adds more build metadata. These filenames
and formats are implementation details and differ by version.

The important result is not that the LSP can parse these files. It is that
booting the kernel and running public Symfony behavior can cheaply reuse them.
On the temporary applications, a no-op `cache:warmup` took about 250 to 300 ms.
Real applications need separate benchmarks.

### Safe access rule

The bridge should:

1. load the project autoloader;
2. boot the selected FrameworkBundle kernel;
3. let Symfony reuse or build the selected environment's normal cache;
4. query public runtime APIs or execute public console behavior;
5. normalize the result to a versioned snapshot;
6. shut down the kernel.

It should not include generated route files, translation catalogue PHP, dumped
container PHP, debug container XML or serialized data itself. Symfony may use
those artifacts internally through public commands. This distinction keeps the
LSP independent from cache formats.

### Metadata matrix

| Area | Available effective data | Main gap |
| --- | --- | --- |
| Routes | Names, paths, methods, defaults | Declaration origin |
| Services | Definitions, aliases, tags, arguments | Public export and declaration origin |
| Autowiring | Available types and aliases | Stable export contract |
| Parameters | Names, values, deprecations | Values must not cross the bridge |
| Environment | Processors, result types, names | Output that omits real values |
| Effective config | Processed configuration | Schema, origin, and constraints |
| Config schema | Children, types, defaults, docs, enums | Serializer for every extension style |
| Twig | Extensions, loader paths, exact lookup | Complete names and source ranges |
| Translations | Locales, domains, messages, fallbacks | Message-level source provenance |
| Events | Names, listener callables, priorities | Listener declaration source |
| Forms | Types and resolved options | Source locations and stable export |
| Messenger | Buses, messages, handlers, conditions | No JSON output today |
| Security | Firewalls and compiled details | No JSON output; attributes are open-ended |
| Validator | Constraints and mapped members | Compact export and source provenance |
| Serializer | Mapped attributes and groups | Compact export and source provenance |

The current public access paths include `RouterInterface::getRouteCollection()`,
`TranslatorBagInterface`, `MessageCatalogueInterface`, Config node APIs, and the
JSON output of `debug:router`, `debug:container`, `debug:config`,
`debug:event-dispatcher`, `debug:twig`, and `debug:form`.

### Routes

Routes are the strongest first provider. `RouterInterface::getRouteCollection()`
is public on every tested branch. `RouteCollection` exposes names, aliases,
resources, and priorities, while each `Route` exposes path, host, schemes,
methods, defaults, requirements, options, and condition.

`debug:router --format=json --show-aliases` produced valid JSON on Symfony 6.4,
7.4, 8.0, and 8.1. It includes all effective route semantics needed for
completion and diagnostics. It does not include the route declaration file and
line. Route collection resources identify contributing files but do not map an
individual route to its declaration.

Recommendation:

* obtain effective routes through `RouterInterface` in the bridge;
* keep route source locations in the static PHP and YAML indexes;
* reconcile by route name and effective semantics;
* add an optional public route origin to Symfony's route metadata or a dedicated
  metadata export if imported prefixes, generated routes, or duplicate source
  names make reconciliation ambiguous.

### Dependency injection

The runtime `ContainerInterface` is intentionally insufficient for tooling. It
exposes instantiated public services, parameter access, service IDs, and removed
IDs, but not all private definitions and compiler-pass results.

`debug:container --format=json --show-hidden` produced rich JSON on every tested
branch. It includes definitions, aliases, classes, visibility, laziness,
sharing, deprecation, factories, calls, tags, service arguments, usages, and,
on newer branches, decoration stacks. `debug:container --types --format=json`
provides autowiring types.

The command obtains this information from Symfony's debug `ContainerBuilder`.
How it reloads that builder changed between 6.4 and later branches. The command
and descriptor classes are internal even though the command-line behavior is a
public developer interface.

`debug:container --parameters --format=json` includes actual parameter values.
It must not be used as bridge output. If used internally to discover names, the
bridge must drop values immediately and test that they cannot reach stdout,
logs, snapshots, or hovers.

`debug:container --env-vars` is explicitly unsafe for the LSP because its text
output includes real environment values. The LSP must never execute it.

Recommendation:

* use the official JSON command as a prototype extraction path;
* introduce a public safe metadata exporter in Symfony before declaring the
  service provider stable;
* export parameter names and deprecations, never values;
* export referenced environment names, processor chains, defaults-present
  flags, and provided types, never resolved values;
* source-index service and parameter declarations because runtime metadata does
  not consistently preserve configuration file and line.

### Bundle configuration

The Config component exposes enough public tree APIs to serialize a useful
schema:

* node name and path;
* required and default state;
* normalized types;
* child nodes;
* info and examples;
* deprecation metadata;
* enum values;
* extra-key and merge behavior where exposed.

`debug:config <alias> --format=json` returns effective processed values, not the
schema needed for completion. `config:dump-reference` is human-oriented and its
JSON availability varies by branch and output purpose.

A bridge exporter can obtain each bundle extension's `ConfigurationInterface`
and walk its `TreeBuilder`. Modern `AbstractBundle` configurations and classic
extensions need to be normalized behind one Symfony API. Some semantic
validation is implemented in closures and cannot be serialized. The schema
should mark these constraints as runtime-only rather than attempting to encode
arbitrary PHP.

Recommendation: add a public Config schema normalizer that returns JSON-safe
node metadata. This is reusable beyond LSP and avoids coupling the bridge to
bundle implementation styles.

### Twig

`debug:twig --format=json` produced valid JSON on all tested branches. It
contains functions, filters, tests, globals, and loader paths. Passing a template
name returns its matched source path. This is enough for extension completion
and resolving a known template.

Complete template enumeration is not currently a clean public runtime API.
Twig's `FilesystemLoader` exposes namespaces and paths, but applications can use
chain or custom loaders. Symfony's `TemplateIterator` knows the complete warmup
set, but it is internal. Compiled Twig cache files should not be parsed.

Recommendation:

* use `debug:twig` for extension metadata and exact template lookup;
* enumerate filesystem loader paths in the static index;
* support custom loaders through exact `LoaderInterface::exists()` and
  `getSourceContext()` queries when a literal template is known;
* add a public iterable template-name provider to TwigBundle if exhaustive
  completion must include custom loaders.

Twig parsing should not use Twig's production parser for live editing because it
throws on incomplete templates. Tree-sitter is a better fit for partial syntax
and byte ranges. Twig itself remains the semantic validator after save.

### Translations

`TranslatorBagInterface` and `MessageCatalogueInterface` are public and stable.
They expose domains, messages, locale, fallback catalogues, and resources. The
translator transparently reads warmed catalogues from the normal cache.

In the fixture applications, a custom message was available through the public
catalogue, but `getResources()` did not map it back to its source file. Static
resource parsing is therefore required for definitions, references, rename, and
message-level navigation.

Recommendation:

* runtime snapshot: effective key, domain, locale, message, and fallback chain;
* source index: resource file, key range, message range, and placeholders;
* reconcile by locale, domain, and key;
* use the runtime catalogue only to decide effective existence;
* keep missing-key diagnostics opt-in as already decided.

The snapshot may carry message text because hover and placeholder completion
need it, but traffic logs and telemetry must redact or omit it. External
translation providers make completeness project-specific.

### Environment variables

The Dotenv component correctly models precedence and syntax but is not a source
parser with editable ranges. Static indexing should lex `.env`, `.env.local`,
`.env.<environment>`, and `.env.<environment>.local` without resolving values.

The bridge can obtain processor names and result types through
`EnvVarProcessorInterface::getProvidedTypes()` and safe container metadata. It
must never run `debug:container --env-vars`, `debug:dotenv`, or a secrets command
because these may reveal real values.

The snapshot should contain only:

* variable name;
* declaration file and range from the static index;
* declared default presence, not its value;
* processor chain;
* inferred result type;
* whether the name is referenced by the container.

### Later providers

`debug:event-dispatcher --format=json` and `debug:form --format=json` are already
machine-readable across the tested branches. They are strong P1 candidates.

`debug:messenger` and `debug:firewall` are text-only. Their internal command
inputs are structured, but parsing their text output would create an unstable
contract. Add public JSON exporters before implementing stable providers.

Validator and serializer have public metadata factories. They can be queried by
the bridge without parsing cache files, although source provenance remains a
static-index concern.

## Public Symfony APIs to add

### One versioned metadata exporter

Rather than add an LSP-only API to every component immediately, introduce a
small public metadata export facility in FrameworkBundle. A command such as:

```text
bin/console debug:metadata --format=json --sections=container,routes,config,twig,translations
```

would provide a versioned, safe schema. The precise command name should be
decided in Symfony, but the contract should have these properties:

* public and covered by backward-compatibility rules;
* additive within a schema version;
* sections can be requested independently;
* no service instantiation beyond what each section requires;
* no parameter values, environment values, secrets, or credentials;
* absolute source paths only when Symfony knows their exact origin;
* resources and completeness flags for every section;
* errors isolated by section so one optional bundle cannot invalidate the whole
  snapshot;
* JSON written to stdout and diagnostics to stderr.

A dedicated bridge script may call the corresponding service directly, but the
console command makes the contract independently testable and useful to other
tools.

### Proposed initial schema additions

1. **Container metadata:** safe definition graph, aliases, autowiring types,
   tags, deprecations, decorators, parameter names, and environment reference
   metadata.
2. **Route origins:** declaration resource and line or source token when known.
3. **Config schema:** normalized Config node trees for installed extensions.
4. **Template names:** iterable effective template names and exact source paths
   without relying on internal `TemplateIterator`.
5. **Translation origins:** message-level resource provenance where loaders can
   supply it.

Events and forms can initially reuse existing JSON commands. Messenger and
Security should gain JSON output before their LSP providers are stabilized.

## Bridge design

### Invocation

The standalone server should extract its bundled bridge script to its own
versioned application-data directory. It then invokes an argument array such as:

```text
<phpCommand...> /path/to/bridge.php snapshot \
    --project=/workspace/app \
    --environment=dev \
    --debug=1 \
    --sections=routes,container,twig,translations,config
```

The bridge loads `<project>/vendor/autoload.php`, discovers the FrameworkBundle
kernel using the same public runtime convention as `bin/console`, validates the
Symfony branch, boots it, collects requested sections, emits one JSON document,
and shuts down.

Do not pass PHP code through `bin/console` or require PsySH. A fixed bridge entry
point is safer, easier to test, and works in minimal Symfony applications.

For wrappers such as DDEV, the host bridge path may not exist inside the
container. The bridge runner therefore needs one of these deployment modes:

1. execute a project-relative copy under `var/symfony-lsp/<version>/bridge.php`;
2. stream the fixed script to the configured PHP command through stdin;
3. allow an explicit path mapping in advanced configuration.

Recommendation: copy the bridge under the project `var/` directory after trust
is granted. This is not a Composer dependency, survives arbitrary PHP command
prefixes, and can be versioned and replaced atomically. Add the path to the
project's ignore file only on explicit user request; the LSP itself should not
edit ignore files automatically.

### Refresh behavior

A relevant save means the effective model can have changed. Running
`cache:warmup` alone does not rebuild a fresh compiled container when its
resources changed. The bridge should use:

* `cache:clear --no-interaction` for stale or changed compiled resources;
* `cache:warmup --no-interaction` when the built container is current but
  optional warmed metadata is missing;
* no command when the cache is current and all requested sections are present.

The server should debounce saves, serialize refreshes per application root, and
retain the last valid snapshot. It should never delete cache directories
itself. Symfony's command owns atomic cache replacement and locking behavior.

The first implementation can conservatively run `cache:clear` after any saved
PHP file or Symfony configuration resource and optimize later using the
container and router resource sets. Template, translation, and dotenv saves can
refresh only their relevant static indexes first; runtime refresh policy should
be measured because Symfony may already invalidate their dedicated caches.

### Snapshot envelope

Use one immutable snapshot envelope:

```json
{
  "schemaVersion": 1,
  "generation": "opaque-content-hash",
  "project": {
    "root": "/workspace/app",
    "symfonyVersion": "8.1.2",
    "phpVersion": "8.5.8",
    "environment": "dev",
    "debug": true,
    "cacheDir": "/workspace/app/var/cache/dev"
  },
  "sections": {
    "routes": {
      "complete": true,
      "generation": "section-hash",
      "items": []
    }
  },
  "errors": []
}
```

Each section should include:

* `complete`: whether absence is safe to diagnose;
* `generation`: a section content hash;
* `items`: normalized metadata;
* `resources`: source resources that invalidate the section;
* `warnings`: non-fatal extraction limitations.

Use associative maps keyed by canonical identity in the internal model for fast
lookup. Serialize deterministic arrays sorted by identity so hashes and tests
remain stable.

### Live overlays

Keep runtime snapshots immutable. Each open document produces an immutable
`DocumentOverlay` keyed by URI and document version. It contains declarations,
references, diagnostics-safe facts, and parser errors.

A `SemanticView` merges:

1. the latest valid runtime snapshot;
2. the saved static workspace index;
3. open-document overlays, which replace the saved facts from those URIs.

Every fact should have:

* kind and canonical identity;
* source URI and byte range;
* origin: runtime, saved static, or open overlay;
* confidence: exact or provisional;
* document version or snapshot generation;
* environment;
* editable flag.

Completion and navigation can use exact overlay facts immediately. Diagnostics
that need an effective closed-world model use only a matching saved snapshot.
When an overlay shadows a contributing runtime resource, such diagnostics are
suppressed until refresh rather than reported against stale data.

## Parser selection

### PHP

Laravel LSP uses `microsoft/tolerant-php-parser`. The upstream Microsoft
repository is MIT licensed and designed for IDE scenarios, but its last parser
change was in 2024. Phpactor's fork added PHP 8.4 property hooks, asymmetric
visibility, typed constants, and related fixes in 2025. Phpactor itself depends
on a development branch of this fork because no stable Packagist release is
available.

Recommendation:

* use the Phpactor fork, not the older Microsoft release;
* ask Phpactor to tag a stable release or maintain a small Symfony fork until it
  does;
* pin an exact commit in early prototypes;
* contribute parser fixes upstream;
* use only parser and position APIs, not Phpactor's language server or full
  indexer.

The parser gives a round-trippable AST, parent links, byte offsets, missing
nodes, and resolved qualified names. This is a good fit for high-confidence
local inference and PHP edits.

`nikic/php-parser` remains useful for valid saved PHP and transformations, but it
is not the primary live parser because recovery can return no AST for some
incomplete documents.

### Twig and YAML

Tree-sitter is the best common model for incomplete Twig and YAML because it
provides error nodes, byte ranges, and fast parsing. There is now an actively
maintained PHP extension package,
`xberg-io/tree-sitter-language-pack`, with prebuilt cross-platform bindings and
grammars for PHP, Twig, and YAML.

It cannot be adopted unchanged for the standalone LSP because its default model
downloads grammars on demand, while the LSP requires offline operation. Its PHP
API also does not currently expose incremental parsing with an old edited tree.

Recommendation:

* vendor or build one native extension containing only the Tree-sitter runtime
  and pinned Twig and YAML grammars;
* compile it into each standalone PHP binary if `static-php-cli` supports the
  extension, or ship a same-platform extension beside the binary;
* pin grammar commits and preserve their licenses;
* parse whole open Twig and YAML documents initially;
* add incremental parsing only after profiling shows whole-document parsing is
  a bottleneck.

The currently packaged Twig grammar comes from `gbprod/tree-sitter-twig` and is
actively maintained, but its WTFPL license should receive explicit legal review.
`kaermorchen/tree-sitter-twig` is MPL-2.0 and also active, but less established.
Both require Symfony-specific fixture evaluation before selection.

Symfony YAML custom tags such as `!tagged_iterator`, `!service`, and
`!php/const` need dedicated parser fixtures. The grammar need not assign their
semantics; it only needs stable scalar and collection ranges around them.

A promising higher-level YAML option is `mougrim/yaml-cst`, which offers
path-based indexing and non-destructive edits on Tree-sitter. It is very new,
requires FFI and external native libraries, and does not support multi-document
YAML. Reuse its design or selected code only after evaluating maturity; do not
make FFI a runtime requirement of the standalone binary.

### Dotenv

Implement a small position-aware dotenv lexer in the server. The format is
line-oriented, and the LSP needs declaration names, assignment ranges, quoting,
comments, and duplicate precedence rather than value expansion. Use Symfony
Dotenv in tests and in the bridge to verify semantic precedence, but never expose
resolved values.

### Parser acceptance benchmark

Before committing to parser dependencies, run the candidates against a corpus of
real Symfony applications and malformed edit states. Record:

* parse time and peak memory;
* byte-range correctness with UTF-8 input;
* recovery around an incomplete attribute, method call, Twig expression, YAML
  mapping, and quoted dotenv value;
* support for current PHP syntax;
* round-trip edit reliability;
* native binary size and platform availability.

## Best-effort rename

LSP 3.17 has no standard field on `WorkspaceEdit` that says reference discovery
was incomplete. It does support change annotations when the client advertises
`workspace.workspaceEdit.changeAnnotationSupport`. A `ChangeAnnotation` can have
`needsConfirmation: true` and a human-readable description.

Recommendation:

1. `prepareRename` accepts only identifiers with a known Symfony meaning.
2. `rename` finds every exact static declaration and reference under editable
   roots.
3. It returns versioned `documentChanges`, never unversioned edits for open
   documents.
4. If coverage may be incomplete and the client honors change annotations,
   annotate every edit with:

   ```text
   Best-effort Symfony rename
   Dynamic references and files outside editable roots may remain unchanged.
   ```

   and set `needsConfirmation` to `true`.
5. If the client cannot honor annotations, ask once through
   `window/showMessageRequest` before returning the `WorkspaceEdit`.
6. If the user declines, return no edit.
7. References found under `vendor/` or generated files are reported in the
   confirmation summary but never edited.
8. Revalidate open document versions immediately before returning the edit.

Clients decide how to preview a rename, so the server cannot guarantee a rich
preview in every editor. Confirmation and annotations are the strongest
standard behavior available.

A Symfony-specific code action can provide a richer alternative, such as
"Rename route and show coverage", but the actual edits should still be a
standard `WorkspaceEdit`.

## Standalone binary research

Laravel LSP already demonstrates the intended distribution model:

1. build a PHAR;
2. obtain the `static-php-cli` micro SAPI;
3. combine the PHAR and micro SAPI;
4. publish binaries for macOS arm64 and x64, Linux arm64 and x64, and Windows
   x64.

`static-php-cli` is active and MIT licensed. This is the recommended initial
build system.

The Symfony LSP platform matrix should start with:

| Platform | Architectures |
| --- | --- |
| macOS | arm64, x64 |
| Linux glibc | arm64, x64 |
| Windows | x64 |

Linux musl and Windows arm64 can follow demand and build support. Every release
should also publish the PHAR for users with a compatible PHP runtime.

The build must include all extensions needed by `fabpot/json-rpc-peer`, Amp,
parsers, JSON, mbstring, tokenizer, and process handling. Native parser choices
must be proven in the combined binary before parser selection is final.

Releases should include:

* SHA-256 checksums;
* a software bill of materials;
* reproducible build instructions;
* binary smoke tests for `--version`, initialization, parser loading, and bridge
  execution;
* provenance or signing supported by the Symfony release infrastructure.

The binary must not download parsers or dependencies at runtime.

## Performance benchmark plan

The temporary minimal fixtures confirm that warm-cache public queries are fast,
but they are not representative. Create three permanent benchmark applications:

* small: Symfony skeleton with each supported provider;
* medium: a representative application with hundreds of services, routes,
  templates, and translation keys;
* large: thousands of services and templates with several third-party bundles.

Measure cold and warm cases separately:

| Operation | Target |
| --- | --- |
| server initialize without bridge | under 100 ms |
| open and parse one PHP file | under 30 ms |
| open and parse one Twig or YAML file | under 30 ms |
| cached completion or hover p95 | under 100 ms |
| bridge boot with fresh warm cache | under 500 ms on medium fixture |
| full P0 snapshot with fresh cache | under 1 second on medium fixture |
| cache clear and P0 snapshot | under 3 seconds on medium fixture |
| overlay update | under 50 ms |
| idle server memory | benchmark first, then set a regression budget |

Store machine-readable benchmark baselines in CI artifacts. Fail CI only on
large regressions after measurements stabilize.

## Security findings

The research found one concrete trap: `debug:container --env-vars` displays real
environment values. It must be explicitly forbidden and covered by a test.
Parameter debug output also contains values and must not cross the bridge.

Additional requirements:

* bridge stdout is one JSON document and nothing else;
* application output is captured and reported only as redacted stderr details;
* environment, parameter, translation message, and configuration scalar values
  are omitted from traffic logs;
* subprocess argument arrays are used without a shell;
* bridge copies are content-addressed and written atomically after trust;
* bridge execution has time, memory, and output limits;
* a stale or failed section does not erase the last valid snapshot;
* no project code runs before workspace trust is established.

## Concrete implementation sequence

### Research closure

- [ ] Prototype a public safe metadata exporter in the Symfony monorepo.
- [ ] Validate the exporter on every branch in the generated support matrix.
- [ ] Evaluate the two Twig grammars against Symfony fixtures and licenses.
- [ ] Prove a minimal Tree-sitter extension can be included in all standalone
  binaries.
- [ ] Ask Phpactor for a tagged tolerant-parser release or create a temporary
  pinned fork.
- [ ] Build the small, medium, and large benchmark fixtures.

### First vertical slice

- [ ] Implement standalone LSP framing and lifecycle.
- [ ] Implement trust, project discovery, bridge deployment, and version checks.
- [ ] Implement cache freshness, clear, warmup, snapshot, and stale fallback.
- [ ] Implement the immutable snapshot and live-overlay stores.
- [ ] Implement tolerant PHP parsing and high-confidence receiver inference.
- [ ] Implement the route provider end to end.
- [ ] Add route completion, hover, definition, references, diagnostics, and
  best-effort rename.
- [ ] Publish cross-platform prototype binaries and measure the benchmark
  fixtures.

Routes remain the best vertical slice because the public runtime model is
already complete except for declaration provenance, which exercises the exact
runtime and static reconciliation required by the rest of the server.

## Sources

* Symfony release metadata: `https://symfony.com/releases.json`
* Symfony source: `https://github.com/symfony/symfony`
* Laravel LSP: `https://github.com/laravel/lsp`
* JSON-RPC peer: `https://github.com/fabpot/json-rpc-peer`
* Phpactor tolerant parser: `https://github.com/phpactor/tolerant-php-parser`
* Microsoft tolerant parser: `https://github.com/microsoft/tolerant-php-parser`
* PHP Parser: `https://github.com/nikic/PHP-Parser`
* Tree-sitter PHP: `https://github.com/tree-sitter/tree-sitter-php`
* Tree-sitter YAML: `https://github.com/tree-sitter-grammars/tree-sitter-yaml`
* Tree-sitter language pack: `https://github.com/xberg-io/tree-sitter-language-pack`
* Twig grammar: `https://github.com/gbprod/tree-sitter-twig`
* Alternative Twig grammar: `https://github.com/kaermorchen/tree-sitter-twig`
* YAML CST: `https://github.com/mougrim/php-yaml-cst`
* Static PHP CLI: `https://github.com/crazywhalecc/static-php-cli`
* LSP 3.17 specification:
  `https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/`
