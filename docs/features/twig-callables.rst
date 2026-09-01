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

Public methods declared with Twig's ``#[AsTwigFunction]`` and
``#[AsTwigFilter]`` attributes are recognized too::

    use Twig\Attribute\AsTwigFilter;
    use Twig\Attribute\AsTwigFunction;

    final class ProductExtension
    {
        #[AsTwigFunction('product_label')]
        public function productLabel(Product $product): string
        {
            return $product->name;
        }

        #[AsTwigFilter('short_description')]
        public function shortDescription(string $description): string
        {
            return mb_strimwidth($description, 0, 80, '...');
        }
    }

The attribute's injected-parameter options and variadic methods are honored
when completing and validating arguments.

Completion
----------

Function names are completed in Twig expressions, and filter names after a
``|`` pipe. Suggestions come from the recognized application declarations
and replace the identifier being typed.

Inside a recognized call, argument names are completed from the resolved
PHP callable. Registration options such as ``needs_environment``,
``needs_context`` and ``is_variadic`` determine which names Twig injects or
accepts dynamically. When options come from a constant or variable, injected
parameters are inferred from the callable signature and argument diagnostics
are suppressed. Injected parameters and the filtered value are never suggested,
and names already used in the call are omitted.

.. code-block:: twig

    {{ product|image(width: 200, lazy: true) }}

References
----------

Find All References lists recognized function and filter usages across
application templates. You can request references from the name in a Twig
template, from the static name in a ``TwigFunction``, ``TwigFilter``,
``AsTwigFunction`` or ``AsTwigFilter`` declaration, or from the registered PHP
method when the callable is statically resolved.

Diagnostics
-----------

A named argument that doesn't match any parameter of the resolved PHP
callable is reported as an error, including when other argument values contain
nested function calls. String contents, member calls, hash literal keys and
macro declarations aren't interpreted as function calls. Unrecognized, dynamic
and variadic callables aren't diagnosed.

Hover
-----

Hover describes the custom function or filter. When its callable resolves to an
application method, hover also shows the PHP signature and documentation
summary without PHPDoc tags.

Definition
----------

Go to Definition opens the callable method when its source is available, and
otherwise opens the function or filter registration.

Limitations
-----------

Dynamic names and non-public attributed methods are ignored. A static name
with a dynamic callable supports completion, references and navigation to its
registration, but method navigation and signature-based features are
unavailable. A ``self::class`` callable inside an anonymous class is treated as
dynamic instead of being attributed to the enclosing named class. Functions
and filters provided only by dependencies are left to a general Twig language
server.
