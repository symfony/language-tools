Using Symfony Language Tools with Zed
=====================================

Symfony Language Tools uses Zed's Language Server Protocol client alongside a
general PHP language server. The Zed extension finds or downloads the latest
stable standalone server for the current supported platform.

Platform Support
----------------

The Zed integration supports Linux and macOS on x86-64 and ARM64 systems.

.. warning::

    Windows is not supported. Zed starts language servers over standard input
    and output, while the self-contained Windows server requires the socket
    transport.

Installing the Extension
------------------------

Install the Symfony Language Tools extension from Zed's Extensions page. Also
install Zed's PHP extension for PHP syntax and general PHP language features.
Install the Twig and XML extensions when you edit those file types.

The extension uses ``symfony-lsp`` from ``PATH`` when available. Otherwise, it
downloads the matching server archive from the latest stable GitHub release.

Symfony Language Tools starts for PHP, Twig, YAML, JSON, XML, JavaScript and
TypeScript files. It discovers Symfony applications from their
``composer.json`` files and provides no project features when a worktree
contains no full-stack Symfony application.

Workspace Trust
---------------

Symfony Language Tools asks before executing application code when no trust
decision was configured. Accept the prompt only for a workspace whose code you
trust. The decision lasts for the current language server process.

Set an explicit decision in Zed's ``settings.json`` when the configuration is
already scoped to a trusted project:

.. code-block:: json

    {
        "lsp": {
            "symfony-language-tools": {
                "initialization_options": {
                    "workspaceTrust": true
                }
            }
        }
    }

Set ``workspaceTrust`` to ``false`` to keep every project in static-only mode.

Configuration
-------------

Configure startup options and project settings under the
``symfony-language-tools`` language server:

.. code-block:: json

    {
        "lsp": {
            "symfony-language-tools": {
                "initialization_options": {
                    "phpCommand": ["php"],
                    "containerProjectRoot": "",
                    "consolePath": "bin/console",
                    "environment": "dev",
                    "debug": true,
                    "runtimeIndexing": true,
                    "bridgeTimeout": 300,
                    "projectRoots": [],
                    "trace": "off"
                },
                "settings": {
                    "phpCommand": ["php"],
                    "containerProjectRoot": "",
                    "consolePath": "bin/console",
                    "environment": "dev",
                    "debug": true,
                    "runtimeIndexing": true,
                    "bridgeTimeout": 300,
                    "translationDiagnostics": false
                }
            }
        }
    }

``phpCommand`` is the argument list used to inspect the Symfony application.
For example, use ``["symfony", "php"]`` with Symfony CLI, or a Docker command
with ``containerProjectRoot`` as described in `Docker support`_.

``containerProjectRoot``, ``consolePath``, ``environment``, ``debug``,
``runtimeIndexing``, ``bridgeTimeout``, ``projectRoots``, ``trace`` and
``translationDiagnostics`` have the same behavior as their VS Code
counterparts. Restart the language server after changing initialization
options.

Override the executable or set its memory limit through the binary settings:

.. code-block:: json

    {
        "lsp": {
            "symfony-language-tools": {
                "binary": {
                    "path": "/path/to/symfony-lsp",
                    "env": {
                        "SYMFONY_LSP_MEMORY_LIMIT": "4G"
                    }
                }
            }
        }
    }

Remove ``path`` to keep automatic server discovery and downloading while
setting only the environment variable.

Code Lenses
-----------

Zed disables code lenses by default. Enable them to display Symfony reference
counts above Messenger handlers, event listeners, Twig components and Stimulus
controllers:

.. code-block:: json

    {
        "code_lens": "on"
    }

Selecting a Symfony code lens opens the related locations in Zed.

Troubleshooting
---------------

Open Zed's language server logs and confirm that Symfony Language Tools started
for the worktree. If the automatic download fails, install ``symfony-lsp`` on
``PATH`` or configure an absolute binary path.

If runtime information is unavailable, verify that the project has Composer
dependencies installed, the workspace is trusted and ``phpCommand`` can run the
application. Set ``trace`` to ``messages`` or ``verbose`` temporarily for
redacted protocol logging, then restore it to ``off``.

.. _`Docker support`: ../docker.rst
