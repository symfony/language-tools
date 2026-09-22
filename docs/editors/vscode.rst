VS Code
=======

The extension bundles the language server, so there's nothing else to
install. It requires VS Code 1.91 or later.

.. code-block:: terminal

    $ code --install-extension symfony.language-tools

Add ``--pre-release`` for prerelease versions. VSCodium users install the
matching ``.vsix`` from the `GitHub release`_:

.. code-block:: terminal

    $ codium --install-extension /path/to/downloaded-extension.vsix

The Marketplace picks the package for the machine that hosts the workspace,
so a container or remote host gets its own. Packages exist for Linux x64 and
ARM64, musl-based Linux, macOS ARM64 and Windows x64.

Trust the workspace: VS Code disables the extension in Restricted Mode.

Twig Files
----------

The extension registers ``.twig`` and ``.html.twig`` as the ``twig`` language
so Symfony features work without another extension. It provides no Twig
syntax highlighting, formatting or generic Twig completion; install an
extension such as Modern Twig or Twig Language 2 for those. Files associated
with the ``html`` language are recognized too.

Status and Commands
-------------------

The status bar shows the state of the application whose file you're editing,
and the selected environment once it's ready. Select it for details,
including when stale information was last refreshed.

The command palette offers ``Symfony Language Tools: Refresh Index``,
``Show Index Status``, ``Switch Environment`` and ``Switch Kernel``. Leave
the kernel input empty to detect it again.

Settings
--------

Shared project settings belong in ``.symfony-lsp.json``; see
`project configuration`_. Every setting from that file also exists as
``symfonyLsp.<name>`` in VS Code and overrides it for VS Code only.

These four are specific to VS Code:

* ``symfonyLsp.serverPath``: path to another server executable, for a build
  from source or a standalone release;
* ``symfonyLsp.memoryLimit``: PHP memory limit of the server process, such as
  ``4G`` or ``-1``. Empty keeps the default of 2 GB;
* ``symfonyLsp.trace``: adds redacted protocol messages to the output
  channel. ``off`` by default;
* ``symfonyLsp.projectRoots``: the applications to analyze.

Run ``Developer: Reload Window`` after changing any of those four. The others
apply immediately.

Keep a general PHP language server such as Intelephense or PHP Tools enabled
for PHP types, diagnostics and completion.

Troubleshooting
---------------

Open ``View > Output`` and select ``Symfony Language Tools``. It reports
startup, configuration and indexing failures, including the reason an
application failed to boot.

If the server doesn't start, check that ``symfonyLsp.serverPath``, when set,
points to an existing executable, and that the installed package matches the
platform running the workspace.

.. _`project configuration`: ../configuration.rst
.. _`GitHub release`: https://github.com/symfony/language-tools/releases
