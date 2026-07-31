Symfony LSP Documentation
=========================

Symfony LSP adds Symfony-specific editor features without replacing a general
PHP language server. It implements the Language Server Protocol, so its Symfony
features are independent of any particular editor.

Requirements
------------

The language server requires:

* PHP 8.4 or later;
* Composer 2;
* a Symfony application using FrameworkBundle 6.4, 7.4, 8.0 or 8.1.

The Symfony application can use an older PHP version accepted by its Symfony
branch. Configure the project bridge to use a compatible PHP command when the
language server and application require different PHP versions.

Installing the Server from Source
---------------------------------

Clone this repository outside the Symfony application that you want to edit.
Install the server dependencies and build the bundled Twig and YAML parser
extension from the repository root:

.. code-block:: terminal

    $ composer install
    $ composer tree-sitter:build

The development executable is ``bin/symfony-lsp``. It automatically loads the
locally built parser extension on Unix systems. Verify that it starts:

.. code-block:: terminal

    $ ./bin/symfony-lsp

The command waits for LSP messages on standard input. Stop it with ``Ctrl+C``.
It doesn't provide a command-line interface or print a startup message.

Features
--------

Each Symfony integration documents its supported declarations, references and
Language Server Protocol capabilities:

.. toctree::
    :maxdepth: 2

    features/index

Editor Integrations
-------------------

Editor pages only cover installation and editor-specific configuration. All
editors expose the same Symfony language features.

.. toctree::
    :maxdepth: 1

    editors/vscode
