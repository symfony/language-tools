Doctrine Entities and Repositories
==================================

Symfony Language Tools provides navigation between Doctrine entities,
repositories and mapped fields without executing the application. Doctrine
packages don't need to be installed in the language server itself.

Entity Fields
-------------

Mapped field completion is available for literal criteria arrays passed to
``findBy()``, ``findOneBy()`` and ``count()``. Symfony Language Tools resolves
the entity from:

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

Symfony Language Tools recognizes ``#[ORM\Entity]`` and
``#[ORM\MappedSuperclass]`` classes, ``#[ORM\Column]`` fields and Doctrine
association attributes. Repository classes are resolved from the entity's
``repositoryClass`` option and direct ``ServiceEntityRepository`` subclasses.

Go to Definition connects ``repositoryClass`` references to repository classes
and repository constructor entity references back to entity classes. Hover
shows entity and repository relationships. Entity and repository declarations
provide code lenses for navigating the relationship in either direction.

Runtime Metadata
----------------

Effective entity metadata is also collected from the application's Doctrine
metadata factory. This covers mappings that source scanning can't see, such
as XML and YAML ORM mappings and entities shipped by dependencies under
``vendor/``. Field completion, hover and navigation work for these entities
too; Go to Definition opens the entity class file when no precise source
declaration is available.

Current Limitations
-------------------

Inherited fields, DQL strings, Query Builder field expressions and dynamic
repository lookups aren't interpreted. String callbacks used as
``EntityType`` choice labels aren't treated as mapped fields. Repositories
bound to entities through interfaces, as in Sylius resource
configuration, aren't resolved. Symfony Language Tools doesn't diagnose
unknown Doctrine fields because custom mapping drivers and inherited
metadata can extend the source model.
