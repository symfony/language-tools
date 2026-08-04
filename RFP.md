# Symfony Language Server

Status: Draft

## Summary

This document proposes a Symfony-specific Language Server Protocol (LSP) server.
It would add framework-aware editor features to Symfony applications while
running alongside, rather than replacing, a general-purpose PHP language
server.

The server would understand Symfony's compiled application model and connect
string-based references across PHP, Twig, YAML, and dotenv files. Its first
features would cover dependency injection, routes, templates, translations,
configuration, parameters, and environment variables. Later versions could
cover Messenger, Security, events, forms, validation, AssetMapper, and Symfony
UX integrations.

The initial server would target FrameworkBundle applications running a Symfony
branch listed in `supported_versions` by Symfony's release metadata. It would be
developed in the official
[`symfony/lsp`](https://github.com/symfony/lsp) repository, distributed primarily
as the standalone `symfony-lsp` binary, and available secondarily as the
`symfony/lsp` Composer package.

The server would use
[`fabpot/json-rpc-peer`](https://github.com/fabpot/json-rpc-peer) for its
asynchronous, bidirectional JSON-RPC implementation. Laravel's
[`laravel/lsp`](https://github.com/laravel/lsp) is an important source of
inspiration, but the implementation and feature model should follow Symfony's
components, compiled container, and bundle ecosystem.

## Motivation

A standard PHP language server understands PHP syntax, symbols, and types. It
does not generally know that:

* `admin_dashboard` is a route name;
* `billing.invoice_created` is a translation key in the `messages` domain;
* `@App/payment/receipt.html.twig` is a Twig template;
* `app.invoice_generator` is a container service;
* `%env(bool:FEATURE_ENABLED)%` references an environment variable through a
  Symfony processor;
* `async_priority_high` is a Messenger transport;
* a YAML key is defined by the configuration tree of an installed bundle.

Symfony already computes much of this information while building the container
and warming the cache. An LSP server can expose that model in editors and relate
it back to source locations.

Today, developers must remember opaque names, search manually across file
formats, or rely on editor-specific plugins. A standalone LSP server would make
the same Symfony-aware behavior available to any compatible editor.

## Goals

The project should:

1. Provide Symfony-specific completion, hover, navigation, references, and
   diagnostics.
2. Work alongside PHP, Twig, and YAML language servers without duplicating
   their generic features.
3. Use the actual application model when possible, including bundle-provided
   configuration and compiler-pass results already materialized in Symfony's
   application cache.
4. Remain useful while a file contains incomplete or invalid code.
5. Support PHP attributes and YAML and PHP configuration where Symfony supports
   those formats.
6. Keep interactive operations fast by indexing asynchronously and retaining
   the last valid application snapshot.
7. Support common local, containerized, and remote PHP execution setups through
   an explicit project PHP command.
8. Require no editor-specific protocol extensions for the core experience.
9. Provide framework-specific code actions and best-effort rename operations.

## Non-goals

The server should not:

* replace PHP language servers such as Intelephense or PHPactor;
* provide generic PHP completion, type inference, formatting, refactoring, or
  syntax diagnostics;
* replace a general Twig, YAML, or dotenv language server;
* report style violations already covered by PHP-CS-Fixer, PHPStan, Psalm,
  Twig CS Fixer, or similar tools;
* execute application code for every keystroke;
* interpret arbitrary dynamic PHP expressions as if their values were known;
* support XML configuration;
* support projects that use Symfony components without FrameworkBundle;
* require a dedicated IDE extension to provide standard LSP capabilities;
* expose container parameters, environment values, or secrets to the editor.

The Symfony server may still parse PHP and infer small local expressions when
needed to recognize a Symfony API call. That parsing is an implementation
detail, not an attempt to become a full PHP analyzer.

## Design principles

### Complement existing language servers

Every result must answer a Symfony-specific question. For example, a PHP
language server should resolve `AbstractController::render()`, while the Symfony
server should complete its template argument and navigate from that argument to
the template.

The server should avoid generic keyword, class, method, property, and variable
completion. It should also avoid generic syntax diagnostics. Multiple language
servers can then safely attach to PHP and Twig documents with little duplicate
output.

### Prefer exact results over speculative results

Symfony applications can create services, routes, translation keys, and other
values dynamically. The server should diagnose an unknown value only when it
has a complete value set for the selected application environment and the
reference is a statically known literal.

The initial implementation should recognize only high-confidence PHP contexts:
explicit attributes, resolved receiver types, known controller inheritance or
traits, typed parameters and properties, and locally resolvable assignments. It
must not infer Symfony semantics from a generic method name. Completion can be
helpful with partial application metadata, but it must still be certain that the
cursor is in a Symfony context. Diagnostics require both a high-confidence
context and a complete value set. Dynamic expressions should normally produce
no undefined-value diagnostic.

### Align with Symfony's maintenance policy

The LSP should support exactly the branches listed in `supported_versions` by
Symfony's release metadata. Security-only and unreleased development branches
are excluded from the compatibility promise. Support for a branch ends when it
leaves that list. The LSP should not establish a separate lifecycle policy.

Official structured debug commands are the preferred runtime integration
boundary. The bridge may use public runtime APIs where a supported branch lacks
suitable structured output. New structured output should be added to future
Symfony versions for long-term convergence, but the LSP cannot depend on it
until all supported branches provide it. The bridge must not parse generated PHP
files or depend directly on internal container and cache formats.

### Preserve the last valid model

Developers routinely make configuration invalid while editing it. A failed
kernel boot or container build must not erase all editor intelligence. The
server should retain the last valid runtime snapshot, overlay safe static
changes from open documents, and clearly mark the project index as stale.

### Keep source locations

Runtime metadata alone is not enough. Every indexed item should retain its
source declaration when one exists. Navigation should lead to a route
attribute, service definition, translation message, template, or configuration
entry rather than only to generated cache files.

### Treat application execution as a trust boundary

Booting a Symfony application executes project code. Runtime indexing must run
in a separate process and never leak output into the LSP stream. The server
should honor a workspace trust decision supplied in initialization options.
LSP does not define a standard workspace trust field, so a client that does not
supply one must be asked through `window/showMessageRequest` before the
application is booted. The decision should be remembered per application root,
and static-only mode should remain active until trust is granted.

## Proposed capabilities

The priorities used below are:

* **P0**: required for an initial useful release;
* **P1**: expected after the core is stable;
* **P2**: useful expansion or ecosystem integration.

### Dependency injection and parameters

Priority: P0

The server should understand the compiled service graph and the configuration
that produced it.

Supported contexts should include:

* service references in YAML service configuration;
* service IDs in PHP configuration and known Symfony attributes such as
  `Autowire`;
* parameter references such as `%kernel.project_dir%`;
* tagged iterators, tagged locators, decorators, aliases, bindings, factories,
  and configurators;
* class and resource references in service configuration;
* named autowiring aliases where they can be resolved exactly.

Capabilities should include:

* completion for service IDs, parameters, tags, classes, and applicable
  autowiring aliases;
* hover details with the resolved class or type, alias target, visibility,
  laziness, deprecation, tags, and declaration source;
* definition links to service and parameter declarations, aliases, and service
  classes;
* references across configuration and recognized PHP call sites;
* diagnostics for definitely unknown services or parameters, invalid
  decoration targets, and incompatible statically known references;
* navigation from a service class to its explicit definitions and decorators.

Parameter and service values must not be displayed. A hover may expose a safe
scalar default written directly in a source file, but it must not expose a
resolved runtime value.

Unknown tags should not normally be diagnosed because tags can intentionally
exist without a consumer.

### Routing

Priority: P0

The server should index routes declared with PHP attributes and YAML or PHP
routing configuration, including imported route resources.

Supported contexts should include:

* `generateUrl()`, `redirectToRoute()`, and other unambiguous Symfony routing
  APIs;
* Twig's `path()` and `url()` functions;
* route names, controllers, imports, aliases, and route parameter maps in
  configuration;
* route references in other Symfony component configuration.

Capabilities should include:

* route-name completion;
* route-parameter completion based on path placeholders and defaults;
* hover details with path, host, methods, schemes, controller, defaults, and
  requirements;
* definition links to the route declaration and controller;
* references for statically known route names;
* diagnostics for unknown route names, missing required parameters, unexpected
  parameters, duplicate declarations that cannot coexist, and invalid
  controller references;
* document links in route configuration and PHP or Twig string literals.

Diagnostics must account for optional parameters, defaults, imported route
prefixes, environment-specific routes, and route aliases.

### Twig templates and components

Priority: P0 for template names, P1 for variables and components

The server should add Symfony's template-loading semantics without duplicating
generic Twig syntax support.

Supported contexts should include:

* template arguments passed to `render()`, `renderView()`, and recognized Twig
  environment APIs;
* Twig `extends`, `include`, `embed`, `import`, `from`, and `use` statements
  when their target is static;
* namespaced template paths such as `@SomeBundle/...`;
* Twig component names and templates when Symfony UX Twig Component is
  installed.

Capabilities should include:

* template and component completion;
* hover details with the resolved file, namespace, component class, blocks, and
  known variables;
* definition links between render calls, Twig references, templates, component
  classes, and component templates;
* diagnostics for missing templates, missing referenced blocks, and unknown
  components;
* references for static template and component names.

A first implementation of template-variable awareness should stay deliberately
small. It can index Twig globals and literal keys passed in a render context.
More advanced propagation through includes, inheritance, embedded controllers,
and component properties can follow once its accuracy is demonstrated.

### Translations

Priority: P0

The server should build translation catalogs using Symfony's locale, domain,
loader, and fallback rules.

Supported contexts should include:

* Translator `trans()` calls;
* `TranslatableMessage` and the `t()` helper;
* Twig translation filters, tags, and functions;
* translation keys, domains, locales, and parameter maps in supported resource
  formats.

Effective catalogue completion and diagnostics should work for every translation
loader through Symfony's runtime catalogue. Initial source-aware navigation,
references, rename, and edits should support YAML, XLIFF, PHP, and JSON. Generic
XML configuration remains unsupported; XLIFF is supported specifically as a
translation format.

Capabilities should include:

* key completion scoped to a domain;
* domain and locale completion;
* placeholder completion for `%name%` and ICU message parameters;
* hover details with source location, domain, available locales, and the
  message for the selected development locale;
* definition links to one or more catalog entries;
* references for static keys;
* diagnostics for definitely missing keys or domains and for missing or
  unexpected placeholders.

Missing-key diagnostics must be disabled by default and enabled explicitly per
application root. When enabled, they must honor fallback locales. Completion,
hover, navigation, and placeholder validation remain available regardless of
this option. The server should never send a translated message to telemetry or
logs.

### Bundle and application configuration

Priority: P0

The server should expose the Config component trees of installed bundles. This
is a major Symfony-specific capability because these trees are generated by the
actual installed package versions.

Capabilities should include:

* completion for extension aliases, child nodes, enum values, and recognized
  semantic values;
* hover documentation with node descriptions, accepted types, defaults,
  examples, deprecations, and normalization behavior when available;
* diagnostics for unknown keys, invalid types, invalid enum values, missing
  required children, and mutually exclusive options;
* navigation through imports and resource paths;
* support for `when@<environment>` sections and the selected application
  environment;
* equivalent behavior for YAML and PHP configuration where practical.

Generic YAML syntax remains the responsibility of another language server. For
PHP config builders, a PHP language server already provides methods and types;
the Symfony server should focus on semantic string values and links that the
generated builder API cannot express.

Configuration from third-party bundles should work without hard-coded knowledge
when it is represented by a Config component tree. Bundle-specific semantic
references can be added by feature providers.

### Environment variables and secrets

Priority: P0

The server should understand Symfony dotenv precedence and `%env(...)%`
expressions without resolving secret values.

Capabilities should include:

* completion for declared variable names and installed environment processors;
* hover details with declaration files, processor pipeline, and expected type;
* definition links to dotenv declarations;
* references across configuration and dotenv files;
* diagnostics for malformed processor chains, unknown processors, and type
  mismatches that can be proven statically;
* optional hints for variables with no known declaration.

A missing dotenv declaration cannot be an error because the variable may be
provided by the operating system, deployment platform, or Symfony secrets
vault. Hovers and logs must never include environment or secret values. The
server must never invoke a command that reveals secrets.

### Messenger

Priority: P1

When Messenger is installed, the server should index buses, transports,
routing, messages, handlers, and relevant attributes.

Capabilities should include:

* completion and navigation for bus and transport names;
* hover details for a message's handlers, buses, routing, and retry or failure
  transport;
* navigation and references between message classes and handlers;
* diagnostics for unknown named buses or transports, invalid handler methods,
  and configuration references that are definitely unresolved;
* code lenses on message and handler classes showing their relationships.

### Events

Priority: P1

The server should index event classes, legacy event names, subscribers, and
listeners after compiler passes have run.

Capabilities should include:

* completion for statically named events in dispatcher and listener contexts;
* hover details with the event class, listeners, priorities, and dispatcher;
* navigation between dispatch sites, event classes, and listeners;
* references for event names and classes;
* code lenses showing listeners for an event and events handled by a listener.

Generic class navigation remains the PHP language server's responsibility. The
Symfony server adds the event graph.

### Security

Priority: P1

The server should index firewalls, user providers, access decision attributes
that are known to be roles, role hierarchy, authenticators, voters, and access
control configuration.

Capabilities should include:

* completion and navigation for firewall and provider names;
* role completion in `IsGranted`, `denyAccessUnlessGranted`, Twig
  `is_granted()`, and security configuration;
* hover details for role hierarchy and known voters;
* diagnostics for unknown firewall or provider references and structurally
  invalid security configuration.

Custom voter attributes form an open set. The server must not diagnose an
unknown authorization attribute merely because it is absent from configuration.

### Forms, validation, and serializer metadata

Priority: P1

The server could use OptionsResolver and component metadata to improve
framework-specific arrays and mapping files.

Potential capabilities include:

* form option completion and hover for a statically known form type;
* diagnostics for definitely invalid form options;
* constraint and constraint-option completion in YAML or PHP mappings;
* navigation between mapped properties and classes;
* completion and references for known serializer groups.

Serializer groups and many form options are extensible at runtime. Diagnostics
must remain conservative.

### Console commands

Priority: P2

The server could index command names, aliases, arguments, options, and command
classes. This would support navigation from recognized programmatic command
lookups and code lenses on command classes. Shell completion for `bin/console`
is useful but outside LSP scope and should remain a separate concern.

### AssetMapper and Symfony UX

Priority: P2

Optional providers could add:

* logical asset-path completion and links for AssetMapper and Twig asset
  functions;
* importmap package completion and diagnostics;
* Stimulus controller and action completion across Twig and JavaScript;
* Twig Component and Live Component names, properties, actions, events, and
  templates;
* links between UX controllers, PHP components, templates, and assets.

### Ecosystem integrations

Priority: P2

Doctrine, API Platform, Webpack Encore, and other packages may receive focused
integrations when concrete use cases justify maintaining them in the core
server. Useful examples include Doctrine entity field completion in Symfony
options, repository and mapping navigation, and API resource operation names.
The first release should support only metadata represented through standard
Symfony mechanisms.

## LSP methods

The first protocol surface should use standard LSP methods:

* `initialize`, `initialized`, `shutdown`, and `exit`;
* `textDocument/didOpen`, `didChange`, `didSave`, and `didClose`;
* `textDocument/completion` and `completionItem/resolve`;
* `textDocument/hover`;
* `textDocument/definition`;
* `textDocument/references`;
* `textDocument/documentLink` and `documentLink/resolve`;
* `textDocument/codeLens` where relationship graphs justify it;
* `textDocument/codeAction` for deterministic framework-specific edits;
* `textDocument/prepareRename` and `textDocument/rename` for best-effort
  framework identifier rename;
* `textDocument/publishDiagnostics` for broad client compatibility;
* `workspace/didChangeWatchedFiles` and workspace-folder notifications;
* `workspace/executeCommand` for reindexing and displaying index status;
* `window/workDoneProgress/create` and `$/progress` for background indexing;
* `window/showMessageRequest` for trust, rename, and ambiguous edit choices;
* `workspace/applyEdit` for edits selected through a command;
* `$/cancelRequest` for cooperative cancellation.

Code actions should return previewable `WorkspaceEdit` values and remain
limited to Symfony-specific operations, such as creating a missing template or
translation entry and editing recognized configuration. A direct edit is
offered only when there is one clear application-owned target. Ambiguous actions
should use `workspace/executeCommand`, ask the user to select a destination, and
then call `workspace/applyEdit`. A Symfony-maintained fork of the tolerant PHP
parser should provide safe PHP parsing and edits. Symfony LSP must not embed
another PHP language server or duplicate generic PHP refactoring.

Rename should update every statically resolved declaration and reference in the
workspace and clearly warn that dynamic references may remain. It is
best-effort rather than exhaustive. It must never edit generated cache files or
Composer-installed dependencies. Inlay hints, workspace symbols, and pull
diagnostics can be added when concrete Symfony use cases justify them.

## Architecture

### Process model

The proposed design separates the long-running language server from application
introspection:

```text
Editor
  |  LSP over stdio
  v
Symfony language server
  |-- tolerant document parsers
  |-- source index and open-document overlays
  |-- merged semantic index
  |
  +-- project bridge subprocess
        |-- project PHP runtime
        |-- Composer autoloader
        |-- Symfony kernel and console
        +-- normalized metadata snapshot
```

The language server owns the LSP connection, document state, source parsers,
indexes, and feature providers. The project bridge boots the application under
the project's PHP runtime and returns a versioned, normalized JSON snapshot.

This split provides several benefits:

* the standalone server can use its bundled PHP runtime while the project bridge
  uses a different PHP binary compatible with the application;
* a kernel crash cannot terminate the language server;
* Docker, DDEV, Symfony CLI, and other environments can be supported by
  configuring the bridge command;
* project dependencies do not enter the language server's Composer process;
* runtime metadata can be cached and refreshed independently from document
  parsing.

The bridge is bundled with Symfony LSP and must be compatible with all PHP
versions allowed by supported Symfony branches. Projects do not install a
separate integration package. After workspace trust is granted, the bridge is
written atomically under
`var/symfony-lsp/<version>/<bundle-hash>/bridge.php` so project PHP wrappers can
access it. Symfony LSP must not modify `.gitignore`. Bridge output
should contain data only. Logs and application output must be captured
separately and must never reach the server's stdout.

### JSON-RPC and transport

`fabpot/json-rpc-peer` should provide request dispatch, concurrent request
handling, outbound notifications and requests, cancellation, error handling,
and connection lifecycle.

LSP stdio uses `Content-Length` headers, not line-delimited JSON.
`fabpot/json-rpc-peer` should gain a generic content-length framed stream
transport with strict case-insensitive header parsing, configurable header and
message size limits, clean EOF behavior, and malformed-frame tests. The library
must remain protocol-neutral. Symfony LSP should use that transport and register
`$/cancelRequest` with the `id` parameter.

Stdout is reserved exclusively for framed LSP messages. Logs go to stderr or a
configured file. Traffic logging must redact credentials and be disabled by
default.

### Document parsers

The server needs position-aware, error-tolerant parsers for:

* PHP syntax and attributes;
* Twig templates;
* YAML with Symfony's custom tags;
* XLIFF as a translation format;
* PHP and JSON translation resources;
* dotenv files.

A native Tree-sitter extension bundled invisibly in every standalone binary is
acceptable for Twig and YAML. Grammar selection should prioritize technical
quality. Creating an official Twig grammar is an option if existing grammars are
not good enough.

Parsers should produce a small common model of declarations, static references,
call contexts, and source ranges. They do not need to reproduce full language
semantics.

Open documents are authoritative over files on disk. Parsing must tolerate
incomplete strings, argument lists, YAML mappings, and Twig expressions so
completion remains available while the user types.

Confidently parsed declarations and references from open documents should form
a live overlay on the cached application model. This overlay may drive
completion, navigation, references, and edits immediately. Unsaved changes must
not trigger a cache warmup, and diagnostics that require the effective runtime
model must wait until the files are saved and the cache is refreshed.

All source positions must follow the encoding negotiated with the LSP client.
UTF-16 compatibility is required for clients that use the LSP default.

### Static and runtime indexes

The server should merge two forms of knowledge:

1. A static source index provides precise locations and immediate updates for
   declarations and references.
2. A runtime snapshot provides the effective application model after imports,
   environment selection, bundle extensions, compiler passes, and cache
   warmers.

Neither source is sufficient alone. Static parsing cannot reproduce arbitrary
compiler passes, while compiled metadata often loses source ranges and removed
or overridden declarations.

Every symbol should carry its origin, selected environment, confidence, and
snapshot generation. Feature providers can then decide whether completion or a
diagnostic is safe.

### Runtime introspection

A warmed FrameworkBundle application already materializes substantial metadata
under its normal `var/cache/<environment>/` directory, including container,
routing, translation, Twig, validation, serializer, configuration, and resource
freshness information where the corresponding features are installed. This
should be the primary source of effective application knowledge.

One bridge process should boot one kernel per snapshot. It should execute
available structured debug commands in-process with isolated stdout and stderr
capture, normalize differences between supported branches, and use public
runtime APIs for sections without suitable structured output. Failure in one
optional section must not discard successful sections. Existing cache artifacts
should be reused through Symfony behavior; the bridge must not parse the dumped
container, compiled route PHP, or any other generated implementation format.

`debug:container --format=json --show-hidden`, `debug:container --types
--format=json`, `debug:router --format=json`, `debug:config <alias>
--format=json`, `debug:twig --format=json`, `debug:event-dispatcher
--format=json`, and `debug:form --format=json` are initial structured sources.
Where supported branches lack structured output, such as Messenger or Security,
the bridge should use public runtime collectors. Structured command output added
to newer Symfony versions is adopted only after it is available across all
supported branches.

If the cache is absent or stale, the bridge may refresh the application's normal
cache through public Symfony behavior. It must not invent an `lsp` environment
or override `kernel.cache_dir`.

The initial snapshot should include, where installed:

* Symfony and bundle versions;
* selected environment and debug mode;
* service definitions, aliases, parameters, tags, and autowiring aliases;
* effective routes and controller metadata;
* Twig loader paths, namespaces, globals, extensions, and components;
* translation catalog metadata and source resources;
* bundle configuration trees;
* environment processor names and expected types;
* Messenger buses, transports, routing, and handlers;
* events and listeners;
* Security firewalls, providers, and role hierarchy;
* form types, validator metadata, serializer metadata, and assets when their
  providers are enabled.

The bridge should not serialize resolved service instances, parameter values,
environment values, credentials, or arbitrary object state. It may execute
`debug:container --parameters --format=json` internally to discover the complete
effective parameter-name set, but must discard values immediately and never log
or persist raw output. Effective `debug:config` output may be normalized into
enabled provider sections; it remains workspace-local and must not enter
telemetry or raw protocol logs.

### Invalidation

Index refreshes should be asynchronous and debounced. Relevant inputs include:

* `composer.json`, `composer.lock`, and installed package metadata;
* kernel and bundle registration;
* service, route, package, translation, and mapping configuration;
* PHP attributes that contribute runtime metadata;
* templates and assets;
* resources tracked by Symfony's container and configuration cache.

Relevant saves should be debounced briefly and collapsed into one cache refresh
per application root. Refreshes must be serialized. An active refresh should be
allowed to finish; changes arriving during it should queue one replacement.
Run `cache:clear --no-interaction` when saved resources can make the compiled
container stale. Use `cache:warmup --no-interaction` only when the built
container is current but optional warmed metadata is missing. A manual refresh
command should bypass the debounce. A source-only edit should update the live
overlay immediately when it can be understood statically. If rebuilding fails,
the previous snapshot remains active and the failure is reported once through
an index-status notification or a project-level diagnostic.

### Feature providers

Each feature area should be implemented behind internal provider interfaces for
completion, hover, definition, references, diagnostics, links, and indexing.
Providers should depend on narrow index interfaces rather than the kernel or LSP
transport.

This keeps the protocol layer independent from Symfony feature logic and makes
providers independently testable.

### Multi-root and application discovery

The server should support LSP workspace folders and more than one FrameworkBundle
application in a repository. Candidate application roots can be detected from
`composer.json`, `bin/console`, and Symfony runtime files, but users must be able
to configure roots explicitly.

Each application root has one selected environment, defaulting to `dev`, and its
own bridge command, runtime snapshot, and normal application cache. A
`workspace/executeCommand` action should switch the selected environment and
refresh its cache; the server must not merge several environments. A document
belongs to the most specific containing root. The server should support Flex
applications, custom directory layouts, and MicroKernelTrait applications
without assuming `config/` or `src/` always has its default meaning.

The static index should scan all application-owned PHP, Twig, YAML, XLIFF, JSON
translation, and dotenv files asynchronously after initialization. Open
documents take priority, and standard LSP progress notifications report the
background scan. Recursively scanning `vendor/` and `var/` is excluded. Symfony-
registered dependency resources and individual dependency files are parsed on
demand for navigation.

The index should persist under `var/symfony-lsp/<server-version>/index/`. Its
format is versioned, writes are atomic, entries are validated by file metadata
and content hashes when needed, and corruption causes a transparent rebuild.

Navigation, hover, completion, and references may include files under `vendor/`
or other registered Symfony resources. Rename and code actions may edit only
files owned by configured application roots. Generated files are always
read-only. Monorepo packages can become editable by adding them as application
roots.

## Configuration

Clients should pass optional settings through `initializationOptions` and
`workspace/configuration`. An initial configuration model could include:

| Option | Default | Purpose |
| --- | --- | --- |
| `phpCommand` | `['php']` | Command prefix used to run the project bridge. |
| `consolePath` | auto-detected | Project console path. |
| `environment` | `dev` | Symfony environment to index. |
| `debug` | `true` | Kernel debug mode used for indexing. |
| `runtimeIndexing` | `true` after trust | Enable application boot and runtime metadata. |
| `projectRoots` | auto-detected | Explicit application roots for a workspace. |
| `features` | all supported | Enable or disable feature groups. |
| `translationDiagnostics` | `false` | Diagnose missing static translation keys. |
| `trace` | `off` | Write redacted protocol and indexing diagnostics. |

`phpCommand` is an argument array, not a shell string. Examples include
`['symfony', 'php']`, `['ddev', 'exec', 'php']`, or a project-specific wrapper.
The server should not guess and execute an arbitrary container command merely
because a configuration file exists.

The selected environment matters because routes, services, translations, and
configuration can differ. The server indexes exactly one environment per
application root. An editor command should switch environments and refresh the
corresponding normal application cache.

## Diagnostics policy

Diagnostics are the capability most likely to become noisy. Providers should
follow these rules:

1. Diagnose only recognized Symfony contexts.
2. Diagnose only static values or expressions resolved with high confidence.
3. Account for the selected environment and configured fallbacks.
4. Distinguish an invalid current document from a stale runtime snapshot.
5. Avoid errors for open-world registries such as voter attributes, tags, OS
   environment variables, and serializer groups.
6. Include a concise explanation and the declaration source when available.
7. Never repeat generic parser or type errors from another language server.
8. Publish automatic diagnostics only for open documents.
9. Clear diagnostics promptly when a document closes or a newer generation is
   published.

The semantic index still covers the application for completion, navigation,
references, code actions, and rename. A workspace-wide validation command can
be considered later.

Feature providers should record why their value set is considered complete.
An absent route in the effective router can be a definite error. An absent
environment variable declaration is only a hint. An unknown custom voter
attribute is not a diagnostic.

## Security and privacy

The implementation should follow these constraints:

* no network access is required for indexing;
* no telemetry is sent by default;
* no environment value, resolved parameter value, secret-store value, or
  credential is included in an index or hover;
* the bridge honors workspace trust supplied in initialization options and
  otherwise asks for explicit consent through `window/showMessageRequest`;
* subprocess commands are argument arrays and never interpolated into a shell;
* stdout contains LSP frames only;
* protocol logs are opt-in and recursively redact credential-like fields;
* bridge timeouts, cancellation, output limits, and memory limits protect the
  long-running server;
* file access remains inside configured application roots except for Composer
  packages and paths explicitly registered as Symfony resources.

The editor starts the server in a developer checkout, so project code already
has the developer's privileges when runtime indexing is enabled. Process
isolation improves resilience, but it is not a security sandbox.

## Performance expectations

The server should respond to `initialize`, open documents, and static features
before runtime indexing finishes. Runtime-backed results can appear when the
first snapshot is ready.

Initial targets for a representative medium Symfony application are:

* cached completion and hover at the 95th percentile in under 100 ms;
* an initial static index in under 500 ms;
* an initial useful runtime snapshot in under 3 seconds on a warm development
  cache;
* no kernel rebuild caused by ordinary cursor movement;
* bounded idle memory with documented benchmarks;
* cancellation observed promptly for superseded completion, hover, references,
  and reindex requests.

These are engineering targets, not protocol guarantees. A benchmark fixture
suite should make them measurable before a stable release.

## Installation and editor integration

The project should live in the official `symfony/lsp` repository and have an
independent release cadence from the Symfony framework. The primary distribution
must be self-contained `symfony-lsp` binaries for macOS, Linux, and Windows from
the first public release. These binaries isolate the server runtime from the
project's PHP version. The `symfony/lsp` Composer package may provide a secondary
installation method, but no application dependency is required for runtime
indexing.

A basic client configuration needs:

* the `symfony-lsp` command;
* PHP, Twig, YAML, XLIFF, JSON translation, and dotenv file associations as
  desired;
* root markers such as `composer.json`, `bin/console`, and `.git`;
* optional initialization settings for the environment and project PHP
  command.

The first release should remain editor-neutral and document configuration for
major editors. Thin official editor extensions may later provide automatic
installation, workspace trust integration, status UI, and sensible file
associations. Core completion, hover, navigation, links, references, edits, and
diagnostics must remain standard LSP behavior.

## Delivery plan

### Phase 1: Protocol and indexing foundation

- [x] Add generic content-length stream framing and limits to
  `fabpot/json-rpc-peer` without adding LSP semantics.
- [x] Implement LSP lifecycle, cancellation, document synchronization, logging,
  and workspace folders on that transport.
- [x] Add bundled Tree-sitter parsing for tolerant Twig and YAML context
  extraction.
- [x] Add tolerant PHP context extraction with high-confidence Symfony call
  recognition.
- [x] Complete tolerant XLIFF, JSON translation, and dotenv context extraction.
- [x] Define the persistent source index, runtime snapshot schema, live-overlay
  model, bridge protocol, normal-cache reuse, and debounced invalidation.
- [x] Add FrameworkBundle project discovery, `supported_versions` checks,
  explicit PHP commands, workspace trust, static-only mode, and status
  reporting.
- [x] Establish fixture applications and protocol-level test infrastructure.
- [x] Establish reproducible standalone binary builds for macOS, Linux, and
  Windows.

### Phase 2: First useful Symfony features

Each area should have an independent implementation and acceptance checklist so
features can land step by step without reducing the intended initial scope.

- [x] Implement dependency injection services and parameters.
- [x] Implement routes and route parameters.
- [x] Implement Twig template names and links.
- [x] Implement translation keys, domains, and placeholders, with missing-key
  diagnostics opt-in.
- [x] Implement environment references and processors.
- [x] Implement bundle configuration trees.
- [x] Implement framework-specific code actions.
- [x] Implement best-effort rename for supported identifiers.

This phase should produce a usable initial release. It is better to support
fewer contexts exactly than to match common method names heuristically and
produce false positives.

### Phase 3: Cross-file framework graphs

- [x] Implement Twig variables and components.
- [x] Implement Messenger messages, handlers, buses, and transports.
- [x] Implement events and listeners.
- [x] Implement Security firewalls, providers, and roles.
- [x] Implement forms, validation, and serializer metadata.
- [x] Implement code lenses and references backed by these graphs.

### Phase 4: Ecosystem and editor integration

- [x] Implement AssetMapper and importmap providers.
- [x] Implement Stimulus and remaining Symfony UX providers.
- [x] Prototype selected Doctrine and ecosystem integrations.
- [ ] Add official editor integrations where they improve setup.

## Testing strategy

The test suite should include:

* unit tests for framing, URI handling, position encoding, context extraction,
  indexes, and every feature provider;
* protocol transcript tests for initialization, cancellation, document edits,
  diagnostics, shutdown, and malformed messages;
* fixture applications for every branch in `supported_versions` and every
  supported configuration format;
* fixtures with Flex and custom layouts, multiple kernels, multiple workspace
  roots, and containerized bridge commands;
* integration tests against real compiled containers and caches;
* regression tests for incomplete PHP, Twig, YAML, XLIFF, JSON translation, and
  dotenv documents;
* tests proving secret and parameter values never enter snapshots, hovers, or
  logs;
* tests for stale snapshots, debounced refreshes, and failed cache warmups;
* tests for workspace trust and static-only behavior;
* canary-secret tests proving parameter and environment values never cross the
  bridge, enter logs, or persist in the static index;
* tests proving automatic diagnostics are limited to open documents;
* tests proving edits never target `vendor/` or generated cache files;
* tests for best-effort rename and incomplete-coverage warnings;
* standalone binary smoke tests on every supported platform;
* Unicode position tests;
* performance benchmarks for cold start, warm start, completion latency,
  incremental invalidation, and memory use.

Every reported bug should receive a focused regression test. Runtime fixtures
should use small applications and deterministic metadata rather than mocks for
Symfony's compiled behavior.

## Initial acceptance scenarios

An initial release is useful when all of these workflows operate in a standard
LSP client:

1. Typing a literal route argument offers route names; hover shows the effective
   route; definition opens its attribute or configuration; an unknown static
   name receives a diagnostic.
2. Typing a route parameter array offers the route's placeholders and diagnoses
   a definitely missing required placeholder.
3. Typing a template argument offers resolved Twig names; definition opens the
   template; a missing static template receives a diagnostic.
4. Typing `@...` or `%...%` in service configuration offers services or
   parameters; hover describes safe metadata; definition opens the declaration.
5. Typing a translation key offers keys for the selected domain and completes
   placeholders from the message; no missing-key diagnostic is published until
   the project opts in.
6. Editing bundle configuration offers nodes from the installed bundle's Config
   tree and reports a definitely invalid option or value.
7. Typing `%env(...)%` offers processors and declared names without displaying
   any value.
8. Breaking the container configuration leaves the last valid runtime-backed
   results available while showing that the index is stale.
9. Running a general PHP language server at the same time does not produce
   duplicate generic PHP completion or diagnostics from Symfony LSP.
10. Unsaved declarations immediately affect navigation and completion without
    warming the application cache.
11. Saving several relevant files causes one debounced, serialized refresh of
    the normal application cache.
12. Renaming a supported framework identifier previews edits to all statically
    resolved application references and warns that dynamic references may
    remain.
13. Opening an untrusted workspace does not boot the application until the
    client provides trust or the user grants it.
14. Navigation can open a dependency source file, but code actions and rename
    never edit dependencies or generated files.

## Implementation research

The initial implementation research is documented in
[`RESEARCH.md`](RESEARCH.md). Its main conclusions are:

1. Boot one cached kernel per snapshot, prefer official structured debug
   commands, and use public runtime collectors where supported branches lack
   structured output.
2. Normalize differences across `supported_versions`; improvements available
   only in future Symfony versions cannot be initial dependencies.
3. Create a Symfony-maintained tolerant PHP parser fork and bundle technically
   strong Tree-sitter Twig and YAML grammars in standalone binaries.
4. Persist the application-owned static index and keep runtime snapshots and
   open-document overlays as separate immutable generations.
5. Use LSP change annotations and confirmation to communicate incomplete rename
   coverage.
6. Add generic content-length framing to `fabpot/json-rpc-peer` while keeping LSP
   semantics in `symfony/lsp`.
7. Build cross-platform binaries with `static-php-cli` and bundle every parser
   needed for offline operation.
8. Start permanent small, medium, and large benchmark fixtures before setting
   hard refresh and memory budgets.

## Recommendation

Proceed in `symfony/lsp` with a protocol and indexing foundation, then implement
the P0 providers one by one behind clear acceptance checklists. Routes, services,
and templates should validate the essential architecture first: LSP framing,
high-confidence tolerant source parsing, public runtime introspection, normal
cache reuse, live overlays, debounced refreshes, source-to-runtime
reconciliation, framework-specific edits, and conservative diagnostics.

Translations, environment variables, and bundle configuration trees remain part
of the intended initial feature set and should follow on the same foundation.
Standalone binaries for macOS, Linux, and Windows are a first-release
requirement. The remaining component and ecosystem features can then build on
the same indexes and internal provider contracts without turning the project
into another general-purpose PHP language server.
