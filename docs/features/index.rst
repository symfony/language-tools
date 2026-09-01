Symfony Integrations
====================

Symfony Language Tools combines project files with information from the
selected Symfony environment. Many features remain available without running
the application; others require runtime indexing.

Use the `headless diagnostics checker`_ to run the same Symfony diagnostics
against saved files in local automation and CI.

Symfony Language Tools honors your ``.gitignore`` rules and always skips
``.git/``, ``node_modules/``, ``var/``, ``vendor/`` and frontend lock files.
Project ``excludePaths`` can omit additional embedded fixtures or generated
sources. Project-root dotenv files remain available for environment variable
names, but their values aren't read.

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
    * - `Console commands`_
      - Yes
      - No
      - No
      - No
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
    * - `AssetMapper and public assets`_
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

Twig
~~~~

.. list-table::
    :header-rows: 1

    * - Integration
      - Completion
      - Hover
      - Definition
      - References
      - Rename
      - Diagnostics
    * - `Template names`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Functions and filters`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `PHP constants and enums`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - No

Workspace Trust
---------------

Runtime indexing boots the application kernel and executes application code.
It is available only in debug mode and for workspaces that you trust. Do not
enable it for a project whose code you would not run from the command line.

Without runtime indexing, Symfony Language Tools continues to provide features
from project files. Features that need the application's current routes,
services or other runtime information may be incomplete. Diagnostics that need
this information are omitted. If one runtime metadata section fails while other
sections load, features backed by the healthy sections remain available and the
runtime index reports a partial state. Enable verbose tracing before refreshing
the index to record the sanitized exception cause and argument-free frames.

Symfony Version Support
-----------------------

During runtime indexing, Symfony Language Tools checks the installed branch
against Symfony's `release metadata`_. Maintained branches, intermediate
branches and the next minor development branch are accepted. Each project's
metadata cache is refreshed at most once per hour under ``var/symfony-lsp/``.
If a refresh fails, the last cached metadata is used. Without a cache, runtime
indexing continues without the support check.

If the installed branch is older than the oldest maintained branch or newer
than the next minor branch, the application isn't booted. The editor or
diagnostics checker reports the detected version and static-only features
remain active. Set ``releaseMetadata`` to ``false`` in
``.symfony-lsp.json`` to prevent release metadata network access and skip this
support check.

Unsaved Files and Refreshes
---------------------------

Navigation, references, rename and diagnostics from project files reflect
unsaved changes. Information from the running application is refreshed after
relevant files are saved. If a refresh fails, the editor reports the project as
stale and keeps the last successful information.

Compatible runtime information from the last successful refresh is retained
across editor restarts. If the application cannot boot during the next refresh,
Symfony Language Tools restores that information, reports when it was last
updated and keeps the project stale until a refresh succeeds.

Use the editor's commands to refresh project data, inspect the current status
or switch the selected Symfony environment. Custom Language Server Protocol
clients can invoke these command identifiers directly:

* ``symfony.refreshIndex``;
* ``symfony.indexStatus``;
* ``symfony.switchEnvironment``.

Runtime timing data returned by ``symfony.indexStatus`` includes a ``scope``
field. ``full`` means section timings cover a complete runtime index;
``targeted`` means they cover only the sections requested by the latest
refresh.

Privacy
-------

Retained runtime information is stored under ``var/symfony-lsp/`` in the
application. Parameter values, environment values, credentials and application
objects are never stored. Runtime failure causes are omitted from normal output.
Verbose tracing and verbose checks can include sanitized exception messages,
relative code locations and argument-free frames. Server traces retain up to 20
frames and truncate each frame independently. Sensitive values are redacted
before output.

General Limitations
-------------------

Symfony Language Tools has these general limitations:

* custom kernel bootstraps must follow a recognized convention: ``App\Kernel``,
  a ``Kernel`` class at a Composer PSR-4 autoload root, legacy
  ``app/AppKernel.php`` or a Symfony Runtime front controller. Symfony Runtime
  options under ``extra.runtime`` and ``APP_RUNTIME_OPTIONS`` are supported;
* one Symfony environment is active at a time for each application root;
* references and rename cover only statically recognized values.

See each integration page for its supported contexts and specific limitations.

Troubleshooting
---------------

Automatic discovery recognizes Composer projects that require
``symfony/framework-bundle`` in ``require`` or contain it in ``composer.lock``
as a transitive dependency. Legacy applications without ``"type": "project"``
are also recognized when ``bin/console`` exists. Set ``projectRoots`` explicitly
for applications with a custom layout.

If a runtime-backed feature returns no results, verify that:

* the workspace root contains ``composer.json``;
* ``composer.json`` requires ``symfony/framework-bundle`` in ``require`` or
  ``composer.lock`` contains it as a transitive dependency;
* ``vendor/autoload.php`` exists;
* the application kernel boots through one of the supported conventions in the
  configured environment;
* the configured PHP command is compatible with the application;
* ``containerProjectRoot`` matches the container-side project path when the
  PHP command runs in Docker (see `Docker support`_);
* runtime indexing is enabled and the workspace is trusted.

.. _`headless diagnostics checker`: headless-diagnostics.rst
.. _`release metadata`: https://symfony.com/releases.json
.. _`Docker support`: ../docker.rst
.. _`Routing`: routing.rst
.. _`Dependency injection`: dependency-injection.rst
.. _`Console commands`: console.rst
.. _`Template names`: templates.rst
.. _`Functions and filters`: twig-callables.rst
.. _`PHP constants and enums`: twig-constants-enums.rst
.. _`Translations`: translations.rst
.. _`Environment variables`: environment.rst
.. _`Bundle configuration`: configuration.rst
.. _`Messenger`: messenger.rst
.. _`Events`: events.rst
.. _`Security`: security.rst
.. _`Forms, validation and serializer metadata`: metadata.rst
.. _`AssetMapper and public assets`: assets.rst
.. _`Stimulus and Live Components`: stimulus.rst
.. _`Doctrine entities and repositories`: doctrine.rst
