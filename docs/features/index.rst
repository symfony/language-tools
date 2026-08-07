Symfony Integrations
====================

Symfony LSP combines effective runtime metadata with application-owned source
indexes. The runtime metadata describes the selected Symfony environment, while
the source indexes provide declaration locations and immediate support for
unsaved documents.

Supported Integrations
----------------------

.. list-table::
    :header-rows: 1

    * - Integration
      - Completion
      - Hover
      - Definition
      - References
      - Rename
      - Diagnostics
    * - :doc:`Routing </features/routing>`
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - :doc:`Dependency injection </features/dependency-injection>`
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - :doc:`Twig template names </features/templates>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`Translations </features/translations>`
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - :doc:`Environment variables </features/environment>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`Bundle configuration </features/configuration>`
      - Yes
      - Yes
      - No
      - No
      - No
      - Yes
    * - :doc:`Messenger </features/messenger>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`Events </features/events>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`Security </features/security>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`Forms, validation, and serializer metadata </features/metadata>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`AssetMapper and importmaps </features/assets>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`Stimulus and Live Components </features/stimulus>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - :doc:`Doctrine entities and repositories </features/doctrine>`
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - No

Messenger message and handler classes, event and listener classes, Stimulus
controllers and Doctrine entity and repository classes also provide code lenses
for navigating between related declarations.

Runtime Indexing and Trust
--------------------------

Runtime indexing boots ``App\\Kernel`` and executes application code. Enable it
only for workspaces that you trust. One kernel is shared by all sections in a
snapshot, then shut down before the snapshot is returned. The project bridge
runs structured Symfony commands to obtain effective metadata:

* ``debug:router --format=json --show-aliases``;
* ``debug:container --format=json --show-hidden``;
* ``debug:container --types --format=json``;
* ``debug:twig --format=json``;
* ``debug:event-dispatcher --format=json``;
* ``debug:config security --format=json`` when SecurityBundle is installed;
* ``debug:form --format=json`` when Form is installed;
* ``debug:config stimulus --format=json`` when StimulusBundle is installed.

The bridge also discovers installed constraint options through reflection,
AssetMapper paths through structured container metadata and environment
processor types and bundle configuration through public Symfony interfaces. It
never executes the ``env-vars`` command.

The bridge also uses the structured parameter output internally to discover
parameter names and deprecations. Effective translation catalogues are read
through Symfony's public translator interfaces. Parameter values are discarded
inside the bridge and never enter snapshots, logs or Language Server Protocol
responses.

With runtime indexing disabled, source-backed navigation, references and rename
remain available. Features that need the effective router or compiled container
may be incomplete, and diagnostics that require a complete value set are
suppressed.

Source Indexes and Overlays
---------------------------

The source scanner indexes application-owned PHP, Twig, YAML, translation,
dotenv and JavaScript or TypeScript controller files. Twig and YAML use bundled
Tree-sitter grammars, preserving useful
source facts and exact byte ranges around incomplete syntax. The scanner
excludes ``vendor/``, ``var/``, ``node_modules/`` and Git metadata. Versioned
source facts persist under
``var/symfony-lsp/<server-version>/index/`` with atomic writes. Entries record
file metadata and content hashes, while corrupted entries rebuild transparently.
Environment and parameter values are never persisted.

Changes in open documents overlay the disk-backed index, so navigation,
references and rename reflect unsaved edits. Saves and watched-file
notifications update individual entries instead of rescanning the application.

Runtime Metadata Refresh
------------------------

Saving relevant PHP, YAML, XML, translation or bundle metadata resources
schedules a debounced runtime refresh. Refreshes are serialized per application
root; changes received during a refresh queue one replacement. In debug mode,
the bridge lets Symfony's resource freshness checks reuse or rebuild the cache.
When changed source facts identify independent runtime domains, the bridge
refreshes only those domains and preserves the dependency injection container
when safe. Refreshes that cannot preserve the container clear its cache before
creating a replacement snapshot. Unknown or ambiguous changes use a full
refresh.

The last valid metadata remains available when a refresh fails. Open-document
diagnostics are republished after a successful refresh. Clients that support
work-done progress receive source and runtime indexing progress notifications.

Language Server Protocol clients can execute these commands:

* ``symfony.refreshIndex`` refreshes source and trusted runtime indexes;
* ``symfony.indexStatus`` returns source and runtime status for each project;
* ``symfony.switchEnvironment`` selects an environment and refreshes its normal
  application cache.

Current Limitations
-------------------

The current implementation has these limitations:

* only ``App\\Kernel`` is discovered;
* one Symfony environment is indexed at a time for each application root;
* references and rename only cover statically recognized strings.

Troubleshooting
---------------

If a runtime-backed feature returns no results, verify that:

* the workspace root contains ``composer.json``;
* ``composer.json`` requires ``symfony/framework-bundle``;
* ``vendor/autoload.php`` exists;
* ``App\\Kernel`` boots in the configured environment;
* the configured PHP command is compatible with the application;
* runtime indexing is enabled for the workspace;
* the relevant structured Symfony command succeeds.

See the individual integration pages for their recognized contexts and
feature-specific limitations.

.. toctree::
    :hidden:

    routing
    dependency-injection
    templates
    translations
    environment
    configuration
    messenger
    events
    security
    metadata
    assets
