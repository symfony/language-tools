Dependency Injection: Services and Parameters
=============================================

The dependency injection integration understands services, aliases, autowiring
types and parameter names from the selected Symfony environment. It also finds
YAML and XML declarations and recognized PHP ``#[Autowire]`` attributes.

Supported Declarations and References
-------------------------------------

Symfony Language Tools recognizes these YAML declarations and references:

* service and parameter declarations;
* ``@service`` and ``%parameter%`` references;
* aliases and decorators;
* tags and bindings.

XML service definitions using the ``dic/services`` schema are recognized
too: service, alias and parameter declarations, ``decorates`` targets,
tags, and service and parameter references in arguments. Single-quoted and
double-quoted attributes are supported, and XML comments are ignored.
Completion is only available in YAML and PHP files.

``%parameter%`` references are also recognized outside the ``parameters``
and ``services`` sections in configuration files, such as
``%kernel.project_dir%`` in a ``config/packages`` file. Double percents escape
a literal percent, and references in YAML comments are ignored.

Symfony Language Tools also recognizes service and parameter references in PHP
``#[Autowire]`` attributes. YAML references under ``when@...`` are validated
only for the selected environment. Dynamic references aren't recognized.

Completion
----------

In YAML service configuration, type ``@`` followed by a service ID prefix or
``%`` followed by a parameter prefix:

.. code-block:: yaml

    # config/services.yaml
    services:
        App\Controller\ArticleController:
            arguments: ['@app.ma', '%app.storage_']

Completion includes private and hidden services, aliases, project declarations
and parameter names. The ``service`` and ``param``
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

Definition requests navigate to application-owned YAML or XML service or
parameter declarations. Service results can also include alias
declarations, decorators and the application PHP class associated with the
service.

References and Rename
---------------------

Reference results include YAML service and parameter references, recognized PHP
``#[Autowire]`` attributes and, when requested, declarations.

Rename is available only when an application-owned YAML or XML declaration
exists. It updates that declaration and all statically recognized
application references.
The edit requires confirmation because dynamic references may remain unchanged.
A rename to an existing service or parameter name is rejected.

Diagnostics
-----------

Once runtime indexing completes, definitely unknown service and parameter
references are reported as errors. Optional service
references such as ``@?app.mailer`` aren't diagnosed. Unknown tags are accepted
because applications and compiler passes can define their own tags.

Privacy
-------

Parameter values are never displayed or written to logs.
