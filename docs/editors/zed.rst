Zed
===

Zed runs Symfony Language Tools alongside a general PHP language server. The
extension finds ``symfony-lsp`` on ``PATH`` or downloads the latest stable
release for you.

Linux on x86-64 and ARM64 and macOS on ARM64 are supported. Windows isn't:
Zed speaks to language servers over standard input and output, which the
Windows server can't do. Intel macOS isn't either, since no release is built
for it.

Installing the Extension
------------------------

The extension isn't in Zed's registry yet, so install it from source:

#. install `Rust with rustup`_ and add Zed's WebAssembly target:

   .. code-block:: terminal

       $ rustup target add wasm32-wasip2

#. clone the repository:

   .. code-block:: terminal

       $ git clone https://github.com/symfony/language-tools.git

#. in Zed, run ``zed: install dev extension`` from the command palette and
   select the clone's ``editor/zed/`` directory.

The Extensions page then lists Symfony Language Tools as ``DEV``. Open a PHP
file and run ``dev: open language server logs`` to confirm it started.

Install Zed's PHP extension too, plus the Twig and XML extensions if you edit
those files. The server starts for PHP, Twig, YAML, JSON, XML, JavaScript and
TypeScript files.

Configuration
-------------

Shared settings belong in ``.symfony-lsp.json``; see
`project configuration`_. Override them for Zed only in ``settings.json``, and
answer the trust question up front:

.. code-block:: json

    {
        "lsp": {
            "symfony-language-tools": {
                "initialization_options": {
                    "workspaceTrust": true
                },
                "settings": {
                    "environment": "test"
                }
            }
        }
    }

Without ``workspaceTrust``, the server asks before running your application.
Set it to ``false`` to never run it. ``projectRoots`` and ``trace`` belong to
``initialization_options``. Restart the server after a change.

Use the binary settings for another executable or a larger memory limit:

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

Omit ``path`` to keep automatic discovery and only set the environment.

Code Lenses
-----------

Zed hides code lenses by default. Enable them to see the Symfony navigation
lenses:

.. code-block:: json

    {
        "code_lens": "on"
    }

Troubleshooting
---------------

Open Zed's language server logs. They report why an application failed to
boot. If the automatic download fails, put ``symfony-lsp`` on ``PATH`` or set
an absolute ``binary.path``. Set ``trace`` to ``messages`` temporarily to add
redacted protocol traffic.

.. _`project configuration`: ../configuration.rst
.. _`Rust with rustup`: https://rust-lang.org/tools/install/
