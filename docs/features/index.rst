Symfony Integrations
====================

Symfony Language Tools knows two things about your project: what your files
say, and what your application answers when it runs. Features that only need
your files always work. Features that need effective routes, services,
templates or configuration need the application to run.

Supported Integrations
----------------------

.. list-table::
    :header-rows: 1

    * - Integration
      - Completion
      - Hover
      - Definition
      - References
      - Rename
      - Diagnostics
    * - `Routes`_
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - `Services and parameters`_
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - `Translations`_
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
      - Yes
    * - `Environment variables`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Bundle configuration`_
      - Yes
      - Yes
      - No
      - No
      - No
      - Yes
    * - `Console commands`_
      - Yes
      - No
      - No
      - No
      - No
      - Yes
    * - `Messenger`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Events`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Security`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Forms, validation and serializer`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Doctrine`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - No
    * - `Assets`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Stimulus`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Twig templates`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `Twig functions and filters`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - Yes
    * - `PHP constants and enums in Twig`_
      - Yes
      - Yes
      - Yes
      - Yes
      - No
      - No

Messenger, events, Doctrine, Stimulus and Twig components also provide code
lenses that navigate between related classes.

Running Your Application
------------------------

To know the routes, services, templates and configuration your application
really has, Symfony Language Tools boots it with your PHP command and reads
its metadata, the way ``debug:router`` does. This executes your project's
code, so it only happens in a workspace you trust and in debug mode.

Without it, features backed by your files keep working: navigation,
references, rename and the diagnostics listed as working without the running
application. Everything else is silently unavailable rather than wrong. Turn
it off with ``runtimeIndexing`` in `project configuration`_, or with
``--source-only`` on the `command line`_.

The application is booted in one environment at a time, ``dev`` by default,
and applications that ship several kernels analyze one of them at a time.
Symfony versions from the oldest maintained branch to the next development
branch are supported; on an older or newer branch the application isn't
booted and only file-based features remain.

Names are checked against the environment you analyze, so an unknown name is
only reported in the files that environment loads. A service, a Security
provider, a Messenger transport or an environment variable processor named in
a file another environment owns, such as ``config/packages/test/`` or
``config/services_test.yaml``, is left alone while you analyze ``dev``: select
that environment to check those files. Configuration keys and values are the
exception: they're checked in every ``when@`` block.

Which Files Are Analyzed
------------------------

Everything your project owns. Symfony Language Tools never looks at the
directory Composer installs into, ``node_modules/``, ``.git/``, its own cache
in ``var/symfony-lsp/`` or any path your ``.gitignore`` excludes, which is
how generated directories such as ``var/`` and ``assets/vendor/`` stay out. A
directory that only shares one of those names, such as ``templates/vendor/``,
is analyzed normally.

Add ``excludePaths`` in `project configuration`_ for embedded fixtures or
generated sources that aren't ignored.

Values are never read: dotenv files contribute variable names, and parameter
values, secrets and application objects never appear in hovers, diagnostics,
caches or logs.

.. _`project configuration`: ../configuration.rst
.. _`command line`: ../check.rst
.. _`Routes`: routing.rst
.. _`Services and parameters`: dependency-injection.rst
.. _`Console commands`: console.rst
.. _`Translations`: translations.rst
.. _`Environment variables`: environment.rst
.. _`Bundle configuration`: configuration.rst
.. _`Messenger`: messenger.rst
.. _`Events`: events.rst
.. _`Security`: security.rst
.. _`Forms, validation and serializer`: metadata.rst
.. _`Doctrine`: doctrine.rst
.. _`Assets`: assets.rst
.. _`Stimulus`: stimulus.rst
.. _`Twig templates`: templates.rst
.. _`Twig functions and filters`: twig-callables.rst
.. _`PHP constants and enums in Twig`: twig-constants-enums.rst
