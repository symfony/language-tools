Assets
======

Completion, navigation and diagnostics for asset paths and importmap
entrypoints. AssetMapper logical paths come from the running application; see
`how it works`_.

Where It Works
--------------

In the Twig ``asset()`` and ``importmap()`` functions.

In the Editor
-------------

* completion for asset paths, from AssetMapper when it's installed and from
  the files in ``public/`` otherwise, and for importmap entrypoint names;
* hover shows the file an asset resolves to, whether it comes from a package,
  and the path and version of an importmap entry;
* go to definition opens the asset file, find references lists its usages, and
  an asset path in a template becomes a clickable link.

Diagnostics
-----------

* ``importmap.unknown_entrypoint``: ``importmap.php`` declares no such
  entrypoint. Needs the running application.

Asset paths themselves aren't diagnosed.

Limitations
-----------

* AssetMapper paths need ``symfony/asset-mapper`` and runtime analysis. The
  ``public/`` fallback works without either;
* completion from ``public/`` stops after 5000 files and refreshes at most
  every ten seconds, so a large public directory is listed partially;
* an ``asset()`` call that names another asset package is skipped;
* rename isn't available.

.. _`how it works`: index.rst
