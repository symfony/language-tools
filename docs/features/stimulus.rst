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
``data-*-target`` attributes and the ``stimulus_target()`` helper.

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

Hover describes the controller source, loading mode, actions, targets, values,
outlets and CSS classes. Go to Definition and document links open controller
files or member declarations. Find All References connects controller and
member declarations to their static Twig usages. Controller files also provide
usage code lenses.

An unknown static controller name is reported only after all registered
controllers are known. Unknown actions and targets aren't diagnosed because
controllers can inherit or register them dynamically.

Live Components
---------------

Symfony Language Tools recognizes ``#[AsLiveComponent]``, ``#[LiveProp]``,
``#[LiveAction]`` and ``#[LiveListener]`` attributes, including imported
aliases. Live properties are included in component property completion. Actions
are completed in
``data-live-action-param`` attributes and ``live_action()`` calls when the
containing component is known.

Hover identifies Live Components and their properties and actions. Go to
Definition and Find All References connect action attributes to their PHP
methods and component templates.

Events declared by ``#[LiveListener]`` are completed in static ``emit()`` calls
inside Live Components. Hover shows listeners, Go to Definition opens listener
declarations and Find All References lists static emitters and listeners.

Limitations
-----------

Project controllers using the conventional ``*_controller.js`` and
``*_controller.ts`` names are recognized. Runtime indexing adds custom paths,
installed Symfony UX controllers and bundle ``controllers.json`` registries.
Dynamic controller registration, computed action names, inherited actions and
dynamic Live Component event names are ignored.
