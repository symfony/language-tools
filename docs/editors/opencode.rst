OpenCode
========

OpenCode starts Symfony Language Tools as a custom language server, so its
agent gets Symfony diagnostics, hover, go to definition and find references
while it works. Install the standalone server and put ``symfony-lsp`` on
``PATH``; see `installing the server`_. Linux and Apple Silicon macOS are
supported.

Configuring OpenCode
--------------------

Create ``opencode.json`` in the project root:

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

OpenCode starts the server when it opens a file with one of those extensions,
so add the dotenv suffixes your project uses. Use an absolute path in
``command`` when ``symfony-lsp`` isn't on ``PATH``. Keep OpenCode's general
PHP language server enabled.

OpenCode doesn't show the interactive trust request, so decide in the
configuration file: ``workspaceTrust`` must be ``true`` for the server to run
your application, and ``false`` keeps it to file-based features only. Set it
to ``true`` only for a project you trust.

Configuration
-------------

Shared settings belong in ``.symfony-lsp.json``; see
`project configuration`_. Use ``initialization`` for OpenCode-only overrides
and ``env`` for the server process:

.. code-block:: json

    {
        "$schema": "https://opencode.ai/config.json",
        "lsp": {
            "symfony": {
                "command": ["symfony-lsp"],
                "extensions": [".php", ".twig", ".yaml", ".yml", ".xml"],
                "initialization": {
                    "workspaceTrust": true,
                    "environment": "test"
                },
                "env": {
                    "SYMFONY_LSP_MEMORY_LIMIT": "4G"
                }
            }
        }
    }

Troubleshooting
---------------

Check the resolved configuration and the diagnostics for one file:

.. code-block:: terminal

    $ opencode debug config
    $ opencode debug lsp diagnostics src/Controller/HomeController.php

If runtime information is missing, verify that Composer dependencies are
installed, ``workspaceTrust`` is ``true`` and ``phpCommand`` can run the
application. See the `OpenCode LSP documentation`_ for its own settings.

.. _`installing the server`: ../index.rst
.. _`project configuration`: ../configuration.rst
.. _`OpenCode LSP documentation`: https://opencode.ai/docs/lsp/
