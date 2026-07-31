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

Runtime Indexing and Trust
--------------------------

Runtime indexing boots ``App\\Kernel`` and executes application code. Enable it
only for workspaces that you trust. The project bridge runs structured Symfony
commands to obtain effective metadata:

* ``debug:router --format=json --show-aliases``;
* ``debug:container --format=json --show-hidden``;
* ``debug:container --types --format=json``.

The bridge also uses the structured parameter output internally to discover
parameter names and deprecations. Parameter values are discarded inside the
bridge and never enter snapshots, logs or Language Server Protocol responses.

With runtime indexing disabled, source-backed navigation, references and rename
remain available. Features that need the effective router or compiled container
may be incomplete, and diagnostics that require a complete value set are
suppressed.

Source Indexes and Overlays
---------------------------

The source scanner indexes application-owned PHP, Twig and YAML files. It
excludes ``vendor/``, ``var/``, ``node_modules/`` and Git metadata. Changes in
open documents overlay the disk-backed index, so navigation, references and
rename reflect unsaved edits.

Runtime Metadata Refresh
------------------------

Saving relevant PHP or YAML configuration schedules a debounced runtime refresh.
The last valid metadata remains available when a refresh fails. Open-document
diagnostics are republished after a successful refresh.

Language Server Protocol clients can execute these commands:

* ``symfony.refreshIndex`` refreshes source and trusted runtime indexes;
* ``symfony.indexStatus`` returns source and runtime status for each project.

Current Limitations
-------------------

The current implementation has these limitations:

* only ``App\\Kernel`` is discovered;
* one Symfony environment is indexed for each application root;
* references and rename only cover statically recognized strings;
* no standalone binary is available;
* bridge failures aren't exposed through a dedicated status interface yet.

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
