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
double-quoted attributes are supported, including quoted ``>`` characters.
Comments, CDATA sections, processing instructions and DOCTYPE declarations are
ignored. Only elements bound to the services namespace are indexed. Tags are
read only as direct children of their service, including services nested inside
another service. Valid definitions after malformed markup are still indexed.
XML entity declarations and external identifiers aren't loaded or expanded.
Completion is only available in YAML and PHP files.

``%parameter%`` references are also recognized outside the ``parameters``
and ``services`` sections in configuration files, such as
``%kernel.project_dir%`` in a ``config/packages`` file. In YAML, double
percents escape a literal percent unless they are the closing and opening
delimiters of adjacent references. For example,
``%root_dir%%document_folder%`` contains two independent references.
References in YAML comments are ignored.

Symfony Language Tools also recognizes service and parameter references in PHP
``#[Autowire]`` attributes. YAML references under ``when@...`` and in
conventional environment-specific files such as
``config/services_<environment>.yaml`` and
``config/packages/<environment>/`` are validated only when that environment is
selected. Dynamic references aren't recognized.

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

Completion is offered only inside the ``#[Autowire]`` argument holding the
cursor. Arguments of other attributes and of function calls are never
completed, even when they use the same argument names.

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

Limitations
-----------

XML service definitions are indexed for navigation, but in-file diagnostics
and cursor features aren't available in XML files.

Environment-specific file detection follows Symfony's conventional ``config/``
layout. Custom conditional imports aren't inferred from application code.

PHP completion requires an ``#[Autowire]`` attribute that resolves to Symfony's
attribute through a ``use`` statement or a fully qualified name. An unimported
short name isn't completed.

Privacy
-------

Parameter values are never displayed or written to logs.
