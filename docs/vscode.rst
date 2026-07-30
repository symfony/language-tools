Using Symfony LSP with VS Code
==============================

Symfony LSP is under active development. The bundled VS Code extension makes
it possible to test the implemented features from a source checkout before
standalone binaries and a published extension are available.

Requirements
------------

Install the following software before starting:

* PHP 8.4 or later for the language server;
* Composer 2;
* Node.js and npm to build the development VS Code extension;
* VS Code 1.91 or later;
* a Symfony application using FrameworkBundle 6.4, 7.4, 8.0 or 8.1.

The Symfony application can use an older PHP version accepted by its Symfony
branch. The initial development setup uses the ``php`` command for both the
server and project bridge, so that command must also be compatible with the
application.

Installing the Server
---------------------

Clone this repository outside the Symfony application that you want to test.
Install the server dependencies from the repository root:

.. code-block:: terminal

    $ composer install

The development executable is ``bin/symfony-lsp``. Make sure that it can be
executed:

.. code-block:: terminal

    $ ./bin/symfony-lsp

The command waits for LSP messages on standard input. Stop it with ``Ctrl+C``.
It doesn't provide a command-line interface or print a startup message.

Building the VS Code Extension
------------------------------

Build and package the development extension:

.. code-block:: terminal

    $ cd editor/vscode
    $ npm install
    $ npm run package

This creates ``editor/vscode/symfony-lsp-0.0.1.vsix``. Install it with VS
Code:

.. code-block:: terminal

    $ code --install-extension editor/vscode/symfony-lsp-0.0.1.vsix

Restart VS Code after installation.

Configuration
-------------

Open the Symfony application as a VS Code workspace. Add these settings to the
workspace's ``.vscode/settings.json`` file:

.. code-block:: json

    {
        "symfonyLsp.serverPath": "/absolute/path/to/lsp/bin/symfony-lsp",
        "symfonyLsp.trustWorkspace": true,
        "php.suggest.basic": false
    }

``symfonyLsp.serverPath`` must be an absolute path. The server path is detected
automatically only when running the extension directly from this repository.

``symfonyLsp.trustWorkspace`` allows the server to execute application code.
Runtime indexing needs this permission because it boots ``App\\Kernel`` and
runs Symfony's structured ``debug:router`` command. Keep it disabled for code
that you don't trust. With trust disabled, route metadata isn't loaded and the
currently implemented features return no results.

The PHP suggestion setting is optional. Symfony LSP is designed to coexist with
a general PHP language server such as Intelephense or PHP Tools. Keep that
server enabled for PHP diagnostics, types and general completion.

After changing either Symfony LSP setting, run ``Developer: Reload Window``
from the VS Code command palette.

What to Test
------------

The current prototype provides route completion, hover, definition, references,
rename and diagnostics in open PHP files. It gets effective route metadata from
the selected application's ``dev`` environment with debug mode enabled.

Route Name Completion
~~~~~~~~~~~~~~~~~~~~~

Completion is available for the first string argument in these contexts:

* ``AbstractController::generateUrl()``;
* ``AbstractController::redirectToRoute()``;
* ``RouterInterface::generate()``;
* ``UrlGeneratorInterface::generate()``.

For example, place the cursor after ``article_`` and invoke completion:

.. code-block:: php

    // src/Controller/ArticleController.php
    namespace App\Controller;

    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

    final class ArticleController extends AbstractController
    {
        public function show(): void
        {
            $this->generateUrl('article_');
        }
    }

The server deliberately avoids completion when it can't prove that the
receiver is Symfony's controller or router API. A call such as
``$unknown->generateUrl('article_')`` shouldn't produce Symfony suggestions.

Route Parameter Completion
~~~~~~~~~~~~~~~~~~~~~~~~~~

For a statically known route name, completion is available for string keys in
the second argument array. Suggestions come from placeholders in the effective
route path and host:

.. code-block:: php

    $this->generateUrl('article_show', ['sl']);

If ``article_show`` has the path ``/article/{slug}``, completion suggests
``slug``.

Route Hover
~~~~~~~~~~~

Hover over a route name in one of the supported PHP calls. The hover displays
available runtime metadata:

* route name;
* path;
* allowed methods;
* controller.

Route Definition
~~~~~~~~~~~~~~~~

Use ``Go to Definition`` on a route name to navigate to the matching named PHP
``#[Route]`` attribute. The source index scans application-owned PHP files and
excludes ``vendor/`` and generated files. YAML route declarations aren't
indexed yet.

Route References and Rename
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use ``Find All References`` from a route reference or named PHP ``#[Route]``
attribute to list statically resolved PHP usages. Rename updates those PHP
references and declarations across application-owned files.

The rename preview requires confirmation because dynamic route references may
remain unchanged. It never edits ``vendor/`` or generated files. Renaming to an
existing effective route name is rejected.

Unknown Route Diagnostics
~~~~~~~~~~~~~~~~~~~~~~~~~

A statically known route name that doesn't exist in the effective route
collection is reported as an error. Diagnostics are limited to open PHP files
and high-confidence Symfony receivers. They update while typing and are cleared
when the file closes.

Route Metadata Refresh
~~~~~~~~~~~~~~~~~~~~~~

Saving a PHP file or a ``.yaml`` file under ``config/`` reloads the effective
route collection. Completion, hover and diagnostics then use the refreshed
metadata. A failed refresh keeps the previous route collection available.

Current Limitations
-------------------

This early build has intentional limitations:

* only PHP route calls are supported;
* only ``App\\Kernel`` is discovered;
* indexing always uses ``dev`` with debug mode enabled;
* the project PHP command can't be configured yet;
* route metadata refreshes aren't debounced yet;
* references and rename cover only statically resolved PHP strings;
* only PHP attribute route declarations are indexed;
* no standalone binary is available;
* bridge failures aren't shown through a dedicated status UI yet.

Troubleshooting
---------------

Open ``View > Output`` and select ``Symfony LSP`` to inspect client and protocol
messages. If completion returns no results, check the following:

* the workspace root contains ``composer.json``;
* ``composer.json`` requires ``symfony/framework-bundle``;
* ``symfonyLsp.trustWorkspace`` is enabled;
* ``symfonyLsp.serverPath`` points to an executable file;
* ``vendor/autoload.php`` exists in the Symfony application;
* ``App\\Kernel`` can boot in the ``dev`` environment;
* ``php bin/console debug:router --format=json --show-aliases`` succeeds;
* the route call uses one of the high-confidence contexts listed above.

After updating the server checkout, run ``composer install`` when dependencies
changed. Rebuild and reinstall the VSIX when files under ``editor/vscode/``
change.
