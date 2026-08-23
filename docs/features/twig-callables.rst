Twig Functions and Filters
==========================

Symfony Language Tools connects custom Twig functions and filters in templates
to declarations in application PHP files. It complements generic Twig syntax
and completion support from the editor.

Supported Declarations
----------------------

Functions and filters returned by extension ``getFunctions()`` and
``getFilters()`` methods with ``TwigFunction`` and ``TwigFilter`` are recognized
when their names are string literals. Statically resolvable class method
callables declared as arrays or first-class callables are also resolved.

Completion
----------

Function names are completed in Twig expressions, and filter names after a
``|`` pipe. Suggestions come from the recognized application declarations
and replace the identifier being typed.

Inside a recognized call, argument names are completed from the resolved
PHP callable. Registration options such as ``needs_environment``,
``needs_context`` and ``is_variadic`` determine which names Twig injects or
accepts dynamically. Injected parameters and the filtered value are never
suggested, and names already used in the call are omitted.

.. code-block:: twig

    {{ product|image(width: 200, lazy: true) }}

References
----------

Find All References lists the recognized function and filter usages across
indexed application templates.

Diagnostics
-----------

A named argument that doesn't match any parameter of the resolved PHP
callable is reported as an error. String contents, member calls and hash
literal keys aren't interpreted as arguments. Unrecognized, dynamic and
variadic callables aren't diagnosed.

Hover
-----

Hover describes the custom function or filter. When its callable resolves to an
application method, hover also shows the PHP signature and documentation.

Definition
----------

Go to Definition opens the callable method when its source is available, and
otherwise opens the function or filter registration.

Limitations
-----------

Dynamic names and callables are ignored. Functions and filters provided only by
dependencies are left to a general Twig language server.
