Symfony Language Tools
======================

Symfony Language Tools makes your editor understand Symfony: route names,
service IDs, template names, translation keys, environment variables, bundle
configuration and more get completion, hover, navigation, references, rename
and diagnostics. It implements the Language Server Protocol, so it works in
any compatible editor, and it complements a general PHP language server
instead of replacing it.

There are two ways to use it:

* `in your editor`_, while you write code;
* `on the command line`_, to check a project in CI or before a commit.

Both use the same analysis and the same `Symfony integrations`_.

Requirements
------------

An application on a maintained Symfony version, with its Composer
dependencies installed and a PHP command compatible with it. That command
doesn't have to run on your machine: applications running in a container are
supported; see `Docker support`_.

Installing
----------

**VS Code**: install the Symfony Language Tools extension from the
`Visual Studio Marketplace`_. It bundles the server.

.. code-block:: terminal

    $ code --install-extension symfony.language-tools

**Neovim**: install the server, then enable it through ``nvim-lspconfig``.
See the `Neovim guide`_.

**Zed**: install the extension from source while registry publication is
pending. It downloads the server for you. See the `Zed guide`_.

**OpenCode**: install the server, then declare it as a custom language
server. See the `OpenCode guide`_.

**Any other client, and the command line**: download the standalone server
below.

Installing the Standalone Server
--------------------------------

Download the archive for your platform from the `GitHub release`_:
``linux-x64``, ``linux-arm64``, ``macos-arm64`` or ``windows-x64``. The Linux
executables are statically linked, so the same archive runs on glibc and musl
distributions, including Alpine. Intel macOS isn't supported; build from
source there.

The release also contains ``SHA256SUMS``. Verify the archive, extract it and
check that the executable runs:

.. code-block:: terminal

    $ ./symfony-lsp --version

The macOS binaries aren't signed. If macOS quarantines the archive, remove
the attribute after checking where it came from:

.. code-block:: terminal

    $ xattr -dr com.apple.quarantine /path/to/symfony-lsp-vX.Y.Z-macos-arm64

Point your editor at that executable. Started without arguments, it speaks
the protocol over standard input and output; ``--socket=<port>`` connects to
a client listening on a local port instead. Windows clients must use the
socket, which the VS Code extension does on its own.

The server raises PHP's memory limit to 2 GB. Set
``SYMFONY_LSP_MEMORY_LIMIT`` to override it, for example ``4G`` or ``-1``.

To upgrade, replace the executable while the editor is stopped. The first
start after an upgrade rebuilds the project index.

Building from Source
--------------------

Source installations need PHP 8.4.1 or later and Composer 2. Clone the
repository outside the application you want to edit, then:

.. code-block:: terminal

    $ composer install
    $ composer tree-sitter:build
    $ ./bin/symfony-lsp --version

Tested with Real Applications
-----------------------------

Symfony Language Tools is continuously tested against `Kimai`_ and `Mautic`_
on Symfony 6.4, `Sulu Demo`_, `Sulu Skeleton`_, `Sylius`_, `Shopware`_ and
`Pimcore Skeleton`_ on Symfony 7.4, and `Symfony Demo`_ on Symfony 8.1. These
cover conventional, legacy and distribution-specific bootstraps, large
codebases and different Symfony features.

.. _`in your editor`: editors/index.rst
.. _`on the command line`: check.rst
.. _`Symfony integrations`: features/index.rst
.. _`Docker support`: docker.rst
.. _`Neovim guide`: editors/neovim.rst
.. _`Zed guide`: editors/zed.rst
.. _`OpenCode guide`: editors/opencode.rst
.. _`Visual Studio Marketplace`: https://marketplace.visualstudio.com/items?itemName=symfony.language-tools
.. _`GitHub release`: https://github.com/symfony/language-tools/releases
.. _`Kimai`: https://github.com/kimai/kimai
.. _`Mautic`: https://github.com/mautic/mautic
.. _`Sulu Demo`: https://github.com/sulu/sulu-demo
.. _`Sulu Skeleton`: https://github.com/sulu/skeleton
.. _`Sylius`: https://github.com/Sylius/Sylius
.. _`Shopware`: https://github.com/shopware/shopware
.. _`Pimcore Skeleton`: https://github.com/pimcore/skeleton
.. _`Symfony Demo`: https://github.com/symfony/demo
