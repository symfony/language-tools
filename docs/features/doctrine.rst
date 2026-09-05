Doctrine Entities and Repositories
==================================

Symfony Language Tools provides navigation between Doctrine entities,
repositories and mapped fields. PHP attribute mappings work without executing
the application.

Entity Fields
-------------

Mapped field completion is available for literal criteria arrays passed to
``findBy()``, ``findOneBy()`` and ``count()``. Symfony Language Tools resolves
the entity from:

* a typed ``ServiceEntityRepository`` parameter or property;
* a call inside a mapped repository class;
* ``getRepository(Entity::class)`` chains and local assignments.

Typed repository parameters and local assignments remain available through
explicit closure captures and implicit arrow-function captures across nested
lexical scopes. Typed repository parameters are scoped to their declaring
method, so same-named parameters with unrelated types in other methods are
ignored. Calls on ``$this`` use the repository class containing the call,
including when one
file declares several repository classes. Doctrine attributes on promoted
constructor properties are recognized. When one Doctrine attribute maps several
properties declared in the same statement, each property is available.

For example, ``name`` and ``category`` are completed from the ``Product``
entity:

.. code-block:: php

    $products->findBy([
        'name' => 'Symfony',
        'category' => $category,
    ]);

Field completion is also available for the ``choice_label``, ``choice_value``
and ``group_by`` options of Symfony's Doctrine ``EntityType`` when the
``class`` option is a static ``::class`` reference. The complete entity passed
to ``getRepository()`` and the complete ``EntityType`` argument must be direct
static ``::class`` references, not parenthesized or computed expressions. PHP's
case-insensitive ``class`` keyword is recognized in any letter case. Those
arguments and the options must be positional.

Hover identifies regular fields and associations, including their PHP type and
target entity when known. Go to Definition opens the mapped property. Find All
References connects mapped properties to recognized repository criteria and
``EntityType`` options.

Repository Mappings
-------------------

Symfony Language Tools recognizes ``#[ORM\Entity]`` and
``#[ORM\MappedSuperclass]`` classes, ``#[ORM\Column]`` fields and Doctrine
association attributes. Repository classes are resolved from the entity's
``repositoryClass`` option and direct ``ServiceEntityRepository`` subclasses.

Go to Definition connects ``repositoryClass`` references to repository classes
and repository constructor entity references back to entity classes. Hover
shows entity and repository relationships. Entity and repository declarations
provide code lenses for navigating the relationship in either direction.

Other Mapping Formats
---------------------

With runtime indexing, XML and YAML ORM mappings and entities shipped by
dependencies under ``vendor/`` are also supported. Field completion, hover and
navigation work for these entities; Go to Definition opens the entity class when
no more precise mapping location is available.

Limitations
-----------

DQL strings, Query Builder field expressions and dynamic repository lookups
aren't interpreted. String callbacks used as ``EntityType`` choice labels
aren't treated as mapped fields. Without runtime indexing, inherited fields and
repositories bound through interfaces may be incomplete. Unknown Doctrine
fields aren't diagnosed because Doctrine supports custom mappings.
