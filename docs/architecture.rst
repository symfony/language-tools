Architecture and Data Flow
==========================

Symfony LSP combines application-owned source facts with effective metadata from
a running Symfony application. This page describes the implementation as it
exists today. The root ``RFP.md`` file remains the historical design document,
but it isn't the operational source of truth.

Process Boundaries
------------------

The system has two process boundaries:

.. code-block:: text

    Editor
      -> LSP over Content-Length framed stdio
        -> Symfony LSP
          -> source parsers and persistent indexes
          -> project bridge subprocess
            -> application Composer autoloader
            -> App\Kernel and structured Symfony metadata

Symfony LSP owns the LSP connection, open documents, source scanning, feature
providers and merged indexes. The project bridge boots application code under a
PHP command compatible with that application. Application dependencies never
enter the language server's Composer process.

Generic JSON-RPC framing, transport and request lifecycle behavior belongs in
``fabpot/json-rpc-peer``. Symfony and LSP semantics belong in this repository.

Initialization and Project Discovery
------------------------------------

``LanguageServer`` registers protocol handlers and delegates construction to
``LanguageServerFactory``, the composition root for every parser, index, loader
and provider.

During ``initialize`` and ``initialized``:

1. The server negotiates position encoding and reads initialization options.
2. ``ProjectDiscovery`` searches workspace folders for ``composer.json`` files
   requiring ``symfony/framework-bundle``.
3. Explicit ``projectRoots`` replace automatic recursive discovery when set.
4. The source scanner starts for every discovered project.
5. Runtime indexing starts only after workspace trust is granted.

A document belongs to the most specific containing project. Project settings,
runtime status, source indexes and runtime indexes remain isolated by project
root.

Source Indexing
---------------

``ApplicationSourceScanner`` reads application-owned PHP, Twig, YAML, XLIFF,
JSON translation and dotenv files. It excludes ``.git/``, ``node_modules/``,
``var/`` and ``vendor/``.

Each ``SourceIndexProviderInterface`` implementation participates in a scan:

* ``begin()`` resets temporary project state;
* ``index()`` extracts fresh facts from a file;
* ``restore()`` accepts facts from the persistent cache;
* ``finish()`` atomically replaces the completed project index;
* ``replace()`` and ``remove()`` update one saved or watched file;
* ``overlay()`` and ``removeOverlay()`` manage unsaved documents.

The persistent source index lives under
``var/symfony-lsp/<server-version>/index/`` in the application. Entries include
file size, modification time, content hash, language ID and one serialized
payload per provider. ``SourceIndexPayloadCodec`` permits only explicitly
listed source-fact classes. A missing provider payload, invalid class or corrupt
entry causes a transparent rebuild.

Open documents are authoritative. Opening or changing a document overlays its
facts without writing the persistent cache or booting the application. Saving
replaces the disk facts. Closing removes the overlay and exposes the saved facts
again.

Runtime Indexing
----------------

Runtime indexing executes application code and therefore requires workspace
trust. ``BridgeInstaller`` writes a content-addressed bridge bundle under the
application's ``var/symfony-lsp/<server-version>/`` directory. The bridge is
compatible with every supported Symfony branch and uses the configured PHP
command, environment and debug mode.

One bridge process boots one ``App\\Kernel`` and loads every requested section.
Sections use structured Symfony commands or public runtime APIs, then normalize
the result into arrays. A section can include completeness flags, resources and
warnings. Failure in an optional section is recorded independently so valid
sections remain loadable.

``RuntimeSnapshotLoaderRegistry`` maps section names to project index loaders.
The bridge snapshot never contains service instances, parameter values,
environment values, credentials or arbitrary application objects.

Runtime Refresh
---------------

Saving or watching relevant PHP and configuration files marks runtime metadata
stale and schedules a refresh. ``DebouncedRuntimeRefreshScheduler`` collapses
nearby changes, serializes refreshes per project and queues one replacement when
changes arrive during an active refresh.

Most runtime-affecting changes clear the selected application's normal cache
before loading a new snapshot. Translation resource changes use cache warmup
instead. Source overlays remain immediately available while runtime metadata is
stale.

A failed first load marks runtime indexing as failed. A failed refresh after a
valid snapshot keeps the previous snapshot and marks it stale. Successful
refreshes republish diagnostics for all open documents in that project.

Feature Providers
-----------------

Feature providers implement narrow interfaces for completion, hover,
definition, references, diagnostics, document links, code actions, code lenses
or rename. Registries call every provider and merge matching results.

Returning ``null`` means that a provider doesn't recognize the request context.
Returning an empty list means that the context belongs to the provider but no
results are available. This distinction allows several Symfony providers to
coexist with a general PHP, Twig or YAML language server.

Providers resolve the current document and project, identify a precise semantic
context and query source or runtime indexes. They don't boot the kernel, access
the LSP transport directly or parse generated cache implementation files.

Document Lifecycle
------------------

``DocumentSynchronizer`` applies full or incremental LSP changes using the
negotiated position encoding. Document notifications then drive these actions:

* open and change update source overlays and publish diagnostics;
* save persists source facts, schedules runtime refresh when relevant and
  republishes diagnostics;
* close removes the overlay and clears published diagnostics;
* watched-file changes update or remove individual source entries;
* workspace-folder changes rediscover projects and start new scans.

Twig files are normalized to the ``twig`` language ID even when an editor sends
another association.

Trust and Privacy Invariants
----------------------------

These rules are non-negotiable:

* untrusted workspaces remain source-only;
* stdout is reserved for framed LSP messages;
* bridge stdout contains one normalized JSON snapshot only;
* secrets, parameter values and environment values never enter indexes, logs,
  hovers, diagnostics or protocol traces;
* protocol tracing is disabled by default and recursively redacts values;
* rename and code actions edit application-owned files only;
* generated cache files and dependency files are read-only;
* diagnostics for open or runtime-extensible sets remain conservative.

See :doc:`Adding a Framework Integration </adding-integrations>` before adding
a new bridge section, source index or feature provider.
