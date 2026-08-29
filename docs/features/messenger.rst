Messenger Integration
=====================

The Messenger integration understands buses, transports, routed message
classes and handlers in the configured Symfony environment, together with PHP
and YAML declarations.

Completion
----------

Bus name completion is available in recognized ``bus`` and ``default_bus``
options and in ``BusNameStamp`` arguments. Transport completion is available in
``fromTransport``, ``from_transport`` and ``failure_transport`` options. YAML
routing entries also complete transport names:

.. code-block:: yaml

    # config/packages/messenger.yaml
    framework:
        messenger:
            default_bus: command.bus
            failure_transport: failed
            routing:
                App\Message\GenerateReport: asy

The suggestions use bus and transport names from the configured application.

Hover
-----

Hover over a recognized bus to display whether it is the default bus and how
many message classes it handles. Transport hover displays whether it is the
failure transport and how many message classes are routed to it.

Hover over a message or handler class to display its transports,
handlers and buses.

Definition and References
-------------------------

Definition requests on YAML bus and transport names navigate to their
application-owned declarations. References include recognized configuration
options, handler attributes and routing entries.

The server recognizes message classes in these PHP contexts:

* ``$bus->dispatch(new Message())``;
* ``new Envelope(new Message())``;
* the ``handles`` argument of ``#[AsMessageHandler]``.

Imported aliases of ``AsMessageHandler`` are recognized. Typed parameters are
matched only inside their declaring method. Typed properties, including
promoted properties, remain available across methods.

From a dispatch site, definition results include the message class and its
registered handlers. From a message class, definition navigates to handlers and
references include recognized dispatch sites. Handler classes navigate back to
the message classes they handle. Handler relationships inherited through
application-owned parent classes and interfaces are included. Commented-out PHP
attributes and calls are ignored.

Code Lenses
-----------

Message classes display the number of handlers above the class name. Handler
classes display the number of message classes they handle. Selecting a code lens
opens the related locations.

Diagnostics
-----------

After runtime indexing completes, unknown bus and transport references are
reported as errors. PHP handlers with a scalar first parameter are reported when
Messenger assigns an object message to that method. These diagnostics are
suppressed when runtime indexing is unavailable or incomplete.

Limitations
-----------

Internal framework messages and handlers can appear when Symfony registers them
on an application bus.
