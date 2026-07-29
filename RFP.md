# Symfony Language Server

Status: Draft

## Summary

This document proposes a Symfony-specific Language Server Protocol (LSP) server.
It would add framework-aware editor features to Symfony applications while
running alongside, rather than replacing, a general-purpose PHP language
server.

The server would understand Symfony's compiled application model and connect
string-based references across PHP, Twig, YAML, XML, and dotenv files. Its first
features would cover dependency injection, routes, templates, translations,
configuration, parameters, and environment variables. Later versions could
cover Messenger, Security, events, forms, validation, AssetMapper, and Symfony
UX integrations.

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
2. Work alongside PHP, Twig, YAML, and XML language servers without duplicating
   their generic features.
3. Use the actual application model when possible, including bundle-provided
   configuration and compiler-pass results.
4. Remain useful while a file contains incomplete or invalid code.
5. Support PHP attributes and YAML, XML, and PHP configuration where Symfony
   supports those formats.
6. Keep interactive operations fast by indexing asynchronously and retaining
   the last valid application snapshot.
7. Support common local, containerized, and remote PHP execution setups through
   an explicit project PHP command.
8. Require no editor-specific protocol extensions for the core experience.
9. Provide a path for Symfony bundles to expose additional framework-aware
   metadata without coupling themselves to a particular editor.

## Non-goals

The server should not:

* replace PHP language servers such as Intelephense or PHPactor;
* provide generic PHP completion, type inference, formatting, refactoring, or
  syntax diagnostics;
* replace a general Twig, YAML, XML, or dotenv language server;
* report style violations already covered by PHP-CS-Fixer, PHPStan, Psalm,
  Twig CS Fixer, or similar tools;
* execute application code for every keystroke;
* interpret arbitrary dynamic PHP expressions as if their values were known;
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

Completion can be helpful with partial information, but diagnostics must have a
higher confidence threshold. Dynamic expressions should normally produce no
undefined-value diagnostic.

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
in a separate process, never leak output into the LSP stream, and be disabled in
untrusted workspaces. A static-only mode should remain available.

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

* service references in YAML and XML service configuration;
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

The server should index routes declared with PHP attributes and YAML, XML, or
PHP routing configuration, including imported route resources.

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

Missing-key diagnostics must honor fallback locales and should be configurable
because some applications intentionally rely on external translation catalogs.
The server should never send a translated message to telemetry or logs.

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
* equivalent behavior for YAML, XML, and PHP configuration where practical.

Generic YAML or XML syntax remains the responsibility of another language
server. For PHP config builders, a PHP language server already provides methods
and types; the Symfony server should focus on semantic string values and links
that the generated builder API cannot express.

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
* constraint and constraint-option completion in YAML or XML mappings;
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

Doctrine, API Platform, Webpack Encore, and other packages should integrate
through an extension mechanism rather than become hard-coded assumptions of the
core server. Useful examples include Doctrine entity field completion in
Symfony options, repository and mapping navigation, and API resource operation
names.

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
* `textDocument/publishDiagnostics` for broad client compatibility;
* `workspace/didChangeWatchedFiles` and workspace-folder notifications;
* `workspace/executeCommand` for reindexing and displaying index status;
* `$/cancelRequest` for cooperative cancellation.

Code actions, rename, inlay hints, workspace symbols, and pull diagnostics can be
added when concrete Symfony use cases justify them. Rename is particularly
sensitive because route names, translation keys, and service IDs may be
referenced dynamically or outside the workspace.

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

* the server can run on PHP 8.4 as required by `fabpot/json-rpc-peer` while the
  project bridge uses a different PHP binary compatible with the application;
* a kernel crash cannot terminate the language server;
* Docker, DDEV, Symfony CLI, and other environments can be supported by
  configuring the bridge command;
* project dependencies do not enter the language server's Composer process;
* runtime metadata can be cached and refreshed independently from document
  parsing.

The bridge must be compatible with the oldest supported project PHP version.
Its output should contain data only. Logs and application output must be
captured separately and must never reach the server's stdout.

### JSON-RPC and transport

`fabpot/json-rpc-peer` should provide request dispatch, concurrent request
handling, outbound notifications and requests, cancellation, error handling,
and connection lifecycle.

LSP stdio uses `Content-Length` headers, not line-delimited JSON. The project
must therefore implement an LSP framing transport conforming to
`JsonRpcTransportInterface`; it should not use the library's line-delimited
stream transport directly. The dispatcher should register
`$/cancelRequest` with the `id` parameter.

