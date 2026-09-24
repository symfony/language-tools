Security
========

Completion, navigation and diagnostics for firewalls, user providers and
roles. Needs the running application; see `how it works`_.

Where It Works
--------------

* PHP: ``#[IsGranted]``, ``denyAccessUnlessGranted()`` in a controller
  extending ``AbstractController``, ``isGranted()`` on a receiver typed
  ``Security`` or ``AuthorizationCheckerInterface``, and ``getLogoutPath()``
  and ``getLogoutUrl()`` on a ``LogoutUrlGenerator``;
* Twig: ``is_granted()``, ``logout_path()`` and ``logout_url()``;
* YAML: firewall and provider declarations in ``security.yaml``, the
  ``provider`` of a firewall, ``role_hierarchy`` and the ``roles`` of an
  access control rule.

Role names are recognized when they follow Symfony's ``ROLE_`` convention.

In the Editor
-------------

* completion for firewall names, provider names and roles;
* hover over a firewall shows its provider, whether it's enabled, stateless or
  lazy, and its custom authenticators; over a provider, its type and the
  firewalls using it; over a role, the roles it inherits and the roles that
  inherit it;
* go to definition and find references for firewalls, providers and roles;
* a quick fix suggests close firewall or provider names.

Diagnostics
-----------

* ``security.unknown_firewall``: no such firewall;
* ``security.unknown_provider``: no such user provider.

A name declared in any indexed YAML file is accepted, including a file that
belongs to another environment.

Limitations
-----------

* roles aren't diagnosed: an application can grant roles from code;
* a role that appears in neither ``role_hierarchy`` nor ``access_control`` has
  no hover;
* the voters listed on role hover are every voter in the application, not the
  ones that handle that role;
* rename isn't available.

.. _`how it works`: index.rst
