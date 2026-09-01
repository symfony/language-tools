Configuring Symfony Projects
============================

Create ``.symfony-lsp.json`` in the workspace root to share project settings
between editors and ``symfony-lsp check``:

.. code-block:: json

    {
        "version": 1,
        "environment": "dev",
        "translationDiagnostics": true
    }

Only ``version`` is required.

Available Settings
------------------

The configuration file supports these workspace settings:

* ``projectRoots``: Symfony application roots, relative to the workspace or as
  absolute paths inside it. Omit this setting to discover applications
  automatically.

These settings can be configured globally or for one project:

* ``phpCommand``: command and arguments used to run the application, such as
  ``["php"]`` or ``["symfony", "php"]``;
* ``containerProjectRoot``: absolute project path seen by a PHP command that
  runs in a container;
* ``environment``: Symfony environment used for runtime analysis;
* ``debug``: whether to boot Symfony in debug mode, which is required for
  runtime analysis;
* ``runtimeIndexing``: whether Symfony Language Tools may execute the
  application to load routes, services and other information;
* ``bridgeTimeout``: maximum duration in seconds for each application run;
* ``translationDiagnostics``: whether to report statically known missing
  translation keys;
* ``excludePaths``: project-relative path patterns excluded from source
  indexing and diagnostics.

The defaults are automatic project discovery, ``["php"]``, no container
project root, the ``dev`` environment, debug and runtime indexing enabled, a
300-second timeout and missing-translation diagnostics disabled. When a
compatible Symfony CLI starts the server or checker, its project-aware
``symfony php`` command becomes the lower-precedence PHP default.

Configuring Multiple Projects
-----------------------------

Top-level settings apply to every discovered application. Use ``projects`` for
workspace-relative overrides:

.. code-block:: json

    {
        "version": 1,
        "projectRoots": [".", "apps/admin"],
        "environment": "dev",
        "projects": {
            "apps/admin": {
                "phpCommand": [
                    "docker",
                    "compose",
                    "exec",
                    "-T",
                    "php",
                    "php"
                ],
                "containerProjectRoot": "/app"
            }
        }
    }

A project entry must match a discovered Symfony application. Unknown settings,
invalid values and unmatched project entries are reported as configuration
errors.

Excluding Project Paths
-----------------------

Use ``excludePaths`` for embedded fixtures, generated sources or other files
that don't belong to the running application:

.. code-block:: json

    {
        "version": 1,
        "excludePaths": [
            "tests/Insight/Rule/Fixtures/**",
            "generated/"
        ]
    }

Patterns are relative to each Symfony project root. ``*`` and ``?`` match
within one path segment. Use ``**`` as a complete path segment to cross
directories. A pattern ending in ``/`` excludes the complete directory.

Excluded files don't contribute source facts and don't receive editor
diagnostics. A path passed explicitly to ``symfony-lsp check`` remains
selectable, which lets you inspect an excluded fixture when needed. See the
`headless diagnostics guide`_ for checker selector syntax.

Overrides and Workspace Trust
-----------------------------

Project entries override top-level values. List settings such as
``excludePaths`` are replaced rather than merged, so an empty project list
clears the top-level exclusions. Explicit editor settings override the shared
file for that editor only. Command-line options passed to
``symfony-lsp check`` override both.

Workspace trust, protocol tracing, the server executable and its memory limit
remain client-specific. A checked-in configuration never grants workspace trust
or executes application code by itself.

See `Docker support`_ when ``phpCommand`` runs in a container.

.. _`headless diagnostics guide`: features/headless-diagnostics.rst
.. _`Docker support`: docker.rst
