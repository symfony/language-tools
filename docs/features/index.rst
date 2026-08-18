Symfony Integrations
====================

Symfony Language Tools combines information from project files with metadata
from the selected Symfony environment. Features based on project files remain
available without running the application. Features that depend on the compiled
container or another runtime service require runtime indexing.

Project file scanning honors your ``.gitignore`` rules, so machine-generated
files such as build caches and package manager lock files are never indexed.
Dotenv files like ``.env.local`` are the exception: they stay indexed to power
environment variable features, and only variable names are read.

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
    * - `Routing`_
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - `Dependency injection`_
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - `Twig template names`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Translations`_
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - `Environment variables`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Bundle configuration`_
      - Yes
      - Yes
      - No
      - No
      - No
      - Yes
    * - `Messenger`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Events`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Security`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Forms, validation and serializer metadata`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `AssetMapper and importmaps`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Stimulus and Live Components`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Doctrine entities and repositories`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - No

Messenger message and handler classes, event and listener classes, Stimulus
controllers and Doctrine entity and repository classes also provide code lenses
for navigating between related declarations.

Workspace Trust
---------------

Runtime indexing boots the application kernel and executes application code.
It is available only in debug mode and for workspaces that you trust. Do not
enable it for a project whose code you would not run from the command line.

Without runtime indexing, Symfony Language Tools continues to provide features
derived from project files. Suggestions that depend on the effective router,
compiled container or another runtime service may be incomplete. Diagnostics are
suppressed when the server cannot prove that the available metadata is complete.

Unsaved Files and Refreshes
---------------------------

Navigation, references, rename and diagnostics derived from project files
reflect unsaved changes. Runtime metadata is refreshed after relevant files are
saved. If a refresh fails, the last valid metadata remains available and the
editor reports that the runtime index is stale.

Use the editor's index commands to refresh project data, inspect the current
status or switch the selected Symfony environment. Language Server Protocol
clients can invoke the corresponding commands directly:

* ``symfony.refreshIndex``;
* ``symfony.indexStatus``;
* ``symfony.switchEnvironment``.

Privacy
-------

Symfony Language Tools uses names, types, relationships and other structural
metadata to provide editor features. Parameter values, environment values,
credentials and application objects are never included in indexes, logs, hover
output, diagnostics or protocol traces. Protocol tracing is disabled by default
and redacts values when enabled.

Current Limitations
-------------------

The current version has these general limitations:

* the kernel must be ``App\Kernel`` or a ``Kernel`` class at a Composer PSR-4
  autoload root;
* one Symfony environment is active at a time for each application root;
* references and rename cover only statically recognized values.

See each integration page for its supported contexts and specific limitations.

Troubleshooting
---------------

Automatic discovery recognizes Composer projects that require
``symfony/framework-bundle`` in ``require``. Legacy applications without
``"type": "project"`` are also recognized when ``bin/console`` exists. Set
``projectRoots`` explicitly for applications with a custom layout.

If a runtime-backed feature returns no results, verify that:

* the workspace root contains ``composer.json``;
* ``composer.json`` requires ``symfony/framework-bundle`` in ``require``;
* ``vendor/autoload.php`` exists;
* the application kernel boots in the configured environment;
* the configured PHP command is compatible with the application;
* ``containerProjectRoot`` matches the container-side project path when the
  PHP command runs in Docker (see `Docker support`_);
* runtime indexing is enabled and the workspace is trusted.

.. _`Docker support`: ../docker.rst
.. _`Routing`: routing.rst
.. _`Dependency injection`: dependency-injection.rst
.. _`Twig template names`: templates.rst
.. _`Translations`: translations.rst
.. _`Environment variables`: environment.rst
.. _`Bundle configuration`: configuration.rst
.. _`Messenger`: messenger.rst
.. _`Events`: events.rst
.. _`Security`: security.rst
.. _`Forms, validation and serializer metadata`: metadata.rst
.. _`AssetMapper and importmaps`: assets.rst
.. _`Stimulus and Live Components`: stimulus.rst
.. _`Doctrine entities and repositories`: doctrine.rst
