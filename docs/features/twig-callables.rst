Twig Functions and Filters
==========================

Completion, navigation and argument checking for the Twig functions and
filters your project declares. This works from your source files, with no
runtime analysis. With runtime analysis, the arguments of functions and
filters from installed packages are completed too; see `how it works`_.

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
* completion for the keys of a hash argument, and for the string values of
  those keys, when the PHP parameter documents an array shape;
* hover shows the PHP method behind the callable, its signature and its
  description;
* go to definition opens that method, and find references lists the template
  usages and the declaration.

For example, with this parameter, ``button({ size: '' })`` completes ``sm``,
``md`` and ``lg`` inside the quotes:

.. code-block:: php

    /**
     * @param array{size?: 'sm'|'md'|'lg', icon?: IconOptions} $options
     */
    public function button(array $options): string

The shape can be written in ``@param``, ``@phpstan-param`` or
``@psalm-param``, directly or through a ``@phpstan-type`` or ``@psalm-type``
alias, including one imported with ``@phpstan-import-type`` or
``@psalm-import-type``, from a class in your project or in the method file.
Nested hashes complete the keys of their own shape. Only literal string
values are offered.

Diagnostics
-----------

* ``twig_callable.unknown_argument``: the callable has no parameter with that
  name. Nothing is reported when the callable takes variadic arguments or
  when its declaration can't be read completely.

Limitations
-----------

* the names of Twig's built-in functions and filters, and of those provided
  by installed packages, aren't completed, hovered or checked; they are left
  to a general Twig language server. Their arguments are completed only with
  runtime analysis;
* a ``TwigFunction`` or ``TwigFilter`` created outside ``getFunctions()`` or
  ``getFilters()``, in a helper method for example, isn't recognized;
* rename isn't available.

.. _`how it works`: index.rst
