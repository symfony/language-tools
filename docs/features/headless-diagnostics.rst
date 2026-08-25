Running Diagnostics Without an Editor
=====================================

The ``symfony-lsp check`` command runs Symfony Language Tools diagnostics
against saved application files without an editor or Language Server Protocol
client. Use it in local automation, pre-commit checks and continuous
integration.

The checker reports Symfony-specific diagnostics only. Keep your PHP syntax,
type, style, dependency-security and test tools in the same CI pipeline.

Installing the Executable
-------------------------

The checker is included in the standalone ``symfony-lsp`` executable. For local
automation and CI, follow the `standalone guide`_ to download the
archive for your platform, verify its checksum and extract the executable. Add
its directory to ``PATH`` or invoke it by its full path:

.. code-block:: terminal

    $ /path/to/symfony-lsp check

To build the executable yourself, follow the `source installation guide`_.

Running a Check
---------------

Run the checker from the workspace root:

.. code-block:: terminal

    $ symfony-lsp check

With no path arguments, the command discovers Symfony projects and analyzes all
supported project files. Pass files, directories or patterns to narrow the
selection:

.. code-block:: terminal

    $ symfony-lsp check src/ templates/
    $ symfony-lsp check 'config/**/*.yaml'

Paths and patterns are resolved from the workspace root. Files excluded by
``.gitignore`` and files under ``.git/``, ``node_modules/``, ``var/`` or
``vendor/`` are skipped. The default selection also skips project
``excludePaths``. An explicit file, directory or pattern can select those
configured exclusions, but it can't bypass dependency, cache or ``.gitignore``
rules.

Runtime analysis is enabled by default and boots the application with the
configured PHP command. Use source-only mode for code that you don't trust or
when CI must not execute the application:

.. code-block:: terminal

    $ symfony-lsp check --source-only

Reports indicate whether each project used runtime or source-only analysis. If
invalid application configuration prevents runtime analysis, the report
identifies the configuration failure and remains incomplete. Other runtime
failures also exit with status ``12`` instead of silently switching to
source-only analysis.

Configuring the Check
---------------------

The checker and editor integrations share ``.symfony-lsp.json``. See the
`project configuration`_ for available settings and multi-project examples.

Command-line options override the shared configuration. Use ``--config=PATH``
to select another file and ``symfony-lsp check --help`` to list every option.

Choosing an Output Format
-------------------------

The default human format prints deterministic project status, diagnostics and a
summary. Use JSON for automation:

.. code-block:: terminal

    $ symfony-lsp check --format=json > diagnostics.json

The JSON document uses ``schemaVersion`` 1. Diagnostic ranges are zero-based,
end-exclusive and encoded as UTF-16 character offsets. It contains project
identity, project-relative and workspace-relative paths, analysis mode, project
status, baseline state, diagnostic provenance and summary counts. Operational
errors include the provider and a sanitized cause when an exception is
available.

Use GitHub Actions annotations for pull request feedback:

.. code-block:: terminal

    $ symfony-lsp check --format=github

Standard output contains only the selected report format. Operational details
go to standard error. Add ``--verbose`` to human output to show sanitized
exception classes and messages. GitHub annotations remain generic. Once JSON is
selected successfully, later invocation, configuration, indexing and internal
failures still produce a valid JSON report with ``complete`` set to ``false``.

Selecting Blocking Diagnostics
------------------------------

By default, every active error-severity diagnostic blocks the check. Warnings,
such as ``config.deprecated_key`` and provisional source-only configuration
findings, remain visible but don't block.

Use ``--fail-on`` to make only selected codes blocking without filtering other
diagnostics from the report:

.. code-block:: terminal

    $ symfony-lsp check \
        --fail-on=route.not_found,translation.not_found

A selected warning becomes blocking. Unknown codes are configuration errors, so
a removed or renamed code cannot silently weaken CI. List the supported contract
with:

.. code-block:: terminal

    $ symfony-lsp check --list-codes
    $ symfony-lsp check --format=json --list-codes

Using a Baseline
----------------

Create an occurrence-specific baseline when adopting the checker in an
application with existing diagnostics:

.. code-block:: terminal

    $ symfony-lsp check --generate-baseline

This writes ``.symfony-lsp-baseline.json`` and records every collected
diagnostic, regardless of the active ``--fail-on`` policy. Ordinary checks read
but never modify the baseline:

.. code-block:: terminal

    $ symfony-lsp check --baseline=.symfony-lsp-baseline.json

Matched occurrences remain visible and don't block. A second identical
occurrence in the same file remains active, and known diagnostics continue to
match after unrelated line movement.

Refresh the baseline explicitly after reviewing current diagnostics:

.. code-block:: terminal

    $ symfony-lsp check --refresh-baseline

Removed occurrences become stale baseline entries. They are reported but don't
block by default. Add ``--strict-baseline`` to require stale entry cleanup.

Exit Statuses
-------------

The exit statuses are stable automation contracts:

* ``0``: analysis completed without blocking diagnostics;
* ``10``: analysis completed with blocking diagnostics or strict stale entries;
* ``11``: invalid invocation, configuration, code policy, baseline or selection;
* ``12``: incomplete analysis caused by indexing, timeout, cancellation, process
  or internal failure.

Operational failure takes precedence over diagnostic findings. A partial report
can contain diagnostics from completed projects and successful providers. If one
provider fails for a file, the remaining providers continue and their
findings stay in the report. ``complete`` remains ``false`` and the exit status
is ``12``.

Caching and Privacy
-------------------

The checker stores its cache under ``var/symfony-lsp/<server-version>/`` in
each application. Runtime analysis can also update the application's Symfony
cache. These directories must be writable.

CI can cache ``var/symfony-lsp/`` by project revision, platform and Symfony
Language Tools version. Don't publish it as a build artifact or share it between
untrusted projects. Treat the application's Symfony cache according to the same
policy you use when running its console and tests.

Reports and baselines can contain diagnostic messages and application names,
but not parameter values, environment values, credentials or resolved secrets.
Baselines contain no absolute checkout paths or source snippets.

Limitations
-----------

The checker has no watch mode or SARIF output and doesn't apply fixes.

.. _`standalone guide`: ../index.rst#installing-a-standalone-release
.. _`source installation guide`: ../index.rst#installing-the-server-from-source
.. _`project configuration`: ../project-configuration.rst
