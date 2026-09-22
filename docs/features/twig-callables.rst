Twig Functions and Filters
==========================

Completion, navigation and argument checking for the Twig functions and
filters your project declares. This works from your source files, with no
runtime analysis.

Where It Works
--------------

In any Twig directive: after ``|`` for filters, and anywhere an expression is
allowed for functions.

Declarations are read from ``new TwigFunction()`` and ``new TwigFilter()``
inside the ``getFunctions()`` and ``getFilters()`` methods of an extension,
and from the ``#[AsTwigFunction]`` and ``#[AsTwigFilter]`` attributes on
public methods.

In the Editor
-------------

* completion for function and filter names, and for the named arguments of a
  recognized call;
* hover shows the PHP method behind the callable, its signature and its
  description;
* go to definition opens that method, and find references lists the template
  usages and the declaration.

Diagnostics
-----------

* ``twig_callable.unknown_argument``: the callable has no parameter with that
  name. Nothing is reported when the callable takes variadic arguments or
  when its declaration can't be read completely.

Limitations
-----------

* only callables declared in your project are known. Twig's built-in
  functions and filters, and those provided by installed packages, are left
  to a general Twig language server;
* a ``TwigFunction`` or ``TwigFilter`` created outside ``getFunctions()`` or
  ``getFilters()``, in a helper method for example, isn't recognized;
* rename isn't available.
