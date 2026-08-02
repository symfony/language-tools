Twig Templates and Components
=============================

Symfony LSP understands template names resolved through Twig filesystem loader
paths. It complements generic Twig syntax support from the editor.

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

This first implementation deliberately avoids inferring values propagated
through dynamic arrays, includes, inheritance, or arbitrary PHP expressions.

Twig Components
---------------

Component names and public properties are completed in ``<twig:...>`` tags.
Hover shows the component class, template, and public properties. Go to
Definition opens the component class and anonymous component template. Find All
References and code lenses expose statically recognized component usages.

Symfony LSP indexes ``#[AsTwigComponent]`` classes, templates under
``templates/components/``, ``<twig:...>`` tags, and static ``component()``
function calls. Unknown static component names are reported after source
indexing finishes.

Diagnostics
-----------

A missing static template name is reported after the configured filesystem
loader paths have been indexed. Dynamic template expressions are ignored.

Runtime and Source Indexes
--------------------------

Runtime indexing uses ``debug:twig --format=json`` to discover Twig namespaces
and filesystem loader paths. The source index enumerates application templates
under ``templates/`` and immediately overlays unsaved Twig documents.

Custom non-filesystem loaders cannot provide exhaustive completion. Their
literal names are therefore only available when another indexed source exposes
them.
