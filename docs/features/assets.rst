AssetMapper and Importmaps
==========================

Symfony LSP understands effective AssetMapper logical paths and importmap
entrypoints. The integration is enabled only when ``symfony/asset-mapper`` is
installed in the selected application.

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

Runtime and Source Indexes
--------------------------

The trusted project bridge reads effective AssetMapper repository paths from the
structured container metadata, applies configured exclusions and enumerates
application and bundle assets. It reads normalized importmap names, paths,
versions and entrypoint flags without exposing package contents.

The source index records static Twig references and application entrypoint
declarations. Open ``importmap.php`` and Twig documents overlay saved facts, so
completion and navigation reflect unsaved entrypoint and reference changes.
