Twig Templates and Components
=============================

Symfony Language Tools understands template names resolved through Twig
filesystem loader paths. It complements generic Twig syntax support from the
editor.

Completion
----------

Template completion is available in PHP ``render()`` and ``renderView()`` calls
and in these Twig contexts:

* ``extends``;
* ``include``;
* ``embed``;
* ``import`` and ``from``;
* ``use``;
* the ``include()`` and ``source()`` functions.

Both regular names such as ``article/show.html.twig`` and namespaced names such
as ``@Admin/dashboard.html.twig`` are supported.

Navigation and Links
--------------------

Hover shows the resolved template file. Go to Definition and document links open
the resolved file. Find All References lists statically recognized PHP and Twig
references.

Variables
---------

Variable completion and hover are available in Twig templates for Twig globals
and literal keys passed by PHP ``render()`` and ``renderView()`` calls. Twig
component templates also expose public component properties.

Variables declared by Twig's ``types`` tag are completed with their declared
type and required or optional status. Documentation comments attached to type
declarations are included in completion details and hover.

Symfony Language Tools doesn't infer values propagated through dynamic arrays,
includes, inheritance or arbitrary PHP expressions.

Twig Components
---------------

Component names and public properties are completed in ``<twig:...>`` tags,
including bundle-provided and anonymous component names found in runtime
metadata.
Hover shows the component class, template, and public properties. Go to
Definition opens the component class and anonymous component template. Find All
References and code lenses expose statically recognized component usages.

Symfony Language Tools recognizes ``#[AsTwigComponent]`` and
``#[AsLiveComponent]`` classes, templates under ``templates/components/``,
``<twig:...>`` tags and static ``component()`` function calls. Live Component
properties and actions are included in component metadata. Unknown static
component names are reported once project files have been analyzed and runtime
metadata lists the registered component names, so components provided by
bundles are recognized.

Stimulus controllers and Live Component actions and events are documented in
`Stimulus and Live Components`_.

Diagnostics
-----------

A missing static template name is reported after the configured filesystem
loader paths have been indexed. Dynamic template expressions are ignored.
Files owned by dependencies, such as bundle templates under ``vendor/``, are
never diagnosed.

Limitations
-----------

Custom non-filesystem loaders cannot provide exhaustive completion. Their
literal names are available only when another recognized project file exposes
them.

.. _`Stimulus and Live Components`: stimulus.rst
