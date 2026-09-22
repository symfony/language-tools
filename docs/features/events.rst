Events
======

Completion, navigation and diagnostics for event names, listeners and
subscribers. Listeners come from the running application; see
`how it works`_.

Where It Works
--------------

* PHP: the ``event:`` argument of ``#[AsEventListener]``,
  ``$dispatcher->dispatch(new OrderPlaced())``, ``$dispatcher->addListener()``
  and the array returned by ``getSubscribedEvents()``;
* YAML: the ``event`` attribute of a ``kernel.event_listener`` tag.

In the Editor
-------------

* completion for event names;
* hover over an event shows its class and its listeners in priority order;
  hover over a listener class shows the events it listens to;
* go to definition and find references between events and their listeners;
* a code lens above an event class lists its listener classes, and a code lens
  above a listener or subscriber class lists the event classes it handles.

Diagnostics
-----------

* ``event.invalid_listener_method``: the method configured on
  ``#[AsEventListener]`` doesn't exist in the class. This one works without
  the running application.

Limitations
-----------

* an event that no listener subscribes to is unknown to completion, hover and
  code lenses;
* the method check is skipped when the listener class extends another class
  or uses a trait, since the method may come from there;
* rename isn't available for event names.

.. _`how it works`: index.rst
