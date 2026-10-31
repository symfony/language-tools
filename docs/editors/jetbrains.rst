JetBrains IDEs
==============

In PhpStorm and other JetBrains IDEs, use the `Symfony Plugin`_ first. It's a
native plugin that covers the same Symfony integrations and more, and it is
better integrated with the IDE than any language server.

If you still want Symfony Language Tools, for example to get the same
diagnostics as ``symfony-lsp check``, run it through the `LSP4IJ`_ plugin.
Linux and Apple Silicon macOS are supported.

Installing
----------

#. install the standalone server; see `installing the server`_;
#. install the LSP4IJ plugin from the Marketplace tab of the Plugins settings;
#. open the Language Servers tool window and choose New Language Server.

Configuring the Server
----------------------

On the Server tab, name the server and set Command to the absolute path of
``symfony-lsp``. Set ``SYMFONY_LSP_MEMORY_LIMIT=4G`` in Environment variables
for a larger memory limit.

On the Mappings tab, add these languages with their Language ID:

=============  ===============
Language       Language ID
=============  ===============
PHP            ``php``
Twig           ``twig``
YAML           ``yaml``
XML            ``xml``
JSON           ``json``
JavaScript     ``javascript``
TypeScript     ``typescript``
=============  ===============

Add ``.env*`` as a file name pattern with the ``dotenv`` Language ID for
environment files.

Shared settings belong in ``.symfony-lsp.json``; see `project configuration`_.
Override them for the IDE only on the Configuration tab, under Server:

.. code-block:: json

    {
        "symfonyLsp": {
            "environment": "test"
        }
    }

The server asks before running your application. To answer up front, put
this in Initialization Options, or ``false`` to never run it:

.. code-block:: json

    {
        "workspaceTrust": true
    }

``projectRoots`` and ``trace`` belong to Initialization Options too. Restart
the server from the Language Servers tool window after a change.

Troubleshooting
---------------

Select the server in the Language Servers tool window: the Logs tab under
LSP Consoles reports why an application failed to boot. Set ``trace`` to
``messages`` temporarily to add redacted protocol traffic.

.. _`Symfony Plugin`: https://plugins.jetbrains.com/plugin/7219-symfony-plugin
.. _`LSP4IJ`: https://plugins.jetbrains.com/plugin/23257-lsp4ij
.. _`installing the server`: ../index.rst
.. _`project configuration`: ../configuration.rst
