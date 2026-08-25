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
as ``@Admin/dashboard.html.twig`` are supported. A leading ``./`` is resolved
like the same loader-root name without that prefix.

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
including bundle-provided and anonymous components. Hover shows the component
class, template and public properties. Go to Definition opens the component
class or anonymous component template. For bundle components such as
``ux:icon``, it opens the vendor class. Find All References and code lenses
show statically recognized component usages.

Symfony Language Tools recognizes ``#[AsTwigComponent]`` and
``#[AsLiveComponent]`` classes, templates under ``templates/components/``,
``<twig:...>`` tags and static ``component()`` function calls. Live Component
properties and actions are included in completion and navigation. Unknown
static component names are reported only after all registered components,
including bundle components, are known.

Stimulus controllers and Live Component actions and events are documented in
`Stimulus and Live Components`_.

Diagnostics
-----------

A missing static template name is reported after the configured Twig loader
paths are known. Twig files outside those loader paths aren't diagnosed. Dynamic
template expressions, including concatenated names, are ignored.
Files owned by dependencies, such as bundle templates under ``vendor/``, are
never diagnosed. A quick fix creates missing application templates under
the ``templates/`` directory; namespaced ``@Bundle`` names are excluded.

Limitations
-----------

Custom non-filesystem loaders can limit completion and navigation. Theme engines
such as Sylius are supported through common application and bundle template
conventions.

.. _`Stimulus and Live Components`: stimulus.rst
