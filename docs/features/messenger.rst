Messenger
=========

Completion, navigation and diagnostics for buses and transports, and
navigation between messages and their handlers. Needs the running
application; see `how it works`_.

Where It Works
--------------

* YAML: bus and transport declarations under ``framework.messenger``,
  ``default_bus``, ``failure_transport``, the ``routing`` map, and the
  ``bus`` and ``from_transport`` attributes of a service tag;
* PHP: the ``bus:``, ``fromTransport:`` and ``handles:`` arguments of
  ``#[AsMessageHandler]``, ``new BusNameStamp('...')``, ``$bus->dispatch(new
  Message())`` and ``new Envelope(new Message())``.

In the Editor
-------------

* completion for bus and transport names;
* hover shows, for a bus, whether it's the default one and how many messages
  it handles; for a transport, whether it's the failure transport and how many
  messages are routed to it; for a message, its transports and handlers; for a
  handler, its messages and buses;
* go to definition and find references between messages, handlers, buses and
  transports;
* a code lens above a message class lists its handler classes, and a code lens
  above a handler class lists the message classes it handles.

Diagnostics
-----------

* ``messenger.unknown_bus``: no such bus in the selected environment;
* ``messenger.unknown_transport``: no such transport in the selected
  environment;
* ``messenger.invalid_handler_signature``: the handler can't accept the
  message class it's registered for.

Declarations that belong to another environment, in ``when@test`` or
``config/packages/test/``, aren't reported when you analyze ``dev``.

Limitations
-----------

* a dispatch is recognized when the message is created directly in the call,
  not when it's passed through a variable;
* the handler signature check applies to handlers whose first parameter is
  typed with scalar types only;
* rename isn't available for bus and transport names.

.. _`how it works`: index.rst
