Bundle Configuration
====================

Symfony Language Tools understands configuration options for installed bundles
in the selected environment, including required options, default values, enum
values, examples and deprecations. It supports common aliases, shorthand and
normalized values such as a cache pool's ``adapter``, Doctrine's default
connection ``url``, ``~`` and ``true`` for array options. Extensible sections
allow custom keys while validating their known children.

Completion
----------

Configuration completion is available in YAML, XML and PHP configuration
files. YAML suggestions follow the current indentation and mapping path. PHP
suggestions recognize the bundle configuration DSL. XML suggestions follow the
current element path.

YAML value completion suggests allowed enum values. Suggested keys include
type and description details when the bundle provides them.

Hover
-----

Hover shows a node's full configuration path, type, description, required
state, default summary, allowed values, example and deprecation marker. Default
summaries describe the value's type without exporting runtime values.

Diagnostics
-----------

Diagnostics report statically provable configuration errors, including unknown
or duplicate keys, invalid scalar types, invalid enum values, deprecated nodes
and malformed structures. Symfony Language Tools suppresses diagnostics when a
root key doesn't belong to an installed bundle, so application service and
import sections aren't mistaken for bundle configuration. Route resources under
``config/routes/`` and ``config/routes.*`` are analyzed as routes instead.
Diagnostics are limited to the application's own ``config/`` directory because
configuration files elsewhere, such as bundle test fixtures, can target another
kernel.

Selected Environments
---------------------

Completion and validation use the configured Symfony environment. YAML sections
such as ``when@test`` are resolved within their environment wrapper.

Imports and Refreshes
---------------------

Relative YAML ``resource`` imports are exposed as document links. Configuration
changes are picked up after saving, while the current open file continues to
reflect unsaved edits.

Limitations
-----------

Custom validation callbacks and options built dynamically by application code
may not be diagnosed.
