Stimulus and Live Components
============================

Symfony Language Tools connects Stimulus controllers and Symfony UX Live
Components to references in Twig and JavaScript files.

Stimulus Controllers
--------------------

Controller completion is available in ``data-controller`` attributes and the
``stimulus_controller()``, ``stimulus_action()`` and ``stimulus_target()`` Twig
helpers. Action completion is available in ``data-action`` descriptors and the
``stimulus_action()`` helper. Target completion is available in
``data-*-target`` attributes and the ``stimulus_target()`` helper. The Twig
helpers accept Symfony UX package names such as
``symfony/ux-autocomplete/autocomplete`` and
``@symfony/ux-autocomplete/autocomplete``. Controller completion recognizes
these spellings while typing and inserts the normalized Stimulus identifier.

For example, when ``assets/controllers/search_controller.js`` declares an
``open()`` method and a ``results`` target, Symfony Language Tools completes
these values:

.. code-block:: twig

    <button
        data-controller="search"
        data-action="click->search#open"
        data-search-target="results"
    >
        Search
    </button>

Controllers registered manually on a Stimulus application in a JavaScript or
TypeScript file under ``assets/`` are recognized too, which is how third-party
controllers are added to the bootstrap file:

.. code-block:: javascript

    import { startStimulusApp } from '@symfony/stimulus-bundle';
    import Clipboard from 'stimulus-clipboard';

    const app = startStimulusApp();

    app.register('clipboard', Clipboard);

The registered identifier is used exactly as written, and the registered class
isn't inspected, so its actions, targets and values remain unknown.

Hover describes the controller source, loading mode, actions, targets, values,
outlets and CSS classes. A ``stimulusFetch: 'lazy'`` line or block comment marks
the entire controller file as lazy, regardless of where the comment appears. Go
to Definition and document links open controller files or member declarations.
Find All References connects controller and member declarations to their static
Twig usages. Escaped characters in static helper arguments follow Twig's string
rules. JavaScript comments and string contents aren't indexed as registrations
or controller members. Controller files also provide usage code lenses.

The Twig helpers are recognized only as real calls with static string
arguments, including calls chained on the same expression. Look-alike text in
strings, comments and ``verbatim`` blocks and helper names called on an object
are ignored.

``data-controller``, ``data-action`` and ``data-*-target`` attributes are
recognized only where a template renders them as markup, and only when their
value is static. Attribute values built from a Twig expression are ignored.
Markup-like text on an incomplete Twig directive line is ignored, while normal
markup on following lines remains available. Later quoted expressions remain
ignored, including expressions spanning several lines.

An unknown static controller name is reported only after all registered
controllers are known. Unknown actions and targets aren't diagnosed because
controllers can inherit or register them dynamically.

Live Components
---------------

Symfony Language Tools recognizes ``#[AsLiveComponent]``, ``#[LiveProp]``,
``#[LiveAction]`` and ``#[LiveListener]`` attributes, including imported
aliases. Live properties are included in component property completion. Actions
are completed in ``data-live-action-param`` attributes and ``live_action()``
calls when the containing component is known. Static ``live_action()``
references and completion recognize positional and named ``actionName``
arguments.

Hover identifies Live Components and their properties and actions. Go to
Definition and Find All References connect action attributes to their PHP
methods and component templates.

Events declared by ``#[LiveListener]`` are completed in literal
``$this->emit()`` calls inside their declaring Live Component, including calls
that use the named ``event`` argument. Calls on other receivers and calls in
unrelated classes are ignored. Hover shows listeners, Go to Definition opens
listener declarations and Find All References lists static emitters and
listeners.

Limitations
-----------

Project controllers in ``controllers/`` directories anywhere under ``assets/``
are recognized when they use the conventional ``*_controller.js`` and
``*_controller.ts`` names. Runtime indexing adds custom paths, installed
Symfony UX controllers and bundle ``controllers.json`` registries.
Only members of the default-exported controller class and of superclasses
declared in the same file are recognized; other classes in the file are ignored,
and actions inherited from another file aren't. Manual registrations are
recognized on ``application``, ``this.application`` and variables assigned from
``startStimulusApp()`` or ``Application.start()``, and only when the registered
name is a static string. Computed controller names, computed action names and
dynamic Live Component event names are ignored.
