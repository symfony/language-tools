Twig Template Names
===================

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
