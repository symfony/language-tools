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

Start the container before opening your project. Symfony Language Tools uses
``phpCommand`` to run the application and maps container paths back to files on
your machine.

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

* Runtime indexing still requires a trusted workspace and debug mode, and
  static features never execute PHP.
* When ``vendor/`` lives only in the container, Symfony features work but the
  editor cannot open vendor files that don't exist on your machine.
* Any other isolated PHP command works the same way, for example in a virtual
  machine: set ``containerProjectRoot`` to the project path that this command
  sees, and share the project files between both sides.
