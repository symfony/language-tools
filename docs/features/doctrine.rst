Doctrine
========

Completion, hover and navigation for entities, their fields and their
repositories. Attribute mappings are read from your code; the running
application adds the rest.

Where It Works
--------------

* the ``choice_label``, ``choice_value`` and ``group_by`` options of an
  ``EntityType``, when its ``class`` option is a ``::class`` reference;
* the criteria keys of ``findBy()``, ``findOneBy()`` and ``count()``, on
  ``$this`` in a repository, on a typed repository property or variable, and
  on a ``getRepository(Article::class)`` call;
* ``#[ORM\Entity]``, ``#[ORM\Column]``, association attributes and the
  ``repositoryClass`` argument.

In the Editor
-------------

* completion for field and association names, annotated with their Doctrine
  type;
* hover shows the type of a field, the target entity of an association, the
  repository and field count of an entity, and the entity of a repository;
* go to definition and find references between entities, fields and
  repositories;
* a code lens above an entity opens its repository, and a code lens above a
  repository opens its entity.

Diagnostics
-----------

None. An unknown field name isn't reported, because criteria can be resolved
in ways this tool can't observe.

Limitations
-----------

* without the running application, only mappings declared with PHP attributes
  are known. XML and YAML mappings, and entities from installed packages, need
  runtime analysis;
* go to definition on a field that isn't mapped with attributes opens the
  class file at its first line instead of the property;
* rename isn't available.
