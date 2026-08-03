Dogfooding Symfony LSP
======================

The local Symfonycorp applications provide a real-world test matrix alongside
unit, protocol and fixture tests. Use this matrix for changes involving runtime
metadata, source indexing, cross-file navigation, diagnostics, performance or
release packaging.

Canonical Applications
----------------------

The standard matrix contains these eight applications under the local
``symfonycorp/`` checkout:

* ``book.symfony.com``;
* ``certification.symfony.com``;
* ``connect.symfony.com``;
* ``insight.symfony.com``;
* ``live.symfony.com``;
* ``symfony.com``;
* ``tui.symfony.com``;
* ``oss-websites/twig.symfony.com``.

From the usual Symfony LSP checkout, the Symfonycorp root is
``../../symfonycorp``. Pass a different root to the tools when the repositories
use another layout. Each application must have its Composer dependencies
installed and its ``App\\Kernel`` must boot in the selected environment.

Running the Full Matrix
-----------------------

Build the server dependencies and Tree-sitter sidecar before running the
matrix:

.. code-block:: terminal

    $ composer install
    $ composer tree-sitter:build-sidecar

Run every application against the development server:

.. code-block:: terminal

    $ ./tools/dogfood-symfonycorp \
        ./bin/symfony-lsp \
        ./build/symfony-lsp-tree-sitter \
        ../../symfonycorp

The optional final argument selects the Symfony environment. It defaults to
``dev``:

.. code-block:: terminal

    $ ./tools/dogfood-symfonycorp \
        ./bin/symfony-lsp \
        ./build/symfony-lsp-tree-sitter \
        ../../symfonycorp \
        test

The command prints one summary line per application and exits with a nonzero
status when an application is missing, an index fails, the server writes an
error, an LSP request fails or the server exits unsuccessfully. Detailed JSON
results are written to ``var/dogfood/``, which is intentionally ignored by Git.

Running One Application
-----------------------

Use the lower-level harness while developing a provider:

.. code-block:: terminal

    $ ./tools/dogfood-server \
        ./bin/symfony-lsp \
        ./build/symfony-lsp-tree-sitter \
        ../../symfonycorp/symfony.com

Its optional final argument also selects the Symfony environment. The command
writes one JSON result to standard output, so it can be inspected or redirected
to an ignored file.

What the Harness Checks
-----------------------

For each application, the harness:

* starts a trusted Symfony LSP process with runtime indexing enabled;
* waits for source and runtime indexes to become ready or fail;
* discovers representative PHP, Twig and YAML references in application code;
* opens the matching documents through LSP;
* requests completion, hover, definition, references, document links, code
  lenses, code actions and rename preparation;
* records diagnostics, request errors, response counts and request latency;
* shuts the server down through LSP and verifies its exit status.

A zero-result request isn't automatically a failure. Some methods don't apply
to every probe. Review the detailed result when a changed provider is expected
to handle a probe, and compare response counts before and after the change.
Diagnostics can also represent valid findings in the application and require
manual review.

Run the matrix twice when changing persistent source facts or cache restoration.
The second run exercises restored indexes. Run the complete matrix before a
private release and against the packaged server and sidecar after packaging.

Adding Probes
-------------

Probe definitions live in ``tools/dogfood-server``. Add a probe only for a
statically recognizable context that is likely to occur in several real
applications. Keep probe matching conservative, then add a focused unit or
fixture test for the behavior itself. Dogfooding complements deterministic
tests; it doesn't replace them.
