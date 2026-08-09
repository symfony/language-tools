Updating the Visual Guide
=========================

The capture script rebuilds every screenshot used by ``index.html`` and
``features.html``. Run it from the repository root:

.. code-block:: terminal

    $ ./tools/capture-vscode-guide

The script:

* opens the Symfony LSP Marketplace page in an empty VS Code profile;
* clones Symfony Demo and installs its Composer dependencies under ``var/``;
* builds a feature lab from the public runtime application fixture;
* installs Symfony LSP in isolated VS Code profiles;
* waits for source and runtime indexing;
* reproduces every integration and editor workflow in the visual catalog;
* optimizes the screenshots as WebP images;
* closes every VS Code window that it opened, including after a failure.

The regular VS Code profile and its open windows aren't used. Temporary files
are removed after the isolated windows have closed. Set
``KEEP_VSCODE_GUIDE_STATE=1`` to retain them when debugging a failed capture.

Requirements
------------

The script requires ``code``, Composer, Git, ImageMagick, Node.js with the
global WebSocket API, PHP, ``tar`` and the PHP version required by Symfony
Demo.

Set ``CODE`` to use another VS Code command. Set
``SYMFONY_DEMO_REVISION`` to capture a specific branch, tag or revision:

.. code-block:: terminal

    $ CODE=code-insiders SYMFONY_DEMO_REVISION=main \
        ./tools/capture-vscode-guide

The capture script runs ``tools/check-vscode-guide.mjs`` before it finishes.
Run that checker directly after changing the support matrix or catalog markup.
It verifies all 13 integration rows, 65 supported capability combinations and
30 referenced captures.

Review all generated images and both rendered HTML pages before committing the
result.
