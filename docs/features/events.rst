Events and Listeners
====================

The Events integration understands event classes, legacy event names, listeners,
subscribers, priorities and recognized dispatch sites in the configured Symfony
environment.

Completion
----------

Event-name completion is available in resolved ``AsEventListener`` attributes,
including imported aliases and fully qualified names. It is also available in
``EventSubscriberInterface::getSubscribedEvents()`` arrays, event-listener
service tags and calls on statically typed event dispatchers. Unrelated
attributes that share the ``AsEventListener`` short name are ignored. PHP class
completion remains the responsibility of the general PHP language server.

Hover
-----

Hover over a recognized event reference or event class to display its class and
ordered listeners. Hover over a listener or subscriber class to display the
events it handles.

Definition and References
-------------------------

Definition requests from dispatch sites and static event-name references
navigate to the event class and registered listener classes. Event classes
navigate to their listeners, while listener classes navigate back to event
classes. References include recognized dispatch calls, listener attributes,
subscriber declarations and service tags.

The server recognizes event references in these contexts:

* ``$dispatcher->dispatch(new Event())`` when the dispatcher type is known;
* the optional static event name passed to ``dispatch()``;
* static event names passed to ``addListener()``;
* ``AsEventListener`` attributes;
* ``EventSubscriberInterface::getSubscribedEvents()`` return values;
* ``kernel.event_listener`` service tags.

Typed parameters are matched only inside their declaring method. Typed
properties, including promoted properties, remain available across methods.
Quoted listener method names can contain braces and escaped quotes.
Commented-out PHP attributes and calls are ignored.

Code Lenses
-----------

Event classes display the number of listeners above the class name. Listener
and subscriber classes display the number of events they handle. Selecting a
code lens opens the related locations.

Diagnostics
-----------

A class-level ``AsEventListener`` attribute that explicitly names a method is
reported when that method doesn't exist in the class. Unknown event names aren't
diagnosed because the event dispatcher accepts events without registered
listeners.
