Symfony Language Tools Documentation
====================================

Symfony Language Tools brings Symfony-specific features to your editor:
completion, hover, navigation, references, rename and diagnostics for routes,
services, templates, translations and more. It implements the Language Server
Protocol, so its Symfony features are independent of any particular editor, and
it runs alongside a general PHP language server instead of replacing it.

Setting Up Your Client
----------------------

Symfony Language Tools officially supports the following clients. Use the
corresponding page for installation, configuration and troubleshooting:

* `VS Code`_: install the Symfony Language Tools extension from the
  Marketplace. It bundles the language server, so no separate download is
  needed;
* `Neovim`_: install the server with Mason or from a standalone release, then
  enable it through ``nvim-lspconfig``;
* `Zed`_: install the extension to download and run the server automatically on
  Linux and macOS;
* `OpenCode`_: install the standalone server, then configure it as a custom
  language server for Symfony-aware diagnostics and navigation.

Any other editor with a Language Server Protocol client can run the
standalone server: see `installing a release`_ below and configure your client
to start ``symfony-lsp``.

Features
--------

Symfony Language Tools understands routing, dependency injection, Twig
templates, translations, environment variables, bundle configuration, Messenger,
events, security, form and validation metadata, AssetMapper, Stimulus and
Doctrine. Each integration page documents its supported declarations, references
and Language Server Protocol capabilities. See the `supported integrations`_
for the complete feature matrix.

Requirements
------------

The language server supports the maintained Symfony versions listed in
Symfony's `release metadata`_. Your application must have its Composer
dependencies installed and provide a PHP command compatible with its Symfony
version. The PHP command doesn't have to run on your machine: applications
that run in a container are officially supported through `Docker support`_.

.. _`installing a release`:

Installing a Standalone Release
-------------------------------

The VS Code extension bundles the language server, and the Zed extension
downloads it automatically. For a manual installation or a custom binary,
download the archive for your platform from the GitHub release:

* ``linux-x64`` or ``linux-arm64``;
* ``macos-x64`` or ``macos-arm64``;
* ``windows-x64``.

Extract the archive to get the self-contained ``symfony-lsp`` executable. On
Windows, it has an ``.exe`` suffix.

The release also contains ``SHA256SUMS``. Verify the archive checksum before
running it.

Verify the Unix executable before configuring an editor:

.. code-block:: terminal

    $ ./symfony-lsp --version

The macOS binaries aren't signed or notarized. If macOS quarantines an archive
downloaded from the release, remove the quarantine attribute
from the extracted directory after verifying where the archive came from:

.. code-block:: terminal

    $ xattr -dr com.apple.quarantine /path/to/symfony-lsp-v0.9.2-macos-arm64

Run ``./symfony-lsp`` without arguments to start the Language Server Protocol
connection over standard input and standard output. Pass ``--socket=<port>``
to connect to a client listening on that local port instead. On Windows, the
bundled runtime cannot serve the protocol over standard input and output, so
clients must use the socket transport there; the bundled VS Code extension
does this automatically.

The server raises PHP's ``memory_limit`` to ``2G`` when the configured limit
is lower. Set the ``SYMFONY_LSP_MEMORY_LIMIT`` environment variable to
override the limit with any PHP memory limit value, such as ``512M``, ``4G``
or ``-1`` for no limit.

Upgrading
~~~~~~~~~

Download the new archive for the same platform, stop the editor client and
replace the executable. Verify the installed version, then restart
or reload the editor:

.. code-block:: terminal

    $ ./symfony-lsp --version

The first workspace initialization after an upgrade rebuilds the project
index.

Installing the Server from Source
---------------------------------

Source installations require PHP 8.4.1 or later and Composer 2. Clone this
repository outside the Symfony application that you want to edit. Install the
server dependencies and build the bundled Twig and YAML parser extension:

.. code-block:: terminal

    $ composer install
    $ composer tree-sitter:build

The development executable is ``bin/symfony-lsp``. It automatically loads the
locally built parser extension on Unix systems. Verify that it starts:

.. code-block:: terminal

    $ ./bin/symfony-lsp --version

.. _`VS Code`: editors/vscode.rst
.. _`Neovim`: editors/neovim.rst
.. _`Zed`: editors/zed.rst
.. _`OpenCode`: editors/opencode.rst
.. _`supported integrations`: features/index.rst
.. _`Docker support`: docker.rst
.. _`release metadata`: https://symfony.com/releases.json
