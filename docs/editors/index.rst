Using Symfony Language Tools in Your Editor
===========================================

Symfony Language Tools runs alongside your PHP language server and adds
Symfony-aware completion, hover, navigation, references, rename, code lenses
and diagnostics. Keep Intelephense, PHP Tools or any other PHP server
enabled: this one answers Symfony questions only.

Pick your editor:

* `VS Code`_;
* `Neovim`_;
* `Zed`_;
* `OpenCode`_.

Any editor with a Language Server Protocol client can run the server; see
`installing the server`_. Code lenses use the ``editor.action.showReferences``
command, which your editor must run itself to open the related locations.

What Happens When You Open a Project
------------------------------------

The server finds the Symfony applications in your workspace, indexes their
files, then asks to run the application to read its routes, services and
other information. Until you accept, only file-based features are available;
see `Symfony integrations`_.

Indexing progress and the current state are reported by your editor. The
result is cached per project, so later starts are faster.

While You Work
--------------

Completion, navigation and diagnostics follow your unsaved edits: a route you
declare shows up before you save the file.

Information that comes from the running application is refreshed after you
save a file that can change it. If the application stops booting, the last
successful information is kept and the project is reported as stale until a
refresh succeeds.

Commands
--------

Every client exposes four commands, under names of its own:

* refresh the index, when something looks out of date;
* show the index status, to see the selected environment, the kernel and any
  failure;
* switch the Symfony environment, to analyze ``prod`` or ``test`` instead of
  ``dev``;
* switch the kernel, for applications that ship several.

Custom clients invoke them as ``symfony.refreshIndex``,
``symfony.indexStatus``, ``symfony.switchEnvironment`` and
``symfony.switchKernel``.

A switched environment or kernel stays selected until you switch again or
change that setting in your configuration.

Settings
--------

Put project settings in ``.symfony-lsp.json`` so every editor and the command
line share them; see `project configuration`_. Editor settings override that
file for that editor only.

When a Feature Is Missing
-------------------------

Check the index status first. If it reports a static-only or failed state,
Symfony-specific results that need the application are unavailable.

Then verify that:

* the workspace root contains ``composer.json`` and ``vendor/autoload.php``;
* the application boots in the selected environment from the command line;
* the bundle providing the feature is registered in that kernel;
* the workspace is trusted and runtime analysis is enabled;
* ``containerProjectRoot`` matches the path inside the container when PHP runs
  in Docker; see `Docker support`_.

Your editor's output panel or log shows the cause of a failed boot. Running
``symfony-lsp check --verbose`` on the same project shows the same cause in a
terminal.

.. _`VS Code`: vscode.rst
.. _`Neovim`: neovim.rst
.. _`Zed`: zed.rst
.. _`OpenCode`: opencode.rst
.. _`installing the server`: ../index.rst
.. _`Symfony integrations`: ../features/index.rst
.. _`project configuration`: ../configuration.rst
.. _`Docker support`: ../docker.rst
