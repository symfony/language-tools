Docker Support
==============

Use this setup when your application runs in a Docker container and PHP isn't
installed on your machine. You don't need to install it: Symfony Language
Tools is a self-contained executable, and it boots your application inside
the container to index runtime metadata such as the effective routes and
services.

Configure Your Editor
---------------------

Set ``phpCommand`` to a non-interactive command that runs PHP in the
container, and ``containerProjectRoot`` to the project path inside it.

For a Docker Compose service named ``php`` that mounts the project at
``/app``, add this to ``.vscode/settings.json``:

.. code-block:: json

    {
        "symfonyLsp.phpCommand": [
            "docker", "compose", "exec", "-T", "php", "php"
        ],
        "symfonyLsp.containerProjectRoot": "/app"
    }

The same options exist in every client: see `Neovim`_, `Zed`_ and `OpenCode`_
for their configuration syntax.

Start the container before opening your project. The server writes a small
bridge script under ``var/symfony-lsp/``, runs it through ``phpCommand`` and
translates project paths in both directions, so navigation always targets
files on your machine.

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
* When ``vendor/`` lives in a container-only volume, runtime metadata is
  complete but the editor cannot open vendor files that don't exist on your
  machine.
* Any other isolated PHP command works the same way, for example in a virtual
  machine: set ``containerProjectRoot`` to the project path that this command
  sees, and share the project files between both sides.

.. _`Neovim`: editors/neovim.rst
.. _`Zed`: editors/zed.rst
.. _`OpenCode`: editors/opencode.rst
