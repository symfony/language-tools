PHP Constants and Enums in Twig
===============================

Completion, hover and navigation for the PHP constants and enums your
templates reference. This works from your source files, with no runtime
analysis.

Where It Works
--------------

In ``constant()``, ``enum()`` and ``enum_cases()`` calls inside a Twig
directive, for classes, interfaces and enums declared in your project.

In the Editor
-------------

* completion for class names, class constants and enum cases, including the
  case after an ``enum()`` call;
* hover shows the kind of symbol, its signature and its description;
* go to definition opens the PHP declaration, and find references lists the
  template usages.

Diagnostics
-----------

None. A constant or enum that doesn't exist isn't reported.

Limitations
-----------

* constants declared in a trait aren't completed;
* completion lists the members a class declares itself, not the ones it
  inherits;
* symbols from installed packages aren't indexed;
* rename isn't available.
