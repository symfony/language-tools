Docker Support
==============

Symfony Language Tools runs on your machine as a self-contained executable, so
it doesn't require a local PHP installation. Features derived from project
files work without executing any PHP. Runtime metadata is different: the
server boots the application with the configured PHP command to read the
effective routes, services and other metadata, and that command must be able
to run your application.

When the application runs in Docker and PHP isn't installed on the host,
configure the server to boot the application inside the container:

* set ``phpCommand`` to a non-interactive command that runs PHP in the
  container;
* set ``containerProjectRoot`` to the absolute project path inside the
  container.

For a Docker Compose service named ``php`` that mounts the project at
``/app``, use these VS Code settings:

.. code-block:: json

    {
        "symfonyLsp.phpCommand": [
            "docker", "compose", "exec", "-T", "php", "php"
        ],
        "symfonyLsp.containerProjectRoot": "/app"
    }

The same options exist in every client: see `VS Code`_, `Neovim`_ and
`OpenCode`_ for their configuration syntax.

Requirements
------------

Docker support relies on a bind mount, the standard development setup:

* the project root on the host is mounted at ``containerProjectRoot`` in the
  container, so both sides see the same files;
* the container is running before runtime indexing starts;
* the command runs without a TTY: pass ``-T`` to ``docker compose exec`` and
  use ``docker exec -i`` without ``-t``.

The command starts from the project root on the host, so Docker Compose finds
the ``compose.yaml`` file stored there. For another layout, add ``-f`` or
``--project-directory`` to ``phpCommand``.

How It Works
------------

The server writes its project bridge under ``var/symfony-lsp/`` inside the
application, where the bind mount shares it with the container. It starts the
bridge through ``phpCommand`` and translates the bridge path and the project
root to container paths. Paths returned in runtime metadata are translated
back to host paths, so navigation always targets files on the host.

Everything else is unchanged: runtime indexing still requires a trusted
workspace and debug mode, and static features never execute PHP.

A virtual machine or any other isolated PHP command works the same way: set
``containerProjectRoot`` to the project path that this command sees, and share
the project files between both sides.

Limitations
-----------

When ``vendor/`` lives in a container-only volume instead of the bind mount,
the bridge still produces complete runtime metadata, but the editor cannot
open vendor files that don't exist on the host.

Troubleshooting
---------------

If the editor reports that runtime metadata could not be initialized, run the
configured command manually from the project root on the host:

.. code-block:: terminal

    $ docker compose exec -T php php -v

Then verify that:

* the container is running;
* Composer dependencies are installed inside the container;
* ``containerProjectRoot`` matches the container-side mount path exactly;
* the application boots in the configured environment inside the container.

.. _`VS Code`: editors/vscode.rst
.. _`Neovim`: editors/neovim.rst
.. _`OpenCode`: editors/opencode.rst
