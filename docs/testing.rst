Testing and Validation
======================

Symfony LSP uses several test layers because source parsing, runtime metadata,
protocol behavior and editor integration fail in different ways. Choose tests
from the behavior changed, then run the full local quality suite before
committing.

Fast Development Loop
---------------------

Build the native Tree-sitter extension after cloning or changing its grammar or
binding code:

.. code-block:: terminal

    $ composer tree-sitter:build

Run one PHPUnit file while developing:

.. code-block:: terminal

    $ ./tools/php-with-tree-sitter \
        vendor/bin/phpunit --no-progress \
        tests/Feature/Twig/TemplateProviderTest.php

Run all PHP validation before committing:

.. code-block:: terminal

    $ composer test
    $ composer phpstan
    $ composer cs-check

Use ``composer cs-fix`` to apply coding-style fixes. Never bypass failing tests,
static analysis or repository hooks.

Test Layers
-----------

Unit and Feature Tests
~~~~~~~~~~~~~~~~~~~~~~

Tests under ``tests/Document/``, ``tests/Parser/``, ``tests/Project/``,
``tests/Index/`` and ``tests/Feature/`` cover tolerant context extraction,
position conversion, source facts, indexes and individual feature providers.

Every bug fix needs a regression test. Include unrelated contexts and incomplete
syntax when a regular expression or tolerant parser could match too broadly.

Runtime and Protocol Tests
~~~~~~~~~~~~~~~~~~~~~~~~~~

``tests/Runtime/`` covers bridge installation, normalized sections, refreshes,
trust and process behavior. ``tests/Server/`` covers LSP lifecycle, requests,
notifications, cancellation and diagnostics.

The application under ``tests/Fixtures/RuntimeApplication/`` is the
deterministic runtime fixture. Keep secrets and canary values in tests that
prove they never enter bridge snapshots, logs or LSP responses.

Symfony Compatibility
~~~~~~~~~~~~~~~~~~~~~

The compatibility workflow reads ``supported_versions`` from Symfony's release
metadata and installs the runtime fixture against every listed FrameworkBundle
branch. Run or extend ``tests/Compatibility/`` whenever a bridge section uses
version-sensitive commands, services or public APIs.

To reproduce one branch locally, install matching fixture dependencies and set
the same environment variables used by the workflow:

.. code-block:: terminal

    $ cd tests/Fixtures/RuntimeApplication
    $ composer update --with-all-dependencies \
        --with 'symfony/framework-bundle:7.4.*'
    $ cd ../../..
    $ SYMFONY_LSP_COMPAT_BRANCH='7.4.*' \
        SYMFONY_LSP_COMPAT_PROJECT="$PWD/tests/Fixtures/RuntimeApplication" \
        ./vendor/bin/phpunit --no-progress tests/Compatibility

Restore the fixture dependency set needed for other local tests after a targeted
compatibility run.

VS Code Integration
~~~~~~~~~~~~~~~~~~~

Type-check the extension for configuration or client changes:

.. code-block:: terminal

    $ cd editor/vscode
    $ npm ci
    $ npm run check

Run the Extension Development Host tests for server startup, workspace trust,
configuration forwarding, document associations or end-to-end feature changes:

.. code-block:: terminal

    $ cd tests/Fixtures/RuntimeApplication
    $ composer update
    $ cd ../../../editor/vscode
    $ npm ci
    $ npm run test:e2e

The test downloads an isolated VS Code build and disables other extensions to
keep results deterministic.

Performance
~~~~~~~~~~~

Run the server benchmark for changes to parsers, scanning, persistent payloads,
runtime sections, provider lookup or request dispatch:

.. code-block:: terminal

    $ composer server:benchmark

The command must satisfy every target. Run it twice when comparing cold and warm
behavior. See :doc:`Benchmarking </benchmarking>` for target values and packaged
binary commands.

Real Applications
~~~~~~~~~~~~~~~~~

Run one Symfonycorp application while iterating and all eight applications for
cross-file features, bridge changes, parser changes and releases:

.. code-block:: terminal

    $ ./tools/dogfood-symfonycorp \
        ./bin/symfony-lsp \
        ./build/symfony-lsp-tree-sitter \
        ../../symfonycorp

See :doc:`Dogfooding Symfony LSP </dogfooding>` for the project list, harness
behavior and result interpretation.

Validation by Change Type
-------------------------

.. list-table::
    :header-rows: 1

    * - Change
      - Required validation
    * - Source extractor or provider
      - Focused tests, full PHP suite, PHPStan, coding standards and relevant
        dogfood applications
    * - Persistent source facts
      - Source scanner and codec tests, benchmark, then two dogfood runs
    * - Runtime bridge section
      - Bridge tests, compatibility matrix, benchmark and all dogfood
        applications
    * - LSP lifecycle or transport
      - Server protocol tests, cancellation tests, smoke test and benchmark
    * - Tree-sitter extension or sidecar
      - Parser fixtures, native extension tests, sidecar build and smoke test
    * - VS Code extension
      - TypeScript check and Extension Development Host tests
    * - Release packaging
      - Full CI, checksums, packaged smoke test and packaged dogfood matrix
    * - Documentation only
      - RST formatting, heading lengths, links and toctree placement

CI Workflows
------------

Every pushed commit and pull request runs:

* ``PHP quality`` for PHPUnit, PHPStan, coding standards and both Tree-sitter
  builds;
* ``Symfony compatibility`` for every supported Symfony branch;
* ``VS Code integration`` for the Extension Development Host suite.

A green local suite doesn't replace CI. Wait for all workflows before tagging a
release. The release workflow adds platform packaging and smoke tests only when
run manually or by a version tag.

Failure Investigation
---------------------

Preserve the first failing output. Check the narrowest responsible layer before
changing code:

1. Reproduce an extractor or index failure with a focused unit test.
2. Compare source and runtime index status separately.
3. Run the project bridge section directly when runtime metadata is missing.
4. Inspect redacted server stderr, never application secrets or raw values.
5. Compare one dogfood application's detailed JSON before running the matrix.
6. Add a regression test before applying the root-cause fix.

Don't weaken a diagnostic, skip a test or add a fallback only to make a real
application pass. Determine whether the source context, runtime metadata or
application itself is invalid.