Stdout is reserved exclusively for framed LSP messages. Logs go to stderr or a
configured file. Traffic logging must redact credentials and be disabled by
default.

### Document parsers

The server needs position-aware, error-tolerant parsers for:

* PHP syntax and attributes;
* Twig templates;
* YAML with Symfony's custom tags;
* XML service, route, validation, and other mappings;
* dotenv files.

Parsers should produce a small common model of declarations, static references,
call contexts, and source ranges. They do not need to reproduce full language
semantics.

Open documents are authoritative over files on disk. Parsing must tolerate
incomplete strings, argument lists, YAML mappings, XML tags, and Twig
expressions so completion remains available while the user types.

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

The bridge should prefer supported Symfony APIs and structured command output.
Version-specific adapters may be needed for metadata that Symfony does not yet
expose through a stable API.

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
environment values, credentials, or arbitrary object state.

### Invalidation

Index refreshes should be asynchronous, cancellable, and debounced. Relevant
inputs include:

* `composer.json`, `composer.lock`, and installed package metadata;
* kernel and bundle registration;
* service, route, package, translation, and mapping configuration;
* PHP attributes that contribute runtime metadata;
* templates and assets;
* resources tracked by Symfony's container and configuration cache.

A source-only edit should update navigation and completion immediately when it
can be understood statically. Runtime validation can follow in the background.
If rebuilding fails, the previous snapshot remains active and the failure is
reported once through an index-status notification or a project-level
diagnostic.

### Feature providers

Each feature area should be implemented behind internal provider interfaces for
completion, hover, definition, references, diagnostics, links, and indexing.
Providers should depend on narrow index interfaces rather than the kernel or LSP
transport.

This keeps the protocol layer independent from Symfony feature logic and makes
providers independently testable. It also creates a future extension point for
bundles.

A bundle extension mechanism should exchange declarative, versioned metadata
with the bridge. Third-party packages should not register arbitrary LSP request
handlers in the server process. The public extension contract should be defined
only after the core route, container, and configuration providers establish the
necessary abstractions.

### Multi-root and application discovery

The server should support LSP workspace folders and more than one Symfony
application in a repository. Candidate application roots can be detected from
`composer.json`, `bin/console`, and Symfony runtime files, but users must be able
to configure roots explicitly.

Each application root has its own environment, bridge command, runtime
snapshot, and cache. A document belongs to the most specific containing root.
The server should support Flex applications, custom directory layouts, and
MicroKernelTrait applications without assuming `config/` or `src/` always has
its default meaning.

## Configuration

Clients should pass optional settings through `initializationOptions` and
`workspace/configuration`. An initial configuration model could include:

| Option | Default | Purpose |
| --- | --- | --- |
| `phpCommand` | `['php']` | Command prefix used to run the project bridge. |
| `consolePath` | auto-detected | Project console path. |
| `environment` | `dev` | Symfony environment to index. |
| `debug` | `true` | Kernel debug mode used for indexing. |
| `runtimeIndexing` | `true` in trusted workspaces | Enable application boot and runtime metadata. |
| `projectRoots` | auto-detected | Explicit application roots for a workspace. |
| `features` | all supported | Enable or disable feature groups. |
| `translationDiagnostics` | `true` | Diagnose missing static translation keys. |
| `trace` | `off` | Write redacted protocol and indexing diagnostics. |

`phpCommand` is an argument array, not a shell string. Examples include
`['symfony', 'php']`, `['ddev', 'exec', 'php']`, or a project-specific wrapper.
The server should not guess and execute an arbitrary container command merely
because a configuration file exists.

The selected environment matters because routes, services, translations, and
configuration can differ. Indexing several environments concurrently can be
considered later, but the first version should have one explicit environment
per application root.

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
8. Clear diagnostics promptly when a document closes or a newer generation is
   published.

Feature providers should record why their value set is considered complete.
An absent route in the effective router can be a definite error. An absent
environment variable declaration is only a hint. An unknown custom voter
attribute is not a diagnostic.

## Security and privacy

The implementation should follow these constraints:

* no network access is required for indexing;
* no telemetry is sent by default;
* no secret, environment value, resolved parameter value, or credential is
  included in an index or hover;
* the bridge runs only for a trusted workspace or with explicit opt-in;
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

The proposed binary name is `symfony-lsp`. It communicates over stdio and
should be installable as a Composer global tool and, when dependency constraints
allow it, as a project development dependency. A PHAR or standalone binary can
be considered for easier editor integration.

A basic client configuration needs:

