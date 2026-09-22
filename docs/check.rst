Checking a Project from the Command Line
========================================

``symfony-lsp check`` reports the same Symfony diagnostics as the editor, for
saved files, without an editor. Use it in CI, in a pre-commit hook or to see
everything a project would report at once.

It reports Symfony-specific problems only. Keep your PHP syntax, type, style
and test tools in the same pipeline.

Running a Check
---------------

Run it from the workspace root:

.. code-block:: terminal

    $ symfony-lsp check

The command discovers the Symfony applications in the workspace, boots each
one to read its routes, services and other information, then analyzes every
file the project owns. Pass files, directories or patterns to narrow it down:

.. code-block:: terminal

    $ symfony-lsp check src/ templates/
    $ symfony-lsp check 'config/**/*.yaml'

Quote patterns so the shell passes them through. ``*`` and ``?`` stay inside
one path segment; ``**`` crosses directories. A path that matches nothing is
an error, so a typo in a CI script doesn't silently check nothing.

Explicit paths also select files that ``excludePaths`` normally hides, which
is how you inspect an excluded fixture on purpose. Nothing selects files in
the Composer vendor directory, ``node_modules/``, ``.git/`` or paths your
``.gitignore`` excludes.

Options take their value with ``=``: ``--environment=prod`` works,
``--environment prod`` doesn't. Run ``symfony-lsp check --help`` for the
complete list.

Running Without the Application
-------------------------------

Runtime analysis executes your application's code. Use ``--source-only`` when
that isn't acceptable, for example when checking code you don't trust:

.. code-block:: terminal

    $ symfony-lsp check --source-only

Expect far fewer results. Most diagnostics compare your code against the
running application, so they're silently skipped. What remains comes from your
source files alone: malformed environment variable expressions, listener
methods that don't exist, translation placeholders, YAML files indented with
tabs and malformed XML. ``--no-debug`` has the same effect, since Symfony
needs debug mode to expose its configuration.

Exit Status
-----------

* ``0``: no blocking diagnostic;
* ``10``: blocking diagnostics, or stale baseline entries with
  ``--strict-baseline``;
* ``11``: invalid invocation, configuration, selection or baseline;
* ``12``: incomplete analysis, such as a kernel that fails to boot, a timeout
  or an interrupted run.

An incomplete analysis takes precedence over findings: a run that exits ``12``
may have missed diagnostics.

By default every error-severity diagnostic blocks. Warnings don't. Restrict
blocking to specific codes with a comma-separated list:

.. code-block:: terminal

    $ symfony-lsp check --fail-on=route.not_found,template.not_found

Unknown codes are rejected, so a renamed code can't silently weaken CI. An
empty ``--fail-on=`` makes the run report without ever blocking. List the
codes with ``symfony-lsp check --list-codes``.

Some codes are never errors: ``config.unknown_key``, ``config.invalid_type``
and ``env.incompatible_type`` are always warnings, because a bundle can accept
keys no schema describes. Name them in ``--fail-on`` to block on them.
Missing-translation diagnostics are off unless you enable
``translationDiagnostics``.

Report Formats
--------------

``--format=human`` (the default) prints one line per project, one line per
diagnostic and a summary. The other formats go to standard output and can be
piped:

.. code-block:: terminal

    $ symfony-lsp check --format=json > diagnostics.json
    $ symfony-lsp check --format=sarif > symfony-lsp.sarif

* ``json``: every diagnostic with its project, paths, range, code, severity
  and origin, plus project status, baseline state, a summary and any errors.
  Ranges are zero-based and end-exclusive, with UTF-16 character offsets;
* ``github``: workflow commands, so diagnostics appear as annotations on a
  pull request;
* ``gitlab``: a Code Quality report. Baseline matches are omitted so accepted
  findings don't show up as new degradations;
* ``sarif``: SARIF 2.1.0 for code-scanning systems. Baseline matches remain
  visible as accepted suppressions. Don't upload the report when the run
  exited ``12``, since its results may be incomplete.

Only the report goes to standard output; progress, failures and profiles go
to standard error.

GitHub Actions:

.. code-block:: yaml

    - run: symfony-lsp check --format=github

GitLab CI:

.. code-block:: yaml

    symfony-lsp:
        stage: test
        script:
            - symfony-lsp check --format=gitlab > gl-code-quality-report.json
        artifacts:
            when: always
            reports:
                codequality: gl-code-quality-report.json

Accepting Existing Diagnostics
------------------------------

Adopt the checker on an application that already has diagnostics by recording
them once:

.. code-block:: terminal

    $ symfony-lsp check --generate-baseline

This writes ``.symfony-lsp-baseline.json``. Later runs read it, keep matched
diagnostics visible without blocking, and report only new ones:

.. code-block:: terminal

    $ symfony-lsp check --baseline=.symfony-lsp-baseline.json

A baseline entry matches a specific occurrence, not a line number, so it
survives moved code, and a second identical occurrence in the same file stays
active. Entries whose diagnostic disappeared are reported as stale;
``--strict-baseline`` turns them into a failure so the file gets cleaned up.

``--generate-baseline`` refuses to overwrite an existing file and
``--refresh-baseline`` refuses to create a missing one, both exiting ``11``.

Ignoring One Diagnostic
-----------------------

When code triggers a diagnostic on purpose, add a comment. The editor and the
command line honor it:

.. code-block:: php

    /* @symfony-lsp-ignore template.not_found (checked at runtime) */
    return $this->render($template);

The same directive works in Twig, YAML and XML comments. It accepts several
comma-separated codes and an optional reason in parentheses. On its own line
it applies to the next line; after code, it applies to that line. An unknown
code or a directive without a code reports ``suppression.invalid`` instead of
hiding anything.

Configuration
-------------

The command reads ``.symfony-lsp.json`` from the workspace root, like the
editor does; see `project configuration`_. Command-line options win over the
file. Use ``--config=PATH`` to load another file.

Applications that ship several kernels are checked one at a time:

.. code-block:: terminal

    $ symfony-lsp check --kernel='Admin\Kernel' src/Admin config/admin

The whole run stops after 600 seconds and each application boot after 300;
raise them with ``--timeout=`` and ``--bridge-timeout=`` on slow machines or
containers.

Using Symfony CLI
-----------------

Symfony CLI can download and run the checker for the current project:

.. code-block:: terminal

    $ symfony lsp:check

It manages its own copy of the executable and runs the application with the
PHP version the project is configured for.

When Something Looks Wrong
--------------------------

* a run exits ``12``: add ``--verbose`` to see the cause, usually the
  application failing to boot in the selected environment;
* diagnostics you expect are missing: check the project line in the report.
  ``source-only`` means the application wasn't executed;
* the checker is slow: ``--profile`` prints where the time goes on standard
  error;
* nothing indicates progress while it runs. That's expected, there's no
  progress output and no watch mode.

The checker never changes your files: it applies no fixes.

.. _`project configuration`: configuration.rst
