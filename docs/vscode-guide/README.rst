Updating the Visual Guide
=========================

The capture script rebuilds every screenshot used by ``index.html``. Run it
from the repository root:

.. code-block:: terminal

    $ ./tools/capture-vscode-guide

The script:

* opens the Symfony LSP Marketplace page in an empty VS Code profile;
* clones Symfony Demo and installs its Composer dependencies under ``var/``;
* installs Symfony LSP in a second isolated VS Code profile;
* waits for source and runtime indexing;
* reproduces completion, hover and Command Palette interactions;
* optimizes the screenshots as WebP images;
* closes every VS Code window that it opened, including after a failure.

The regular VS Code profile and its open windows aren't used. Temporary files
are removed after the isolated windows have closed. Set
``KEEP_VSCODE_GUIDE_STATE=1`` to retain them when debugging a failed capture.

Requirements
------------

The script requires ``code``, Composer, Git, ImageMagick, Node.js with the
global WebSocket API and the PHP version required by Symfony Demo.

Set ``CODE`` to use another VS Code command. Set
``SYMFONY_DEMO_REVISION`` to capture a specific branch, tag or revision:

.. code-block:: terminal

    $ CODE=code-insiders SYMFONY_DEMO_REVISION=main \
        ./tools/capture-vscode-guide

Review all five images and the rendered HTML guide before committing the
result.
