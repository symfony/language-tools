Benchmarking
============

The server benchmark uses the runtime fixture application and a real Language
Server Protocol process. Build the Tree-sitter extension, warm the fixture once,
and run:

.. code-block:: terminal

    $ composer tree-sitter:build
    $ composer server:benchmark

The command prints JSON containing initialization and indexing durations,
completion and hover p95 latency, cancellation latency, and idle resident memory
before and after repeated requests. It also evaluates the current engineering
targets:

* cached completion and hover p95 below 100 milliseconds;
* source indexing below 500 milliseconds;
* a warm runtime snapshot below 3 seconds;
* cancellation handled below 100 milliseconds;
* less than 10 MiB of idle memory growth during the request loop.

The source and runtime targets require a warm application cache. Run the command
twice when measuring a clean checkout and retain both results when investigating
a cold-start regression.

A standalone release executable can be measured against another application:

.. code-block:: terminal

    $ ./tools/benchmark-server \
        /path/to/symfony-lsp \
        /path/to/application \
        /path/to/symfony-lsp-tree-sitter \
        100

Runtime indexing executes the selected application's code. Only benchmark
trusted application checkouts.
