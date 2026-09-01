Using Symfony Language Tools with OpenCode
==========================================

OpenCode can start Symfony Language Tools as a custom language server and make
Symfony-aware diagnostics and navigation available to its coding agent. Install
the standalone server, then configure it in the Symfony project.

Installing the Server
---------------------

Use the `standalone guide`_ to download a release and make ``symfony-lsp``
available on ``PATH``.

The OpenCode integration is supported on Linux and macOS.

Configuring OpenCode
--------------------

For a project whose code you trust, create ``opencode.json`` in the project
root:

.. code-block:: json

    {
        "$schema": "https://opencode.ai/config.json",
        "lsp": {
            "symfony": {
                "command": ["symfony-lsp"],
                "extensions": [
                    ".php",
                    ".twig",
                    ".yaml",
                    ".yml",
                    ".xml",
                    ".json",
                    ".js",
                    ".mjs",
                    ".ts",
                    ".env",
                    ".env.local"
                ],
                "initialization": {
                    "workspaceTrust": true
                }
            }
        }
    }

Use an absolute executable path in ``command`` when ``symfony-lsp`` isn't on
``PATH``. OpenCode starts the server when it accesses a file matching one of
the configured extensions. Add other project-specific dotenv suffixes, such as
``.env.dev``, when the agent needs to initiate requests from those files.

Keep OpenCode's general PHP language server enabled. Symfony Language Tools
adds Symfony-specific information alongside it.

Workspace Trust
---------------

OpenCode doesn't display the interactive workspace trust request sent by the
language server, so configure ``workspaceTrust`` explicitly. Set it to ``true``
only after reviewing and trusting the project because runtime indexing executes
application code.

Set ``workspaceTrust`` to ``false`` to keep the project in static-only mode.
Source-based navigation and diagnostics remain available, but features that
need information from the running application are unavailable.

Supported Features
------------------

OpenCode can consume Symfony-aware diagnostics and use hover, go-to-definition
and find-references requests while its agent works on PHP, Twig, configuration
and frontend files.

Changes made with OpenCode are picked up without restarting it. Files changed
outside OpenCode are refreshed the next time OpenCode accesses them.

Configuration
-------------

Put shared settings in ``.symfony-lsp.json``; see the `project configuration`_.
Use the ``initialization`` object for trust, tracing or an OpenCode-only
override:

.. code-block:: json

    {
        "$schema": "https://opencode.ai/config.json",
        "lsp": {
            "symfony": {
                "command": ["symfony-lsp"],
                "extensions": [".php", ".twig", ".yaml", ".yml", ".xml"],
                "initialization": {
                    "workspaceTrust": true,
                    "environment": "test",
                    "trace": "off"
                },
                "env": {
                    "SYMFONY_LSP_MEMORY_LIMIT": "4G"
                }
            }
        }
    }

Use the same setting names as ``.symfony-lsp.json`` for OpenCode-only
overrides. See `Docker support`_ when the PHP command runs in a container. The
``env`` object configures environment variables for the server process.

Troubleshooting
---------------

Inspect the resolved OpenCode configuration first:

.. code-block:: terminal

    $ opencode debug config

Confirm that OpenCode can start the server and receive diagnostics for a file:

.. code-block:: terminal

    $ opencode debug lsp diagnostics src/Controller/HomeController.php

If runtime information is unavailable, verify that the project has Composer
dependencies installed, ``workspaceTrust`` is ``true`` and ``phpCommand`` can
run the application. Set ``trace`` to ``messages`` or ``verbose`` temporarily
for redacted protocol logging. The ``verbose`` level also records sanitized
runtime section failure causes with relative code locations and argument-free
frames. Restore it to ``off`` after troubleshooting.

See the `OpenCode LSP documentation`_ for its custom language server settings
and debugging commands.

.. _`standalone guide`: ../index.rst#installing-a-standalone-release
.. _`project configuration`: ../project-configuration.rst
.. _`Docker support`: ../docker.rst
.. _`OpenCode LSP documentation`: https://opencode.ai/docs/lsp/
