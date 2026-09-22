Docker Support
==============

Use this setup when your application runs in a Docker container and PHP isn't
installed on your machine. You don't need to install it: Symfony Language
Tools is a self-contained executable, and it boots your application inside
the container to load application information such as routes and services.

Configure the PHP Command
-------------------------

Set ``phpCommand`` to a non-interactive command that runs PHP in the
container, and ``containerProjectRoot`` to the project path inside it.

For a Docker Compose service named ``php`` that mounts the project at
``/app``, add this to ``.symfony-lsp.json``:

.. code-block:: json

    {
        "version": 1,
        "phpCommand": [
            "docker", "compose", "exec", "-T", "php", "php"
        ],
        "containerProjectRoot": "/app"
    }

Start the container before opening your project. The container must mount the
project at ``containerProjectRoot``: paths are translated between both sides
by swapping that prefix.

Raise ``bridgeTimeout`` when the application needs more than 300 seconds to
boot in the container.

If It Doesn't Work
------------------

Run the configured command manually from the project root:

.. code-block:: terminal

    $ docker compose exec -T php php -v

Then check that:

* the container is running and mounts the project root at
  ``containerProjectRoot``;
* Composer dependencies are installed inside the container;
* the command doesn't allocate a TTY: pass ``-T`` to ``docker compose exec``
  and use ``docker exec -i`` without ``-t``;
* ``compose.yaml`` is discoverable from the project root; add ``-f`` or
  ``--project-directory`` to ``phpCommand`` otherwise;
* the application boots in the configured environment inside the container.

Good to Know
------------

* running the application still requires debug mode, and your editor still
  asks for workspace trust. ``symfony-lsp check`` has no such prompt and runs
  the application unless you pass ``--source-only``;
* when ``vendor/`` lives only in the container, Symfony features work but your
  editor can't open vendor files that don't exist on your machine;
* any other isolated PHP command works the same way, in a virtual machine for
  example: set ``containerProjectRoot`` to the project path that command sees
  and share the project files between both sides.
