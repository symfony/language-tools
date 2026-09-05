Translations
============

Symfony Language Tools combines the selected environment's translation
catalogue with translation resources from the project.

Completion
----------

Translation key completion is available in recognized PHP ``trans()`` calls,
``TranslatableMessage`` objects, the ``t()`` helper and Twig's ``trans`` filter.
The ``t()`` helper is recognized when it resolves to Symfony's translation
function through a function import, an alias or a fully qualified call; an
unimported bare ``t()`` call is ignored. Imported, aliased and fully qualified
``t()`` calls honor literal ``domain`` and ``parameters`` arguments the same
way ``trans()`` calls do. Suggestions are scoped to the selected domain.
Literal PHP keys are recognized when they are the first argument, either
positionally or as ``id:`` for ``trans()`` and ``message:`` for ``t()`` and
``TranslatableMessage``. Literal PHP domains are recognized in positional and
named arguments, including after dynamic parameter expressions. Literal Twig
keys follow Twig's string escape rules in completion and navigation. Literal
Twig domains are recognized in positional and named ``trans`` filter
arguments. Domain, locale and message placeholder completion are available in
statically recognized call arguments.

Resources
---------

Definitions are read from YAML, JSON, XLIFF and PHP resources under a
``translations/`` directory. Nested YAML and JSON keys use dot notation. YAML
messages follow YAML quoting, escape, folded block and literal block semantics.
PHP messages can use quoted strings, heredocs or nowdocs. INI catalogs using a
locale directory, such as ``Translations/en_US/messages.ini``, are recognized
too; their messages can be quoted or unquoted, and comment lines and trailing
``;`` comments are ignored. Escaped quotes and backslashes in quoted messages
are decoded. Unsaved resource changes are available immediately, and changes
made by external tools are picked up while the server is running.

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

Placeholders the message expects but a supplied literal parameter map doesn't
provide are reported. Extra parameters and literal global parameters registered
with ``addGlobalParameter()`` are accepted. ICU parameter names may be bare,
such as ``name``, or brace-wrapped, such as ``{name}``. If a global parameter
name is dynamic, placeholder diagnostics are suppressed because the available
names can't be determined. Calls without a parameter map, with dynamic
expressions or with unpacked parameter arrays aren't diagnosed. Missing-key
diagnostics are disabled by default because external translation providers can
make the runtime catalogue incomplete.

Enable missing-key diagnostics in ``.symfony-lsp.json``:

.. code-block:: json

    {
        "version": 1,
        "translationDiagnostics": true
    }

A quick fix on a missing-key diagnostic adds the key to an existing YAML
catalog for the selected domain under ``translations/``.

Project-specific overrides can enable the setting independently for each
application in a multi-project workspace. See the `project configuration`_.

.. _`project configuration`: ../project-configuration.rst
