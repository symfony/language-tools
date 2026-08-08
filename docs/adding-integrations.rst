Adding a Framework Integration
==============================

A framework integration should add Symfony semantics without reproducing a
general language server. Start with a small set of statically recognizable
contexts, prove their accuracy and expand only when the indexes can answer new
questions confidently.

Choose the Knowledge Sources
----------------------------

Decide what the feature needs before creating classes:

* use a source index for application declarations, exact ranges, references and
  immediate unsaved-document behavior;
* use a runtime index for the effective model after configuration imports,
  compiler passes, bundle extensions and environment selection;
* use both when completion or diagnostics need effective names while navigation
  and edits need application-owned locations.

Don't reconstruct runtime behavior from generated cache files. Prefer structured
Symfony commands or public APIs. Don't parse human-readable console output whose
format isn't a supported machine contract.

Domain Models and Indexes
-------------------------

Keep normalized domain models independent of LSP arrays and parser nodes. A
usual feature contains:

* immutable value objects for effective runtime metadata;
* an index with replace and focused lookup methods;
* a project-keyed index registry;
* separate source-fact and source-symbol value objects when locations matter;
* a source index that merges saved facts with open-document overlays.

Index methods should expose the narrow queries providers need. Avoid passing the
whole project model into providers or deriving project identity from a local Git
branch.

Source Extraction
-----------------

A source-backed feature normally provides an extractor and a
``SourceIndexProviderInterface`` implementation. The extractor accepts URI,
language ID and text, then returns serializable facts with exact ranges.

The indexer must support the complete scanner lifecycle:

* collect fresh and restored facts between ``begin()`` and ``finish()``;
* validate the cached payload type in ``restore()``;
* replace or remove one saved source after file changes;
* overlay unsaved documents without mutating saved facts;
* restore saved facts when an open document closes.

Use ``PositionConverter`` for every byte offset and range. Test non-ASCII text,
incomplete syntax and escaped strings when the context can contain them.

Persistent Payloads
-------------------

Every source provider has a unique stable name. Add every serialized fact,
symbol, enum, range and position class to ``SourceIndexPayloadCodec``. Runtime
models don't belong in source payloads.

Cache payloads must never contain parameter values, environment values,
credentials or generated application objects. A payload schema change must
continue to fail safely and trigger a rebuild.

Runtime Bridge Sections
-----------------------

A runtime-backed feature adds a section under ``resources/bridge/sections/``.
Use a globally unique ``bridge<Feature>Section()`` function and register the
section in ``resources/bridge.php``.

A section should:

1. Detect whether its optional Symfony component is installed.
2. Reuse the one kernel held by ``SymfonyLspBridgeContext``.
3. Prefer structured commands and public runtime APIs.
4. Normalize branch differences inside the bridge.
5. Sort maps and lists for deterministic generation hashes.
6. Report optional gaps as warnings.
7. Add section errors without discarding unrelated sections.
8. Return arrays, scalars and ``null`` values only.

Include completeness flags when diagnostics depend on a closed set. Never expose
raw command output or resolved values.

Add a ``RuntimeSnapshotLoaderInterface`` implementation that validates the
section arrays, builds domain models and atomically replaces the project index.
Autoconfiguration registers it in ``RuntimeSnapshotLoaderRegistry``.

Providers and Context Resolution
--------------------------------

Implement only the provider interfaces justified by concrete use cases. A
provider should:

1. Resolve the document, project and position.
2. Ask an extractor or parser for a precise semantic context.
3. Query a narrow source or runtime index.
4. Convert domain ranges into LSP arrays at the boundary.
5. Return ``null`` when the context isn't recognized.
6. Return an empty list when the context is recognized but has no result.

Autoconfiguration registers the provider in every registry matching its
implemented interfaces. ``LanguageServerFactory`` remains the composition root;
don't instantiate dependencies from inside providers.

Diagnostics Policy
------------------

Diagnostics need stronger evidence than completion. Apply these rules:

* diagnose only static values in recognized Symfony contexts;
* wait for a complete effective index before declaring a closed-set value
  unknown;
* don't diagnose runtime-extensible names unless the selected metadata proves
  they are invalid;
* ignore dynamic expressions rather than guessing their value;
* preserve a previous valid runtime snapshot after refresh failures;
* add regression tests for every false positive fixed during dogfooding.

Custom tags, serializer groups, voter attributes, environment declarations and
other open sets require feature-specific caution.

Composition Root Checklist
--------------------------

A complete integration can touch these locations:

* parsers and extractors shared by the runtime and provider layers;
* ``resources/services.php`` to place the feature namespace in provider order
  and when a service doesn't use one of the recognized class suffixes;
* ``SourceIndexPayloadCodec`` for persistent source facts;
* bridge section registration in ``resources/bridge.php``.

After its namespace is added to the ordered feature groups, classes ending in
``Provider``, ``Handler``, ``Extractor``, ``Indexer``, ``Registry``,
``Resolver``, ``Loader``, ``Publisher`` or ``Parser`` are loaded as feature
services.
Implemented provider interfaces, ``SourceIndexProviderInterface`` and
``RuntimeSnapshotLoaderInterface``, determine their registry tags.

Search for a similar completed feature and follow its data flow, but keep the
new domain model independent.

Required Tests
--------------

Add the smallest deterministic test at every affected boundary:

* extractor tests for every supported context and incomplete syntax;
* index tests for replacement, overlays and lookup semantics;
* provider tests for positive, empty and unrelated contexts;
* diagnostics tests for complete, incomplete and extensible indexes;
* snapshot loader tests for malformed and valid arrays;
* bridge tests that prove normalization and value redaction;
* source codec or scanner coverage for cache restoration;
* protocol or VS Code tests when lifecycle behavior changes;
* compatibility coverage when the bridge uses version-sensitive APIs.

Run the relevant real applications from
:doc:`Dogfooding Symfony LSP </dogfooding>`. Add a conservative dogfood probe
when several applications contain the new context.

Definition of Done
------------------

Before committing an integration:

* run focused tests, the full PHP suite, PHPStan and PHP CS Fixer;
* run the benchmark when indexing or request latency can change;
* run the eight-application dogfood matrix for cross-file or runtime changes;
* update the feature documentation and supported-integration table;
* add one short imperative entry without trailing punctuation to the
  ``Unreleased`` CHANGELOG section;
* update the RFP checklist when the planned capability is complete;
* verify that no secret or resolved application value reaches output;
* review all registered contexts for false-positive diagnostics.

See :doc:`Testing and Validation </testing>` for the complete command matrix.
