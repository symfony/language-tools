Routes
======

Completion, navigation and diagnostics for route names and their parameters.
Route names come from the running application, so most of this page needs
runtime analysis; see `how it works`_.

Where It Works
--------------

* PHP: ``generateUrl()`` and ``redirectToRoute()`` in a controller extending
  ``AbstractController``, and ``generate()``, ``generateUrl()`` or
  ``redirectToRoute()`` on a property or parameter typed ``RouterInterface``
  or ``UrlGeneratorInterface``;
* Twig: ``path()`` and ``url()``;
* declarations: ``#[Route]`` attributes with an explicit name, PHP routing
  configurators and YAML files in ``config/routes.yaml`` or
  ``config/routes/``.

In the Editor
-------------

* completion for route names, and for parameter keys in a literal parameter
  array once the route name is known;
* hover shows the path, host, allowed methods and schemes, default parameter
  names, requirements, the controller and the aliased route when there is one;
* go to definition, find references and rename across PHP, Twig and YAML.
  Rename asks for confirmation because dynamic route names can't be updated,
  and it never edits files outside your project;
* a route reference becomes a clickable link when exactly one declaration
  matches;
* a quick fix adds the missing parameters to a PHP short array or a Twig
  parameter map.

Diagnostics
-----------

Both need the running application:

* ``route.not_found``: the route name doesn't exist in the selected
  environment;
* ``route.missing_parameters``: a literal parameter array doesn't provide
  every parameter the route path or host requires. Parameters with a default
  or a value in the router request context are optional.

Twig files are diagnosed only when the selected environment's Twig loader
actually loads them.

Limitations
-----------

* XML route files aren't supported;
* a ``#[Route]`` attribute without an explicit name isn't a navigation or
  rename target;
* YAML routes are recognized in ``config/routes.yaml``, ``config/routes.yml``
  and ``config/routes/`` only;
* a parameter array built from a variable, a dynamic key or top-level
  unpacking isn't diagnosed;
* the missing-parameter quick fix doesn't rewrite the long ``array(...)``
  syntax.

.. _`how it works`: index.rst
