Console Commands
================

Completion and diagnostics for the arguments and options a Console command
declares.

Where It Works
--------------

In ``$input->getArgument('name')`` and ``$input->getOption('name')``, when
``$input`` is typed ``InputInterface``.

Declarations are read from ``addArgument()``, ``addOption()`` and
``setDefinition()`` calls in ``configure()``, from ``InputArgument`` and
``InputOption`` lists, and from ``#[Argument]`` and ``#[Option]`` attributes
on ``__invoke()`` parameters. A parameter name without an explicit attribute
name is converted to kebab case, so ``$sourcePath`` declares ``source-path``.
Declarations inherited from a parent command class or a trait are included.

In the Editor
-------------

Completion suggests the names the command declares, plus the application
options the running Symfony application adds.

Diagnostics
-----------

Both need the running application:

* ``console.unknown_argument``: the command declares no such argument;
* ``console.unknown_option``: the command declares no such option.

Nothing is reported when the command builds its definition dynamically, so a
name computed at runtime never produces a false positive.

Limitations
-----------

* hover, navigation, references and rename aren't available for argument and
  option names;
* completion doesn't trigger after the nullsafe operator
  (``$input?->getArgument()``), while diagnostics still apply there;
* commands defined outside your project, in a bundle or in ``vendor/``, get
  no diagnostics;
* a command class declared twice in the project disables its diagnostics.