* the `symfony-lsp` command;
* PHP, Twig, YAML, XML, and dotenv file associations as desired;
* root markers such as `composer.json`, `bin/console`, and `.git`;
* optional initialization settings for the environment and project PHP
  command.

Thin official editor extensions may later provide automatic installation,
workspace trust integration, status UI, and sensible file associations. Core
completion, hover, navigation, links, references, and diagnostics must remain
standard LSP behavior.

## Delivery plan

### Phase 1: Protocol and indexing foundation

* Implement LSP framing on `fabpot/json-rpc-peer`.
* Implement lifecycle, cancellation, document synchronization, logging, and
  workspace folders.
* Add tolerant PHP, Twig, YAML, XML, and dotenv context extraction.
* Define the source index, runtime snapshot schema, bridge protocol, cache, and
  invalidation model.
* Add project discovery, explicit PHP commands, static-only mode, and status
  reporting.
* Establish fixture applications and protocol-level test infrastructure.

### Phase 2: First useful Symfony features

* Dependency injection services and parameters.
* Routes and route parameters.
* Twig template names and links.
* Translation keys, domains, and placeholders.
* Environment references and processors.
* Bundle configuration trees.

This phase should produce a usable initial release. It is better to support
fewer contexts exactly than to match common method names heuristically and
produce false positives.

### Phase 3: Cross-file framework graphs

* Twig variables and components.
* Messenger messages, handlers, buses, and transports.
* Events and listeners.
* Security firewalls, providers, and roles.
* Forms, validation, and serializer metadata.
* Code lenses and references backed by these graphs.

### Phase 4: Ecosystem and distribution

* AssetMapper and Symfony UX providers.
* A declarative bundle extension API.
* Selected Doctrine and ecosystem integrations.
* Official editor integrations where they improve setup.
* Reproducible PHAR or standalone distributions.

## Testing strategy

The test suite should include:

* unit tests for framing, URI handling, position encoding, context extraction,
  indexes, and every feature provider;
* protocol transcript tests for initialization, cancellation, document edits,
  diagnostics, shutdown, and malformed messages;
* fixture applications for supported Symfony versions and configuration
  formats;
* fixtures with Flex and custom layouts, multiple kernels, multiple workspace
  roots, and containerized bridge commands;
* integration tests against real compiled containers and caches;
* regression tests for incomplete PHP, Twig, YAML, and XML documents;
* tests proving secret and parameter values never enter snapshots, hovers, or
  logs;
* tests for stale snapshots and failed kernel rebuilds;
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
5. Typing a translation key offers keys for the selected domain; placeholders
   are completed from the message; fallback locales prevent false missing-key
   diagnostics.
6. Editing bundle configuration offers nodes from the installed bundle's Config
   tree and reports a definitely invalid option or value.
7. Typing `%env(...)%` offers processors and declared names without displaying
   any value.
8. Breaking the container configuration leaves the last valid runtime-backed
   results available while showing that the index is stale.
9. Running a general PHP language server at the same time does not produce
   duplicate generic PHP completion or diagnostics from Symfony LSP.

## Open questions

The following decisions should be made before implementation is considered
stable:

1. Which Symfony and project PHP versions should the first release support?
2. Should the package live under the Symfony organization and use the
   `symfony/lsp` Composer name?
3. Should runtime indexing require a project development dependency, or must a
   global installation support every core feature by itself?
4. Which stable Symfony APIs need to be added so the bridge can avoid depending
   on debug command output or internal container structures?
5. Which tolerant parsers provide accurate ranges and acceptable licensing for
   PHP, Twig, YAML, and XML?
6. Should missing translation keys be enabled by default for applications that
   use external translation providers?
7. How much Twig variable inference can be offered without duplicating a Twig
   or PHP language server?
8. What declarative metadata contract is sufficient for third-party bundle
   providers?
9. Is a PHAR sufficient for distribution, or should official standalone
   binaries be produced for major platforms?
10. Which editor should provide the reference client configuration used by the
    integration test suite?

## Recommendation

Proceed with a small protocol and indexing prototype centered on routes,
services, and templates. These areas exercise the essential architecture:
LSP framing, tolerant source parsing, runtime application introspection,
source-to-runtime reconciliation, asynchronous invalidation, and conservative
diagnostics.

Once those three providers are accurate and responsive in a real Symfony
application, add translations, environment variables, and bundle configuration
trees for the first public release. The remaining component and ecosystem
features can then build on the same indexes and provider contracts without
turning the project into another general-purpose PHP language server.
