Forms, Validation and Serializer Metadata
=========================================

Symfony Language Tools understands form options, validation constraints,
serializer groups and project mappings.

Forms
-----

Form option completion and hover are available in literal option arrays passed
to ``createForm()``, ``createNamed()``, and form builder ``add()`` calls when
the form type is a static ``::class`` reference. Required options are identified
in completion and hover details.

After runtime indexing, definitely unknown literal options are diagnosed for
known form types. Dynamic option arrays and unresolved form types are ignored.

Validation
----------

Constraint and constraint-option completion is available in PHP attributes and
YAML validation mappings. PHP completion supports constraints imported
individually or through the ``Constraints`` namespace. Runtime indexing adds
installed Symfony constraints and their constructor options. Metadata for
available constraints remains active
when optional integrations aren't installed. Project constraint classes
extending ``Constraint`` are recognized from project files.

Definitely unknown options are diagnosed only when the constraint itself is
known. In PHP, the attribute must resolve to an installed constraint; unrelated
attributes that share its short name are ignored. Validation applies to
top-level named arguments; positional values and expressions nested inside
argument values are ignored. Go to Definition and Find All References connect
application constraint classes to static PHP and YAML usages.

Serializer Groups and Mapped Properties
---------------------------------------

Known serializer groups are completed in PHP ``Groups`` attributes, literal
``groups`` context arrays, and YAML mappings. Hover and Find All References
show their statically recognized occurrences.

Go to Definition connects YAML validation and serializer class and property
mappings to application PHP declarations. Property completion is available
under YAML ``properties`` and ``attributes`` mappings.

Unknown serializer groups, dynamic arrays and options that cannot be resolved
exactly aren't diagnosed.
