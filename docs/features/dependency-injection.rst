Dependency Injection: Services and Parameters
=============================================

The dependency injection integration understands effective services, aliases,
autowiring types and parameter names from the compiled Symfony container. A
static index adds precise locations for application-owned YAML declarations and
recognized PHP ``#[Autowire]`` attributes.

Supported Declarations and References
-------------------------------------

The YAML index recognizes:

* service and parameter declarations;
* ``@service`` and ``%parameter%`` references;
* aliases and decorators;
* tags and bindings.

The PHP index recognizes service and parameter references in
``#[Autowire]`` attributes. Dynamic references aren't indexed.

Completion
----------

In YAML service configuration, type ``@`` followed by a service ID prefix or
``%`` followed by a parameter prefix:

.. code-block:: yaml

    # config/services.yaml
    services:
        App\Controller\ArticleController:
            arguments: ['@app.ma', '%app.storage_']

Completion includes private and hidden services, aliases, application-owned
static declarations and effective parameter names. The ``service`` and ``param``
arguments of PHP ``#[Autowire]`` attributes are also supported:

.. code-block:: php

    // src/Controller/ArticleController.php
    namespace App\Controller;

    use Symfony\Component\DependencyInjection\Attribute\Autowire;

    final class ArticleController
    {
        public function __construct(
            #[Autowire(service: 'app.ma')]
            private object $mailer,
            #[Autowire(param: 'app.storage_')]
            private string $storageDirectory,
        ) {
        }
    }

Parameter placeholders passed as the first ``#[Autowire]`` argument are
supported too.

Hover
-----

Service hover can display:

* the service class and alias target;
* visibility and laziness;
* deprecation metadata;
* tags;
* the decorated service and decoration stack;
* autowiring types and named aliases.

Parameter hover displays only the parameter name and deprecation metadata.
Parameter values are never displayed.

Definition
----------

Definition requests navigate to application-owned YAML service or parameter
declarations. Service results can also include alias declarations, decorators
and the application PHP class associated with the service.

References and Rename
---------------------

Reference requests include YAML service and parameter references and recognized
PHP ``#[Autowire]`` attributes. Declarations can be included when requested by
the Language Server Protocol client.

Rename is available only when an application-owned YAML declaration exists. It
updates that declaration and all statically recognized application references.
The edit requires confirmation because dynamic references may remain unchanged.
A rename to an existing service or parameter name is rejected.

Diagnostics
-----------

After a complete runtime container snapshot is available, definitely unknown
service and parameter references are reported as errors. Optional service
references such as ``@?app.mailer`` aren't diagnosed. Unknown tags are accepted
because applications and compiler passes can define their own tags.

Secret-Handling Boundary
------------------------

The project bridge runs these structured commands for container metadata:

* ``debug:container --format=json --show-hidden``;
* ``debug:container --types --format=json``;
* ``debug:container --parameters --format=json``.

The parameter command is used internally only. The bridge keeps parameter names
and deprecations, then discards values before creating the runtime snapshot.
Values never enter static indexes, logs, hover output or other Language Server
Protocol responses.

Static and Runtime Indexing
---------------------------

The runtime snapshot provides effective services and parameters from the
selected Symfony environment. The static index scans application-owned YAML and
PHP files for declarations, references and service classes. Unsaved documents
overlay the disk-backed facts immediately.
