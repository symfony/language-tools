Running Diagnostics in CI
=========================

The ``symfony-lsp check`` command runs Symfony Language Tools diagnostics
against saved application files without an editor or Language Server Protocol
client.
Use it in local automation, pre-commit checks and continuous integration.

The checker reports Symfony-specific diagnostics only. Keep your PHP syntax,
type, style, dependency-security and test tools in the same CI pipeline.

Running a Check
---------------

Run the checker from the workspace root:

.. code-block:: terminal

    $ symfony-lsp check

With no path arguments, the command discovers Symfony projects and analyzes all
recognized application-owned files. Pass files, directories or patterns to
narrow the selection:

.. code-block:: terminal

    $ symfony-lsp check src/ templates/
    $ symfony-lsp check 'config/**/*.yaml'

Paths and patterns are resolved from the workspace root. Selection uses the same
language recognition, project ownership, ``.gitignore`` and fixed ``.git/``,
``node_modules/``, ``var/`` and ``vendor/`` exclusions as editor indexing.
Every file belongs to the most-specific discovered project.

Runtime analysis is enabled by default. Invoking ``symfony-lsp check``
explicitly authorizes the configured PHP command to boot each selected
application. Use
source-only mode for code that you don't trust or when CI must not execute the
application:

.. code-block:: terminal

    $ symfony-lsp check --source-only

Every report identifies whether each project used runtime metadata or
source-only analysis. When runtime analysis is enabled, a failed, stale,
canceled or timed
out runtime index makes the check incomplete. The checker never falls back to a
clean source-only result after a runtime failure.

Configuring Projects
--------------------

Create ``.symfony-lsp.json`` in the workspace root to share analysis settings
between the checker and editor integrations:

.. code-block:: json

    {
        "version": 1,
        "projectRoots": [".", "apps/admin"],
        "phpCommand": ["php"],
        "environment": "dev",
        "debug": true,
        "runtimeIndexing": true,
        "bridgeTimeout": 300,
        "translationDiagnostics": false,
        "projects": {
            "apps/admin": {
                "phpCommand": [
                    "docker",
                    "compose",
                    "exec",
                    "-T",
                    "php",
                    "php"
                ],
                "containerProjectRoot": "/app"
            }
        }
    }

All fields except ``version`` are optional. The built-in defaults are:

* automatic project discovery;
* ``["php"]`` for ``phpCommand``;
* no ``containerProjectRoot``;
* ``dev`` for ``environment``;
* ``true`` for ``debug`` and ``runtimeIndexing``;
* 300 seconds for ``bridgeTimeout``;
* ``false`` for ``translationDiagnostics``.

Top-level analysis settings apply to every discovered project. Entries under
``projects`` override them for one workspace-relative project root. Unknown
keys, invalid values and project entries that don't match a discovered Symfony
project are configuration errors.

For the checker, command-line values override project entries, which override
top-level file values and built-in defaults. Use ``--config=PATH`` to select a
different configuration file.

Editor integrations load the same file through the language server. Explicit
resource-scoped editor settings override file values. Workspace trust, protocol
tracing, the server executable and its memory limit remain client-specific and
aren't accepted in the checked-in file. A checked-in configuration never grants
workspace trust or executes application code by itself.

Use ``symfony-lsp check --help`` for every command-line override, including the
PHP command, environment, project roots and timeouts.

Choosing an Output Format
-------------------------

The default human format prints deterministic project status, diagnostics and a
summary. Use JSON for automation:

.. code-block:: terminal

    $ symfony-lsp check --format=json > diagnostics.json

The JSON document uses ``schemaVersion`` 1. Diagnostic ranges are zero-based,
end-exclusive and encoded as UTF-16 character offsets. It contains project
identity, project-relative and workspace-relative paths, effective analysis
mode, index status, baseline state and summary counts.

Use GitHub Actions annotations for pull request feedback:

.. code-block:: terminal

    $ symfony-lsp check --format=github

Standard output contains only the selected report format. Operational details
go to standard error. Once JSON is selected successfully, later invocation,
configuration, indexing and internal failures still produce a valid JSON report
with ``complete`` set to ``false``.

Selecting Blocking Diagnostics
------------------------------

By default, every active error-severity diagnostic blocks the check. Warnings,
such as ``config.deprecated_key``, remain visible but don't block.

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

The current codes are:

* ``config.deprecated_key``, ``config.duplicate_key``,
  ``config.invalid_type``, ``config.malformed_structure``,
  ``config.missing_required_key`` and ``config.unknown_key``;
* ``env.incompatible_type``, ``env.malformed_chain`` and
  ``env.unknown_processor``;
* ``event.invalid_listener_method``;
* ``form.unknown_option`` and ``validation.unknown_constraint_option``;
* ``importmap.unknown_entrypoint``;
* ``messenger.invalid_handler_signature``, ``messenger.unknown_bus`` and
  ``messenger.unknown_transport``;
* ``parameter.not_found`` and ``service.not_found``;
* ``route.missing_parameters`` and ``route.not_found``;
* ``security.unknown_firewall`` and ``security.unknown_provider``;
* ``stimulus.unknown_controller``;
* ``template.not_found``;
* ``translation.domain_not_found``, ``translation.not_found`` and
  ``translation.placeholders``;
* ``twig_callable.unknown_argument`` and ``twig_component.not_found``.

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

Matched occurrences remain visible and don't block. Matching is
multiplicity-aware: one known occurrence doesn't hide a second identical
occurrence in the same file. Fingerprints ignore line positions, so unrelated
line movement remains matched, while changed diagnostic evidence becomes
active.

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

The nonzero values are application-specific. They avoid the conventional
``sysexits`` range, shell-reserved statuses 126 and 127 and statuses above 128
that shells use for signal termination.

Operational failure takes precedence over diagnostic findings. A partial report
can contain diagnostics from completed projects, but ``complete`` remains
``false`` and the exit status is ``12``.

Caching and Privacy
-------------------

The checker uses the source index and bridge installation under
``var/symfony-lsp/<server-version>/`` in each application. Runtime analysis can
also update the application's normal Symfony cache. These directories must be
writable.

CI can cache ``var/symfony-lsp/`` by project revision, platform and Symfony
Language Tools version. Don't publish it as a build artifact or share it between
untrusted projects. Treat the application's Symfony cache according to the same
policy you use when running its console and tests.

Reports and baselines contain diagnostic messages and structural names, but not
parameter values, environment values, credentials, resolved secrets, raw
runtime snapshots or application objects. Baselines contain no absolute
checkout paths or source snippets.

Current Limitations
-------------------

The initial checker has no watch mode, doesn't analyze unsaved editor contents
and doesn't modify application files. It supports human, JSON and GitHub Actions
output; SARIF isn't currently provided.
