Security
========

The Security integration combines effective SecurityBundle configuration with
application-owned PHP, Twig, and YAML source indexes. It understands firewalls,
user providers, role hierarchy, configured authenticators, registered voters,
and recognized authorization checks in the selected Symfony environment.

Completion
----------

Completion is available for:

* user providers referenced by firewall configuration;
* roles in ``IsGranted`` attributes;
* roles passed to ``denyAccessUnlessGranted()`` on Symfony controllers;
* roles passed to ``AuthorizationCheckerInterface::isGranted()``;
* roles passed to Twig's ``is_granted()`` function;
* roles in role hierarchy and access-control configuration;
* firewall names passed to typed ``LogoutUrlGenerator`` calls and Twig's
  ``logout_path()`` and ``logout_url()`` functions.

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
SecurityBundle YAML configuration.

Diagnostics
-----------

After a complete runtime snapshot is available, definitely unknown user
providers and firewall names are reported. Unknown authorization attributes and
roles aren't diagnosed because custom voters define an open set of valid
attributes.

Security and Privacy
--------------------

The bridge reads structured effective security configuration and immediately
reduces it to names, relationships, types, and boolean metadata. In-memory user
names, password hashes, LDAP settings, and other provider values never enter the
snapshot or Language Server Protocol responses.

Static and Runtime Indexing
---------------------------

The runtime bridge uses ``debug:config security --format=json`` for effective
firewall, provider, authenticator, and role-hierarchy metadata. Registered voter
classes come from structured container tag metadata. It doesn't parse the
text-only ``debug:firewall`` command.

The source index scans application-owned PHP, Twig, and YAML files. Unsaved
documents overlay the disk-backed index immediately.
