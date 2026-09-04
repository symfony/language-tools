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

The extension isn't published in Zed's registry yet. Until it is listed,
install it from source as a development extension:

#. Install `Rust with rustup`_.
#. Add the WebAssembly target used by Zed extensions:

   .. code-block:: terminal

       $ rustup target add wasm32-wasip2

#. Clone the repository:

   .. code-block:: terminal

       $ git clone https://github.com/symfony/language-tools.git

#. In Zed, open the command palette and run
   ``zed: install dev extension``.
#. Select the clone's ``editor/zed/`` directory. Zed compiles and loads the
   extension.

Open Zed's Extensions page and confirm that Symfony Language Tools is marked as
``DEV``. Open a PHP or Twig file, then run
``dev: open language server logs`` to confirm that the server starts.

Also install Zed's PHP extension for PHP syntax and general PHP language
features. Install the Twig and XML extensions when you edit those file types.

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

Put shared settings in ``.symfony-lsp.json``; see the
`project configuration`_.

Configure Zed-only overrides under the ``symfony-language-tools`` language
server:

.. code-block:: json

    {
        "lsp": {
            "symfony-language-tools": {
                "initialization_options": {
                    "workspaceTrust": true,
                    "trace": "off"
                },
                "settings": {
                    "environment": "test"
                }
            }
        }
    }

Use the same setting names as ``.symfony-lsp.json`` for Zed-only overrides.
See `Docker support`_ when the PHP command runs in a container. Restart the
language server after changing its configuration.

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
redacted protocol logging. The ``verbose`` level also records sanitized runtime
section failure causes with relative code locations and argument-free frames.
Restore it to ``off`` after troubleshooting.

.. _`Docker support`: ../docker.rst
.. _`project configuration`: ../project-configuration.rst
.. _`Rust with rustup`: https://rust-lang.org/tools/install/
