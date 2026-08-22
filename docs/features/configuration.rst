Bundle Configuration
====================

Symfony Language Tools understands configuration trees for installed bundles in
the selected environment, including node types, required and default markers,
enum values, examples, deprecations, children and prototype nodes.
Normalization is probed on the real trees, so shorthand keys such as the
Doctrine default connection ``url`` and values that nodes normalize, such
as ``~`` or ``true`` for array nodes, are understood.

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
or duplicate keys, invalid scalar types, invalid enum values, deprecated nodes,
missing required children and malformed structures. Symfony Language Tools
suppresses diagnostics when a root key doesn't belong to an installed bundle, so
application service and import sections aren't mistaken for bundle
configuration. Diagnostics are limited to the application's own ``config/``
directory because configuration files elsewhere, such as bundle test
fixtures, can target another kernel.

Selected Environments
---------------------

The runtime tree is built for the configured Symfony environment. YAML sections
such as ``when@test`` keep their environment wrapper while completion and
validation resolve the enclosed bundle configuration path.

Imports and Refreshes
---------------------

Relative YAML ``resource`` imports are exposed as document links. Saving PHP,
YAML or XML configuration, or ``composer.json``, schedules a debounced runtime
refresh. Open documents continue to use their unsaved contents while runtime
metadata is refreshed.

Limitations
-----------

Diagnostics only report constraints represented by public configuration tree
metadata. Dynamic validation callbacks and options built from arbitrary
application code can require a successful kernel boot before updated metadata
becomes available.
