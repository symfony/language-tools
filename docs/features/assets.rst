AssetMapper and Importmaps
==========================

Symfony Language Tools understands effective AssetMapper logical paths and
importmap entrypoints. AssetMapper features are enabled only when
``symfony/asset-mapper`` is installed in the selected application; plain
files under the ``public/`` document root are supported in every
application.

Completion
----------

Logical asset-path completion is available in static Twig ``asset()`` calls
using the default asset package. Importmap entrypoint completion is available
for a string or list passed to Twig ``importmap()``.

Named asset packages, absolute public paths and dynamic expressions are ignored
because they don't identify an AssetMapper logical path exactly.

Navigation and References
-------------------------

Hover shows the logical path, source file and whether an asset comes from a
vendor path. Go to Definition and document links open the effective mapped
source file, including bundle-provided assets under ``vendor/``.

Importmap hover shows the configured path and package version when available.
Go to Definition opens the entrypoint declaration in ``importmap.php``. Find All
References lists statically recognized Twig usages for assets and entrypoints.

Diagnostics
-----------

An unknown static importmap entrypoint is reported after the effective importmap
has loaded. Dynamic entrypoint expressions are ignored.

Unknown ``asset()`` paths aren't diagnosed. Symfony's asset package can
legitimately fall back to a public path that isn't part of AssetMapper.

Public Assets
-------------

When an ``asset()`` path doesn't match an AssetMapper logical path, it is
resolved against the application's ``public/`` directory. Hover shows the
resolved file, and Go to Definition and document links open it. Completion
suggests files from ``public/``, including build artifacts and installed
bundle assets when they exist on disk. Applications without AssetMapper,
such as Webpack Encore setups, get the same behavior. Removing and re-adding
a workspace folder refreshes the public file list.
