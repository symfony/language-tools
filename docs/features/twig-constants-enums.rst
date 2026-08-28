PHP Constants and Enums in Twig
===============================

Symfony Language Tools connects PHP class constants and enums in application
source files to their static usages in Twig templates. These features don't
require runtime indexing or workspace trust.

Supported Syntax
----------------

Class constants and enum cases are recognized through Twig's ``constant()``
function. Enum classes and cases are also recognized through ``enum()`` and
``enum_cases()``::

    {# templates/article/show.html.twig #}
    {{ constant('App\\View\\ArticleView::DATE_FORMAT') }}
    {{ constant('App\\Model\\PublicationStatus::Published') }}
    {{ enum('App\\Model\\PublicationStatus').Published }}

    {% for status in enum_cases('App\\Model\\PublicationStatus') %}
        {{ status.value }}
    {% endfor %}

Completion
----------

Inside ``constant()``, completion suggests application classes, interfaces and
enums that declare public constants or enum cases. After ``::``, it suggests
public constants and enum cases declared by the selected type.

Inside ``enum()`` and ``enum_cases()``, completion suggests application enum
classes. After an ``enum()`` call, completion suggests the enum's cases.

Hover and Definition
--------------------

Hover shows the PHP declaration signature and its documentation when available.
Go to Definition opens the PHP type, class constant or enum case declaration
selected by the cursor.

References
----------

Find All References lists recognized usages across application Twig templates.
You can request references from a PHP type, class constant or enum case
declaration, or from the corresponding class or member name in Twig.

Limitations
-----------

Only statically named application types, constants and enum cases are
recognized. Dynamic expressions and dependency-owned declarations are ignored.
Completion includes directly declared public members; inherited and imported
constants aren't expanded.
