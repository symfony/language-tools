Forms, Validation, and Serializer Metadata
==========================================

Symfony LSP combines effective Form and Validator metadata with application
source mappings.

Forms
-----

Form option completion and hover are available in literal option arrays passed
to ``createForm()``, ``createNamed()``, and form builder ``add()`` calls when
the form type is a static ``::class`` reference. Required options are identified
in completion and hover details.

Definitely unknown literal options are diagnosed for form types successfully
resolved from the selected application's Form registry. Dynamic option arrays
and unresolved form types are ignored.

Validation
----------

Constraint and constraint-option completion is available in PHP attributes and
YAML validation mappings. Symfony's installed constraints and their constructor
options come from runtime reflection. Application constraint classes extending
``Constraint`` are added by the source index.

Definitely unknown options are diagnosed only when the constraint itself is
known. Go to Definition and Find All References connect application constraint
classes to static PHP and YAML usages.

Serializer Groups and Mapped Properties
---------------------------------------

Known serializer groups are completed in PHP ``Groups`` attributes, literal
``groups`` context arrays, and YAML mappings. Hover and Find All References
show their statically recognized occurrences.

Go to Definition connects YAML validation and serializer class and property
mappings to application PHP declarations. Property completion is available
under YAML ``properties`` and ``attributes`` mappings.

Serializer groups and form options remain extensible at runtime. Symfony LSP
does not diagnose unknown groups, dynamic arrays, or metadata it cannot resolve
exactly.
