Translations
============

Symfony LSP combines effective runtime catalogues with application-owned
translation resources.

Completion
----------

Translation key completion is available in recognized PHP ``trans()`` calls,
``TranslatableMessage`` objects, the ``t()`` helper and Twig's ``trans`` filter.
Suggestions are scoped to the selected domain. Domain, locale and message
placeholder completion are available in statically recognized call arguments.

Resources
---------

Definitions are indexed from YAML, JSON, XLIFF and PHP resources under a
``translations/`` directory. Nested YAML and JSON keys use dot notation.
Unsaved resource changes overlay the disk-backed index.

Hover and Navigation
--------------------

Hover shows the key, domain, available locales and message from the selected
development catalogue. Go to Definition navigates to source resources. Find All
References and rename cover statically recognized PHP and Twig keys.

Diagnostics
-----------

Missing and unexpected placeholders are reported when the call provides a
static parameter map. Missing-key diagnostics are disabled by default because
external translation providers can make the runtime catalogue incomplete.

Enable missing-key diagnostics in a project or workspace-folder setting:

.. code-block:: json

    {
        "symfonyLsp.translationDiagnostics": true
    }

The setting is resource-scoped, so each folder in a multi-root workspace can
choose independently. Runtime catalogue messages are kept inside workspace
snapshots and aren't written to logs or telemetry.
