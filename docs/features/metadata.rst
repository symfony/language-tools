Forms, Validation and Serializer
================================

Completion, hover and diagnostics for form options, validation constraints and
serializer groups. Form types and constraint options come from the running
application; see `how it works`_.

Where It Works
--------------

* form options: the options array of ``createForm()``, ``createNamed()`` and
  ``$builder->add()``;
* validation constraints: constraint attributes such as ``#[Assert\Length]``,
  and YAML validation mapping under a class's ``properties``;
* serializer groups: ``#[Groups]`` and ``#[Context]`` arguments, ``groups``
  entries passed to the serializer or to a controller's ``json()`` call, and
  ``groups`` keys in YAML serializer mapping.

In the Editor
-------------

* completion for form type options, for the fields of the form's data class in
  ``$builder->add()``, for constraint names inside an unfinished attribute and
  for constraint options;
* hover shows the type of a form option and whether it's required, the
  constraint an option belongs to, and how many times a serializer group is
  used;
* go to definition and find references for constraints, mapped classes and
  serializer groups;
* a quick fix suggests close option names for the same form type or constraint.

Diagnostics
-----------

Both need the running application:

* ``form.unknown_option``: the form type doesn't accept this option, including
  the options it inherits from its parent types;
* ``validation.unknown_constraint_option``: the constraint doesn't accept this
  option.

Limitations
-----------

* option completion and option diagnostics cover the constraints shipped by
  the Symfony Validator. Constraints from other packages or from your own code
  are completed by name and navigable, but their options aren't checked;
* an option added by a form type extension registered only in another
  environment is reported as unknown; select that environment to check it;
* only named arguments are checked, so ``#[Assert\Length(10)]`` is left alone;
* field completion in ``$builder->add()`` needs the form type to declare a
  ``data_class`` as a static value;
* rename isn't available.

.. _`how it works`: index.rst
