Console Commands
================

The Console integration completes and validates input argument and option names
used by Symfony commands.

Completion
----------

Argument name completion is available in ``InputInterface::getArgument()``
calls. Option name completion is available in ``InputInterface::getOption()``
calls:

.. code-block:: php

    use Symfony\Component\Console\Input\InputInterface;

    protected function execute(InputInterface $input): int
    {
        $format = $input->getOption('for');
        $source = $input->getArgument('sou');

        // ...
    }

Suggestions include names configured by the command, inherited definitions and
application-level options such as ``help``, ``verbose``, ``env`` and
``no-debug``.

Diagnostics
-----------

After runtime indexing completes, unknown literal argument and option names are
reported as errors. The same diagnostics are available through
``symfony-lsp check`` with these codes:

* ``console.unknown_argument``;
* ``console.unknown_option``.

Diagnostics are omitted when the command definition cannot be determined
completely. Dynamic name expressions are ignored.

Supported Definitions
---------------------

The integration recognizes arguments and options added in ``configure()`` with
``addArgument()``, ``addOption()`` and static ``setDefinition()`` calls. It also
recognizes ``#[Argument]`` and ``#[Option]`` parameters on invokable commands.
Definitions inherited from application-owned parent classes and traits are
included.

Limitations
-----------

Completion and diagnostics require the receiver to have the
``InputInterface`` type. Calls to methods with the same names on other objects
are ignored.

Hover, navigation, references and validation of argument or option default
values aren't supported.
