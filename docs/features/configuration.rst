Bundle Configuration
====================

Completion, hover and diagnostics for the configuration of the bundles your
application registers. The schemas come from the running application, so
without it this integration has nothing to offer; see `how it works`_.

Where It Works
--------------

In the YAML, XML and PHP configuration files under ``config/``. Routing files
are left to the `routes`_ integration.

YAML configuration keys and values are checked in every ``when@...`` section,
regardless of the selected environment. Completion and hover use the selected
environment's bundle configuration.

In the Editor
-------------

* completion for configuration keys and for the values of a key with a fixed
  set of choices;
* hover shows the full key path, the expected type, the description, whether
  the key is required, its default, its allowed values, an example and its
  deprecation;
* a YAML ``resource`` import becomes a clickable link;
* a quick fix suggests close keys under the same parent in YAML, PHP and XML.

Diagnostics
-----------

* ``config.unknown_key``: no registered bundle declares this key;
* ``config.invalid_type``: the value doesn't match the type the key expects;
* ``config.deprecated_key``: the bundle deprecated this key;
* ``config.duplicate_key``: the same key is set twice in the file;
* ``config.malformed_structure``: the file can't be read as configuration,
  for example a YAML file indented with tabs or invalid XML.

The first three are reported as warnings, never errors, because a bundle can
accept keys that its schema doesn't describe. Name them in
``--fail-on`` to block a `command-line check`_ on them.

When the application itself rejects your configuration, the error Symfony
reports wins: it's shown at the place that caused it when Symfony pinpoints
it, and the other findings in the project's configuration files are lowered to
warnings until the application boots again.

Limitations
-----------

* select the relevant environment to check configuration for a bundle registered
  only in that environment;
* nothing is validated for a key whose node accepts arbitrary children, since
  anything is valid there;
* PHP configuration values are checked when they're literal;
* tagged YAML values such as ``!php/const`` are not type-checked; enum
  cases are checked when the allowed cases are known;
* go to definition, references and rename aren't available for configuration
  keys.

.. _`how it works`: index.rst
.. _`routes`: routing.rst
.. _`command-line check`: ../check.rst
