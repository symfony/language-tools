Twig Templates
==============

Completion, navigation and diagnostics for template names, template variables
and Twig components. Template names resolve through the loader paths of the
running application; see `how it works`_.

Where It Works
--------------

* Twig: ``{% extends %}``, ``{% include %}``, ``{% embed %}``,
  ``{% import %}``, ``{% from %}``, ``{% use %}``, and the ``include()`` and
  ``source()`` functions;
* PHP: ``render()`` and ``renderView()`` in a controller extending
  ``AbstractController`` or on a receiver typed ``Twig\Environment``, and the
  ``#[Template]`` attribute.

In the Editor
-------------

* completion for template names, including ``@Bundle`` namespaces;
* hover shows which file a template name resolves to;
* go to definition and find references for template names, and a template
  reference becomes a clickable link;
* a quick fix creates a missing template under ``templates/``;
* completion and hover for the variables a template receives: Twig globals,
  the keys passed to ``render()``, the names listed in ``#[Template]`` and the
  types declared with ``{% types %}``.

Twig Components
---------------

Component names and their properties are completed in ``<twig:Name>`` tags.
Hover, go to definition and find references connect a component tag to its
class and its template, a code lens above a component class opens its
template, and Live Component events are completed inside ``emit()``.

Diagnostics
-----------

Both need the running application:

* ``template.not_found``: the template name doesn't resolve to any loader
  path of the selected environment;
* ``twig_component.not_found``: no such Twig component. Needs
  ``symfony/ux-twig-component``.

Templates outside every loader path of the application aren't diagnosed, so
fixtures and unused template directories stay quiet.

Limitations
-----------

* without the running application, only names under ``templates/`` are known
  and no template diagnostics are reported;
* a template name built from a variable isn't resolved;
* ``component('...')`` calls are navigated and diagnosed but not completed;
* rename isn't available for template names.

.. _`how it works`: index.rst
