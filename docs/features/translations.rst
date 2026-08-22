Translations
============

Symfony Language Tools combines effective runtime catalogues with
application-owned translation resources.

Completion
----------

Translation key completion is available in recognized PHP ``trans()`` calls,
``TranslatableMessage`` objects, the ``t()`` helper and Twig's ``trans`` filter.
Suggestions are scoped to the selected domain. Domain, locale and message
placeholder completion are available in statically recognized call arguments.

Resources
---------

Definitions are read from YAML, JSON, XLIFF and PHP resources under a
``translations/`` directory. Nested YAML and JSON keys use dot notation.
INI catalogs using a locale directory, such as
``Translations/en_US/messages.ini`` in Mautic, are recognized too. Unsaved
resource changes are available immediately.

ICU brace placeholders such as ``{name}`` are only interpreted in ICU
catalogs, identified by the ``+intl-icu`` domain suffix. In plain catalogs,
braces are literal text and only ``%name%`` placeholders are interpreted.

Hover and Navigation
--------------------

Hover shows the key, domain, available locales and message from the selected
development catalogue. Go to Definition navigates to source resources. Find All
References and rename cover statically recognized PHP and Twig keys.

Diagnostics
-----------

Placeholders the message expects but a literal parameter map doesn't
provide are reported. Extra parameters are accepted, and calls passing
dynamic parameter expressions aren't diagnosed. Missing-key diagnostics
are disabled by default because external translation providers can make
the runtime catalogue incomplete.

Enable missing-key diagnostics in a project or workspace-folder setting:

.. code-block:: json

    {
        "symfonyLsp.translationDiagnostics": true
    }

The setting is resource-scoped, so each folder in a multi-root workspace can
choose independently. Runtime catalogue messages aren't written to logs or
telemetry.
