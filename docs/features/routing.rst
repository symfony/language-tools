Routing Integration
===================

The routing integration understands effective route names and parameters in the
selected Symfony environment. It combines ``debug:router`` metadata with PHP,
YAML and Twig source indexes.

Supported Contexts
------------------

Route names are recognized in these PHP calls when the receiver can be resolved
to Symfony's controller or routing APIs:

* ``AbstractController::generateUrl()``;
* ``AbstractController::redirectToRoute()``;
* ``RouterInterface::generate()``;
* ``UrlGeneratorInterface::generate()``.

Twig's ``path()`` and ``url()`` functions are also supported. The server avoids
suggestions when it can't establish that a similarly named method belongs to a
Symfony API.

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

The suggestions come from the effective route collection for the configured
Symfony environment.

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
Suggestions include placeholders from the effective route path and host.

Hover
-----

Hover over a recognized route name to display available runtime metadata:

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
effective route name is rejected.

Diagnostics
-----------

A statically known route name that doesn't exist in the effective route
collection is reported as an error. A route call with a complete literal
parameter map also reports required path or host parameters that are missing.
Parameters with route defaults are optional, while dynamic parameter maps aren't
diagnosed.

Diagnostics are limited to open PHP and Twig files and high-confidence Symfony
contexts. They update while typing and are cleared when the file closes.

Static and Runtime Indexing
---------------------------

Runtime metadata comes from
``debug:router --format=json --show-aliases``. The source index scans
application-owned PHP and Twig files, ``config/routes.yaml`` and YAML files
under ``config/routes/``. Unsaved documents overlay the disk-backed index.
