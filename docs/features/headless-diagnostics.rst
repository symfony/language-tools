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

Using Symfony CLI
-----------------

Symfony CLI can manage the standalone executable and run the checker for the
current project:

.. code-block:: terminal

    $ symfony lsp:check

This integration requires Symfony Language Tools 0.17.0 or newer. Symfony CLI
selects the latest stable release for the current platform, verifies it against
``SHA256SUMS`` and keeps the complete distribution in its own cache. It checks
for a newer stable release at most once every 24 hours, with the same behavior
in interactive shells and non-interactive automation. When the release service
is unavailable or an update can't be verified and installed safely, it reuses a
compatible cached executable. If no cached copy is available, the Symfony CLI
wrapper fails before the checker starts.

Display the managed cache location with:

.. code-block:: terminal

    $ symfony lsp:cache-dir

When Symfony CLI starts the checker, its project-aware ``symfony php`` behavior
becomes the default PHP command. This lets runtime analysis follow the PHP
version and configuration selected for the project. An explicit ``phpCommand``
in ``.symfony-lsp.json``, editor initialization settings or
``symfony-lsp check --php-command`` remains authoritative and replaces this
fallback.

Source-only checks don't execute the project or invoke the configured PHP
command:

.. code-block:: terminal

    $ symfony lsp:check --source-only

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

Paths and patterns are resolved from the workspace root. Arguments without
``*`` or ``?`` select literal files or directories. In patterns, ``*`` and
``?`` stay within one path segment, while ``**`` crosses directories wherever
it appears. For example, ``**.twig`` selects Twig files at every depth and
``src/**.php`` selects PHP files directly or recursively under ``src/``. Quote
patterns so that the shell passes them unchanged to the checker.

Files under ``.git/``, ``node_modules/``, ``var/`` or ``vendor/`` are skipped.
Files excluded by ``.gitignore`` are also skipped, except project-root dotenv
files (``.env*``), which Symfony reads even when ignored. This exception
applies to default and explicit selections. The default selection also skips
project ``excludePaths``. An explicit file, directory or pattern can select
those configured exclusions, but it can't bypass the excluded directories or
other ``.gitignore`` rules.

Runtime analysis is enabled by default and boots the application with the
configured PHP command. Use source-only mode for code that you don't trust or
when CI must not execute the application:

.. code-block:: terminal

    $ symfony-lsp check --source-only

Reports indicate whether each project used runtime or source-only analysis. If
invalid application configuration prevents runtime analysis, the report
identifies the configuration failure and remains incomplete. Other runtime
failures also exit with status ``12`` instead of silently switching to
source-only analysis. If one runtime metadata section fails after other sections
load, diagnostics backed by the healthy sections are still reported and the
runtime state is ``partial``. Last successful metadata for the failed section
remains active when available; otherwise diagnostics that need it are omitted.

Configuring the Check
---------------------

The checker and editor integrations share ``.symfony-lsp.json``. See the
`project configuration`_ for available settings and multi-project examples.
Set ``releaseMetadata`` to ``false`` when the checker must not access Symfony's
release metadata over the network. This also skips the installed-branch support
check.

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

Publish diagnostics as a GitLab Code Quality report:

.. code-block:: yaml

    symfony-lsp:
      stage: test
      script:
        - symfony lsp:check --format=gitlab > gl-code-quality-report.json
      artifacts:
        when: always
        reports:
          codequality: gl-code-quality-report.json

GitLab reports map errors to ``major``, warnings to ``minor`` and information
and hints to ``info``. They use repository-relative paths, one-based lines and
occurrence-specific fingerprints. Baseline-matched diagnostics are omitted so
that accepted findings don't appear as Code Quality degradations. Invocation
and operational failures still produce a valid JSON array, while the nonzero
exit status and standard error identify the failed check.

Generate a SARIF 2.1.0 report for code-scanning systems:

.. code-block:: terminal

    $ symfony-lsp check --format=sarif > symfony-lsp.sarif

SARIF results use workspace-relative paths, UTF-16 coordinates and stable
partial fingerprints. Baseline matches remain visible as accepted external
suppressions. Incomplete runs still produce valid SARIF with operational
notifications, but don't upload reports from exit status ``11`` or ``12``
because their result set may be incomplete.

Standard output contains only the selected report format. Operational details
go to standard error. Failures while processing a source file identify its
project-relative path. The Symfony CLI wrapper also keeps release-management
and cache messages on standard error, so JSON, GitHub Actions, GitLab and SARIF
output remain safe to pipe from standard output. Add ``--verbose`` to include
sanitized runtime section causes in JSON and SARIF reports and show exception
classes, messages, relative code locations and argument-free frames in human
output. GitHub annotations remain generic.
Once JSON, GitLab or SARIF is selected successfully, later invocation,
configuration, indexing and internal failures still produce a valid structured
report.

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

Suppressing Intentional Diagnostics
-----------------------------------

When source code intentionally triggers a diagnostic, add a code-qualified
suppression in a native PHP, Twig, YAML or XML comment. The editor and
``symfony-lsp check`` apply the same suppressions:

.. code-block:: php

    /* @symfony-lsp-ignore template.not_found (intentional missing template) */
    $this->render('test/does_not_exist.html.twig');

