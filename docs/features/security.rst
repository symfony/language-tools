Security
========

The Security integration understands SecurityBundle configuration and
recognized authorization checks in PHP, Twig and YAML files. It supports
firewalls, user providers, role hierarchy, configured authenticators and
registered voters in the selected Symfony environment.

Completion
----------

Completion is available for:

* user providers referenced by firewall configuration;
* roles in imported, aliased and fully qualified ``IsGranted`` attributes;
* roles passed to ``denyAccessUnlessGranted()`` on Symfony controllers;
* roles passed to ``AuthorizationCheckerInterface::isGranted()``;
* roles passed to Twig's ``is_granted()`` function;
* roles in role hierarchy and access-control configuration;
* firewall names passed to typed ``LogoutUrlGenerator`` calls and Twig's
  ``logout_path()`` and ``logout_url()`` functions.

PHP method calls are recognized only when the receiver has the matching Symfony
service type in the same class or method. Controller helper calls are limited to
the ``AbstractController`` subclass that contains them.

Hover
-----

Firewall hover shows whether the firewall is enabled, lazy, or stateless, along
with its user provider and configured authenticators. User-provider hover shows
the provider type and consuming firewalls. Role hover shows direct hierarchy
relationships and registered voter classes.

Definition and References
-------------------------

Definition requests on firewall and provider names navigate to their YAML
declarations. Role definitions navigate to role-hierarchy declarations when
available. References include recognized PHP and Twig authorization checks and
SecurityBundle YAML configuration. Commented-out PHP attributes and calls are
ignored.

Twig ``is_granted()``, ``logout_path()`` and ``logout_url()`` checks are
recognized only as real calls with a static first argument. Look-alike text in
strings, comments, ``verbatim`` blocks and method calls on an object is
ignored.

Diagnostics
-----------

After runtime indexing completes, definitely unknown user providers and
firewall names are reported. Unknown authorization attributes and
roles aren't diagnosed because custom voters define an open set of valid
attributes.

Privacy
-------

User names, password hashes, LDAP settings and other provider values are never
displayed or written to logs.
