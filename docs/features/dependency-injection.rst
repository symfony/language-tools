Services and Parameters
=======================

Completion, navigation and diagnostics for service IDs and container
parameters. Effective services and parameters come from the running
application; see `how it works`_.

Where It Works
--------------

* YAML: ``@service`` and ``@?service`` references under ``services:``,
  ``alias:`` and ``decorates:`` values, and ``%parameter%`` anywhere under
  ``config/``;
* PHP: the ``service:`` and ``param:`` arguments of ``#[Autowire]``, and
  ``%parameter%`` inside any of its string arguments;
* XML service files are indexed as navigation targets.

In the Editor
-------------

* completion for service IDs and parameter names;
* service hover shows the class, visibility, whether it's lazy or deprecated,
  what it decorates, its tags and its autowiring types. Parameter hover shows
  the name and its deprecation, never the value;
* go to definition, find references and rename across YAML, PHP and XML
  declarations. Rename asks for confirmation and never edits files outside
  your project.

Diagnostics
-----------

* ``parameter.not_found``: the parameter doesn't exist in the selected
  environment. Needs the running application.

Unknown service IDs aren't reported: a container can create services that no
listing exposes, so an unknown ID isn't proof of a mistake.

Limitations
-----------

* XML files provide navigation targets only. Completion, hover, references
  and rename don't work with the cursor inside an XML file, and an XML file
  that can't be parsed completely contributes nothing;
* ``@service`` references are recognized under ``services:``, not in
  arbitrary configuration keys;
* ``env()`` placeholders are left to the `environment variables`_
  integration;
* diagnostics and references skip sections that belong to another
  environment, such as ``when@test`` or ``config/packages/test/``.

.. _`how it works`: index.rst
.. _`environment variables`: environment.rst