.. code-block:: twig

    {# @symfony-lsp-ignore template.not_found (intentional missing template) #}
    {{ include('test/does_not_exist.html.twig') }}

.. code-block:: yaml

    # @symfony-lsp-ignore config.unknown_key (compatibility fixture)
    framework:
        unsupported_option: true

.. code-block:: xml

    <!-- @symfony-lsp-ignore config.unknown_key (compatibility fixture) -->
    <framework:unsupported-option>true</framework:unsupported-option>

A standalone comment targets diagnostics whose ranges start on the next
physical line. A comment that shares a line with source code targets that line.
Blank lines aren't skipped.

The directive accepts a comma-separated list of exact diagnostic codes. Each
listed code suppresses one occurrence, so repeat a code to suppress several
matching diagnostics on the same line. An optional parenthesized reason can
follow the codes. Bare directives, malformed directives and unknown codes
produce a ``suppression.invalid`` warning instead of suppressing diagnostics.

Only native comments are recognized. Directive-shaped text in strings, Twig
verbatim content, YAML block scalars and XML CDATA sections has no effect. YAML
comment suppressions remain active while surrounding syntax is incomplete.
Suppressed diagnostics are omitted from editor publications and checker
reports, and they aren't written to new baselines. A matching entry in an
existing baseline becomes stale.

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

Matched occurrences remain visible in human, JSON, GitHub and SARIF output and
don't block. GitLab Code Quality output omits them. A second identical
occurrence in the same file remains active, and known diagnostics continue to
match after unrelated line movement. Baseline matching also applies when
analysis is incomplete; generating or refreshing a baseline and stale-entry
reporting require a complete run.

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
provider fails for a file, the remaining providers continue and their findings
stay in the report. ``complete`` remains ``false`` and the exit status is
``12``.

When you run ``symfony lsp:check``, Symfony CLI forwards these checker statuses.
A failure to select, download, verify or start the managed executable exits with
status ``1`` instead: the checker didn't run, so no checker status or report was
produced.

Caching and Privacy
-------------------

The checker stores its source index and last successful runtime information
under ``var/symfony-lsp/<server-version>/`` in each application. Runtime
analysis can also update the application's Symfony cache. These directories
must be writable.

A runtime failure still makes the analysis incomplete. When the current
application configuration can be diagnosed, those diagnostics can use
compatible runtime information from the cache. Remove the cache when reproducing
a strictly cold analysis.

CI can cache ``var/symfony-lsp/`` by project revision, platform and Symfony
Language Tools version. Don't publish it as a build artifact or share it between
untrusted projects. Treat the application's Symfony cache according to the same
policy you use when running its console and tests.

When CI uses Symfony CLI, it can also cache the directory printed by
``symfony lsp:cache-dir`` by operating system and architecture. A restored
compatible installation can be used without network access; Symfony CLI still
applies its normal latest-stable update policy when the cache is online.

Reports and baselines can contain diagnostic messages and application names,
but not parameter values, environment values, credentials or resolved secrets.
Baselines contain no absolute checkout paths or source snippets.

Limitations
-----------

The checker has no watch mode and doesn't apply fixes.

.. _`standalone guide`: ../index.rst#installing-a-standalone-release
.. _`source installation guide`: ../index.rst#installing-the-server-from-source
.. _`project configuration`: ../project-configuration.rst
