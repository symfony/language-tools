Routing Integration
===================

The routing integration understands route names and parameters in the selected
Symfony environment, together with PHP, YAML and Twig declarations and usages.

Supported Contexts
------------------

Route names are recognized in these PHP calls when the receiver can be resolved
to Symfony's controller or routing APIs:

* ``AbstractController::generateUrl()``;
* ``AbstractController::redirectToRoute()``;
* ``RouterInterface::generate()``;
* ``UrlGeneratorInterface::generate()``.

Controller helpers remain recognized when an application controller inherits
from ``AbstractController`` through one or more project base classes. Twig's
``path()`` and ``url()`` functions are also supported. The server avoids
suggestions when it can't establish that a similarly named method belongs to a
Symfony API. Static Twig route names and quoted parameter keys use Twig's string
escape semantics. Twig parameter mappings support explicit entries such as
``{slug: article.slug}`` and shorthand entries such as ``{year, month}``.

Route Name Completion
---------------------

Place the cursor after a route-name prefix and invoke completion:

.. code-block:: php

    // src/Controller/ArticleController.php
    namespace App\Controller;

    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

    final class ArticleController extends AbstractController
    {
        public function show(): void
        {
            $this->generateUrl('article_');
        }
    }

The suggestions come from the configured Symfony environment. Internationalized
routes use their canonical names without locale suffixes.

Route Parameter Completion
--------------------------

For a statically known route name, completion is available for string keys in
the parameter map:

.. code-block:: php

    $this->generateUrl('article_show', ['sl']);

The equivalent Twig context is:

.. code-block:: twig

    {{ path('article_show', {'sl'}) }}

If ``article_show`` has the path ``/article/{slug}``, completion suggests
``slug``. Parameters already present in the map aren't suggested again.
Suggestions include placeholders from the route path and host.

Hover
-----

Hover over a recognized route name to display:

* the route name and alias target;
* the path and host;
* allowed methods and schemes;
* default parameter names and requirements;
* the controller.

Definition and Links
--------------------

Definition requests navigate to matching named PHP ``#[Route]`` attributes,
PHP routing configurator calls or YAML route declarations. Route references
also become document links when exactly one source declaration is known.

References and Rename
---------------------

Reference requests can start from a route reference, named PHP ``#[Route]``
attribute, PHP routing configurator call or YAML route declaration. Results
include statically recognized PHP and Twig usages.

Rename updates application-owned declarations and static references. The edit
requires confirmation because dynamic route references may remain unchanged.
It never edits ``vendor/`` or generated files, and a rename to an existing
route name is rejected.

Diagnostics
-----------

A statically known route name that doesn't exist is reported as an error. A
route call with a complete literal parameter map also reports required path or
host parameters that are missing. Parameters with route defaults or values
already configured in the router request context are optional. Parameter maps
that are variables, contain a dynamic top-level key or use top-level array
unpacking aren't diagnosed. Nested arrays and unpacking inside a parameter value
don't make the parameter map dynamic. A quick fix adds the
missing parameters to the literal parameter map.

Only high-confidence Symfony contexts are diagnosed. Twig route references are
diagnosed only in files loaded by the selected environment's Twig loader. Editor
diagnostics update while typing, while ``symfony-lsp check`` analyzes saved
files.
