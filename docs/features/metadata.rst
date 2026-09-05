Forms, Validation and Serializer Metadata
=========================================

Symfony Language Tools understands form options, validation constraints,
serializer groups and project mappings.

Forms
-----

Form option completion and hover are available in literal option arrays passed
to ``createForm()``, ``createNamed()``, and form builder ``add()`` calls when
the whole form type argument is a static ``::class`` reference. The form type
and options must be passed positionally. Required options are identified in
completion and hover details.

After runtime indexing, definitely unknown literal options are diagnosed for
known form types. Dynamic option arrays and unresolved form types are ignored.
A type argument that concatenates or computes a class name is dynamic, even
when it starts with a ``::class`` reference. Quoted strings in nested option
values can contain closing brackets without hiding later options.

For form types that configure a static ``data_class`` with ``setDefaults()``
or ``setDefault()``, literal field names passed to
``FormBuilderInterface::add()`` are linked to the corresponding class property.
Property completion is available while entering the field name and is scoped to
the method that declares the typed form builder. Hover shows the PHP property
signature and description. Go to Definition opens the declaration.
Find All References includes the form field and mapped validation or serializer
metadata.

Literal ``property_path`` options are followed when they contain one property
name. Unmapped fields, dynamic option arrays, inherited or dynamic
``data_class`` values and nested property paths are ignored. Completion and
navigation require the property to be declared directly on the data class;
inherited and trait properties aren't resolved.

Validation
----------

Constraint and constraint-option completion is available in PHP attributes and
YAML validation mappings. PHP completion supports constraints imported
individually, with aliases or through the ``Constraints`` namespace. Direct
imports only suggest matching imported constraints. Runtime indexing adds
installed Symfony constraints and their constructor options. Metadata for
available constraints remains active
when optional integrations aren't installed. Project constraint classes
extending ``Constraint`` are recognized from project files.

Definitely unknown options are diagnosed only when the constraint itself is
known. In PHP, the attribute must resolve to an installed constraint; unrelated
attributes that share its short name are ignored. Validation applies to
top-level named arguments; positional values and expressions nested inside
argument values are ignored. Every constraint in a grouped PHP attribute is
indexed. Go to Definition and Find All References connect application constraint
classes to static PHP and YAML usages.

Serializer Groups and Mapped Properties
---------------------------------------

Known serializer groups are completed in resolved PHP ``Groups`` attributes,
including imported aliases and fully qualified names, in literal ``groups``
context arrays and in YAML mappings. Unrelated attributes that share the
``Groups`` short name are ignored. Hover and Find All References show their
statically recognized occurrences.

Go to Definition connects YAML validation and serializer class and property
mappings to application PHP declarations. Hover shows the PHP property signature
and description. Property completion is available under YAML ``properties`` and
``attributes`` mappings.

Unknown serializer groups, dynamic arrays and options that cannot be resolved
exactly aren't diagnosed.
