Environment Variables
=====================

Completion, navigation and diagnostics for ``%env()%`` expressions and the
variables your ``.env`` files declare.

Where It Works
--------------

* ``%env(...)%`` expressions in YAML, PHP, Twig and XML files;
* ``.env``, ``.env.local``, ``.env.test`` and any other file whose name starts
  with ``.env``: each assignment declares a variable, and ``$NAME`` or
  ``${NAME}`` interpolations reference one.

In the Editor
-------------

* completion for variable names and for processors inside ``%env(``;
* hover shows the processor chain, the type the first processor returns and
  the files that declare the variable. Values are never shown;
* go to definition opens the ``.env`` line that declares the variable, and
  find references lists its uses.

Diagnostics
-----------

* ``env.malformed_chain``: the expression isn't closed, a processor segment
  is empty, or a processor that takes an argument is missing one. Works
  without the running application;
* ``env.unknown_processor``: no such processor is installed. Needs the running
  application, which is where the installed processors are read from;
* ``env.incompatible_type``: in a configuration file, the expression returns a
  type the configuration key doesn't accept. Reported as a warning.

Limitations
-----------

* variable names are read from ``.env`` files only. A variable provided by
  the shell, the platform or a secrets vault has no completion and no
  definition, and a variable that no file declares isn't reported;
* only ``%env()%`` expressions are recognized in PHP: ``$_ENV``, ``$_SERVER``
  and ``getenv()`` aren't;
* the variable name inside the expression has to be written literally;
* rename isn't available.
