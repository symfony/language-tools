Translations
============

Completion, navigation and diagnostics for translation keys and domains.

Where It Works
--------------

* PHP: ``trans()`` on a translator, ``new TranslatableMessage()`` and the
  ``t()`` helper;
* Twig: the ``trans`` filter, the ``trans()`` and ``t()`` functions and
  ``{% trans %}`` blocks, with ``{% trans_default_domain %}`` taken into
  account;
* catalogs under ``translations/``, named ``<domain>.<locale>.<extension>``
  with the ``yaml``, ``yml``, ``json``, ``xlf``, ``xliff`` and ``php``
  extensions, plus ``translations/<locale>/<domain>.ini``.

In the Editor
-------------

* completion for keys and domains;
* hover shows the domain, the locales that define the key and the message;
* go to definition opens the catalog entry, and find references lists the
  usages of a key;
* rename updates a key and its usages;
* a quick fix adds a missing key to the catalog of its domain.

Diagnostics
-----------

* ``translation.placeholders``: the parameters passed to a translation don't
  match the placeholders in the message. Works without the running
  application;
* ``translation.not_found`` and ``translation.domain_not_found``: the key or
  the domain doesn't exist. Both are off by default, because catalogs can be
  loaded from a translation provider the tool can't see. Enable
  ``translationDiagnostics`` in `project configuration`_, or pass
  ``--translation-diagnostics`` to a `command-line check`_.

Limitations
-----------

* a call whose domain isn't a literal string is ignored;
* rename changes the last segment of a dotted key, not its prefix, so
  ``form.label.name`` can become ``form.label.title`` but not
  ``form.title.name``;
* the quick fix appends to a YAML catalog, and only when the domain has
  exactly one YAML catalog directly under ``translations/``.

.. _`project configuration`: ../configuration.rst
.. _`command-line check`: ../check.rst
