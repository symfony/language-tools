Stimulus
========

Completion, navigation and diagnostics for Stimulus controllers, their
actions, targets, values, classes and outlets. The installed UX controllers
come from the running application; see `how it works`_.

Where It Works
--------------

* Twig markup: ``data-controller``, ``data-action`` and ``data-*-target``
  attributes;
* Twig helpers: ``stimulus_controller()``, ``stimulus_action()`` and
  ``stimulus_target()``;
* JavaScript and TypeScript: controllers under ``assets/``, named
  ``name_controller.js`` or ``name-controller.js``, and controllers passed to
  ``application.register()``.

In the Editor
-------------

* completion in Twig for controller names, actions, targets, values, classes
  and outlets;
* hover shows the controller file, whether it's lazy, whether it comes from a
  package, and its members;
* go to definition jumps between markup and the controller file, find
  references lists the usages, a controller name in a template becomes a
  clickable link, and a code lens above a controller class counts its usages;
* Live Component events are completed in ``emit()`` calls, and live actions
  are navigable from templates;
* a quick fix suggests close controller names in Twig.

Diagnostics
-----------

* ``stimulus.unknown_controller``: no such controller. Reported in Twig files
  only, and needs the running application with ``symfony/stimulus-bundle``
  installed.

Limitations
-----------

* completion is available in Twig files, not in HTML, PHP or JavaScript
  files;
* controllers declared outside ``assets/`` aren't found without the running
  application;
* rename isn't available.

.. _`how it works`: index.rst
