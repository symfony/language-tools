Doctrine Entities and Repositories
==================================

Symfony LSP provides source-backed navigation between Doctrine entities,
repositories and mapped fields. Doctrine packages don't need to be installed in
the language server itself.

Entity Fields
-------------

Mapped field completion is available for literal criteria arrays passed to
``findBy()``, ``findOneBy()`` and ``count()``. Symfony LSP resolves the entity
from:

* a typed ``ServiceEntityRepository`` parameter or property;
* a call inside a mapped repository class;
* ``getRepository(Entity::class)`` chains and local assignments.

For example, ``name`` and ``category`` are completed from the ``Product``
entity:

.. code-block:: php

    $products->findBy([
        'name' => 'Symfony',
        'category' => $category,
    ]);

Field completion is also available for the ``choice_label``, ``choice_value``
and ``group_by`` options of Symfony's Doctrine ``EntityType`` when the
``class`` option is a static ``::class`` reference.

Hover identifies regular fields and associations, including their PHP type and
target entity when known. Go to Definition opens the mapped property. Find All
References connects mapped properties to recognized repository criteria and
``EntityType`` options.

Repository Mappings
-------------------

Symfony LSP indexes ``#[ORM\Entity]`` and ``#[ORM\MappedSuperclass]`` classes,
``#[ORM\Column]`` fields and Doctrine association attributes. Repository
classes are resolved from the entity's ``repositoryClass`` option and direct
``ServiceEntityRepository`` subclasses.

Go to Definition connects ``repositoryClass`` references to repository classes
and repository constructor entity references back to entity classes. Hover
shows entity and repository relationships. Entity and repository declarations
provide code lenses for navigating the relationship in either direction.

Runtime and Source Indexes
--------------------------

Doctrine support is source-only and remains available without workspace trust.
PHP source facts participate in the same persistent index and unsaved-document
overlays as other Symfony integrations. Saving an entity, repository or
consumer updates only that source entry.

Current Limitations
-------------------

Only PHP attribute mappings and direct ``ServiceEntityRepository`` subclasses
are indexed. XML and YAML ORM mappings, inherited fields, DQL strings, Query
Builder field expressions and dynamic repository lookups aren't interpreted.
String callbacks used as ``EntityType`` choice labels aren't treated as mapped
fields. Symfony LSP doesn't diagnose unknown Doctrine fields because custom
mapping drivers and inherited metadata can extend the source model.
