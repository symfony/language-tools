Twig Functions and Filters
==========================

Symfony Language Tools connects custom Twig functions and filters in templates
to declarations in application PHP files. It complements generic Twig syntax
and completion support from the editor.

Supported Declarations
----------------------

Functions and filters declared with ``TwigFunction`` and ``TwigFilter`` are
recognized when their names are string literals. Class method callables declared
as arrays or first-class callables are also resolved.

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
