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
``no-debug``. Input references through typed parameters captured by closures and
arrow functions are also recognized.

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
recognizes ``#[Argument]`` and ``#[Option]`` parameters on invokable commands,
including attributes stacked or grouped with unrelated ones:

.. code-block:: php

    use Symfony\Component\Console\Attribute\Argument;
    use Symfony\Component\Console\Attribute\Option;

    #[AsCommand(name: 'app:import')]
    final class ImportCommand
    {
        use SharedDefinition {
            report as importReport;
        }

        public function __invoke(
            #[Argument]
            string $sourcePath,
            #[\SensitiveParameter, Option(name: 'dry-run')]
            bool $dryRun = false,
        ): int {
            // ...
        }
    }

Input names come from the ``name`` argument or from the second positional
argument of the attribute. Without one, the name is inferred from the parameter
name as Symfony does, so ``$sourcePath`` becomes ``source-path`` and
``$dryRunHTTP`` becomes ``dry-run-http``.

Definitions inherited from application-owned parent classes and traits are
included, and traits are recognized with or without an adaptation block.

``setDefinition()`` reads literal lists of ``InputArgument`` and ``InputOption``
objects, alone or wrapped in an ``InputDefinition``:

.. code-block:: php

    use Symfony\Component\Console\Input\InputArgument;
    use Symfony\Component\Console\Input\InputDefinition;
    use Symfony\Component\Console\Input\InputOption;

    protected function configure(): void
    {
        $this->setDefinition(new InputDefinition([
            new InputArgument('source'),
            new InputOption('format'),
        ]));
    }

Only the Symfony Console classes are recognized, whichever import, alias or
fully qualified name they're written with.

Limitations
-----------

Completion and diagnostics require the receiver to have the
``InputInterface`` type. Calls to methods with the same names on other objects
are ignored. Definitions made inside closures are available for completion,
but are treated as incomplete so unknown-name diagnostics are omitted. The same
applies to parameter attributes whose name is a variable or a constant, and to
attribute arguments passed with the spread operator.

Definition lists holding variables, spreads, keys or objects of other classes
are treated as incomplete too. Names written as literals next to them stay
available for completion.

Hover, navigation, references and validation of argument or option default
values aren't supported.
