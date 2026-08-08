Releasing Symfony LSP
=====================

Symfony LSP releases contain standalone server binaries for each supported
platform, matching Tree-sitter sidecars, self-contained VS Code extensions, a
first-party Neovim plugin and a checksum manifest. The GitHub release workflow
builds and publishes them from a version tag.

Release Invariants
------------------

A release must satisfy these rules:

* the tag points to a commit that passed every regular CI workflow;
* the VS Code package and Neovim plugin versions match the tag;
* ``resources/version`` remains ``dev`` in source control;
* release jobs derive the server and protocol version from the tag;
* the server and sidecar from one release stay together;
* every archive and VSIX appears in ``SHA256SUMS``;
* every platform VSIX is published under the matching Marketplace version;
* a prerelease suffix selects the GitHub and Marketplace prerelease channels;
* Marketplace automation uses Microsoft Entra ID without a stored credential;
* the published tag is never moved;
* CHANGELOG entries use an imperative verb and omit trailing punctuation.

Marketplace Automation Setup
----------------------------

Marketplace publication uses a Microsoft Entra application federated with the
``vscode-marketplace`` GitHub environment. Complete this setup once; the release
workflow then publishes without a Personal Access Token or client secret.

Create a single-tenant app registration in the `Microsoft Entra admin center`_.
Add a federated credential with these values:

* scenario: ``GitHub Actions deploying Azure resources``;
* organization: ``symfony``;
* organization ID: ``143937``;
* repository: ``lsp``;
* repository ID: ``1316083251``;
* entity type: ``Environment``;
* environment: ``vscode-marketplace``;
* audience: ``api://AzureADTokenExchange``.

The resulting subject must be exactly
``repo:symfony@143937/lsp@1316083251:environment:vscode-marketplace``. Record
the application client ID and tenant ID.

The Visual Studio Marketplace requires the application's Team Foundation
Identity ID, not its Entra application or object ID. Create a temporary client
secret with the shortest available lifetime, then authenticate as the
application and query its profile:

.. code-block:: terminal

    $ az login --service-principal \
        --username APPLICATION_CLIENT_ID \
        --tenant TENANT_ID \
        --password TEMPORARY_CLIENT_SECRET \
        --allow-no-subscriptions
    $ az rest \
        --url 'https://app.vssps.visualstudio.com/_apis/profile/profiles/me' \
        --resource '499b84ac-1321-427f-aa17-267ca6975798'
    $ az logout

Copy the response's ``id`` field. Immediately delete the temporary secret, then
open `Visual Studio Marketplace publisher management`_. Add that ID to the
``symfonycorp`` publisher as a Contributor.

Create a GitHub environment named ``vscode-marketplace``. Limit deployments to
``main`` and tags matching ``v*``, configure required reviewers when approval is
desired and add these environment variables:

* ``AZURE_CLIENT_ID``: the application client ID;
* ``AZURE_TENANT_ID``: the directory tenant ID.

The identifiers aren't secrets. The federated credential only accepts GitHub
OIDC tokens issued for the named environment. After the workflow reaches
``main``, verify the complete identity chain without publishing an extension:

.. code-block:: terminal

    $ gh workflow run publish-vscode.yaml \
        --ref main \
        -f verify_only=true

Use a publication dispatch only for the first publication or to recover a
failed Marketplace job for an existing release. The workflow refuses a VSIX
whose embedded prerelease marker doesn't match the GitHub release. It never
rebuilds an existing asset:

.. code-block:: terminal

    $ gh workflow run publish-vscode.yaml \
        --ref main \
        -f tag=vVERSION

Publisher Verification
----------------------

The Visual Studio Marketplace requires a publisher to have at least one listed
extension for six months before it can receive a verified badge. The domain
must also have been registered for at least six months. Since the first
``symfonycorp`` extension was published on August 5, 2026, the publisher isn't
eligible before February 5, 2027.

Once eligible, open `Visual Studio Marketplace publisher management`_, select
``symfonycorp`` and open the ``Details`` tab. Enter ``https://symfony.com`` in
the ``Verified domain`` field, save it and select ``Verify``. Add the generated
TXT record to the domain's DNS configuration, then complete verification in the
Marketplace. The Marketplace team reviews the request within five business
days. Changing the publisher display name later revokes the verified badge.

Running a Release
-----------------

Start from a clean ``main`` branch synchronized with ``origin/main``, then run
one command with the next semantic version:

.. code-block:: terminal

    $ ./tools/release X.Y.Z
    $ ./tools/release X.Y.Z-PRERELEASE

Use a version such as ``1.0.0`` for a stable release or ``1.0.0-rc.1`` for a
prerelease. The suffix selects the corresponding GitHub and Visual Studio
Marketplace channel. Type the version again at the confirmation prompt. Pass
``--yes`` only for an intentional non-interactive invocation.

Before changing release metadata, the command installs the runtime fixture and
runs PHPUnit, PHPStan, coding standards, the VS Code and Neovim checks, the
server and runtime refresh benchmarks and all eight Symfonycorp applications.
It then:

1. moves the ``Unreleased`` entries under the dated version heading;
2. updates the VS Code package and lock file without creating an npm tag;
3. updates the Neovim plugin version and versioned documentation examples;
4. verifies that only release metadata files changed and commits them;
5. pushes ``main`` with an explicit refspec;
6. waits for ``PHP quality``, ``Symfony compatibility``,
   ``VS Code integration`` and ``Neovim integration``;
7. creates and pushes the version tag only after those workflows pass;
8. waits for the complete GitHub and Marketplace release workflow;
9. creates the empty ``Unreleased`` section, pushes that post-release commit
   and waits for regular CI again.

The command never changes ``resources/version`` and never moves a published
tag. It automatically reruns failed workflow jobs once and reports the failed
logs if the rerun also fails. Rerun the same command after a network
interruption; it resumes a prepared commit or a tag that already reached the
remote. Fix a local or pre-tag CI failure and rerun the same version. Once the
tag reaches the remote, any fix requires a new version.

Release Workflow
----------------

Pushing the tag starts ``.github/workflows/release.yaml``. The workflow builds:

* Linux x64 and arm64 archives;
* macOS x64 and arm64 archives;
* a Windows x64 archive;
* one self-contained VS Code VSIX for each platform;
* ``SHA256SUMS`` covering every published artifact.

Unix jobs build a PHAR, combine it with the pinned static PHP runtime, build the
Tree-sitter sidecar and run the protocol smoke test. The Windows job builds both
executables and checks sidecar parsing. Each VS Code job packages the matching
executables. After every platform succeeds, the release job publishes a stable
GitHub release or prerelease according to the version suffix. The Marketplace
job then downloads those published assets, verifies the checksums and embedded
versions and publishes all five VSIX packages on the same channel.

Artifact Verification
---------------------

Inspect the release and download every asset into an ignored directory:

.. code-block:: terminal

    $ gh release view vVERSION
    $ mkdir -p var/release/vVERSION
    $ gh release download vVERSION --dir var/release/vVERSION
    $ cd var/release/vVERSION
    $ shasum -a 256 -c SHA256SUMS
    $ cd ../../..

Verify that the release contains five platform archives, five VSIX files and
the checksum manifest. Extract the archive for the current machine and check
the reported version:

.. code-block:: terminal

    $ /path/to/symfony-lsp --version
    $ ./tools/smoke-test-server \
        /path/to/symfony-lsp \
        /path/to/symfony-lsp-tree-sitter \
        VERSION

The CLI version and ``initialize`` response version must both equal the complete
release version, including any prerelease suffix.

Packaged Dogfooding
-------------------

Run the extracted release binaries against all eight Symfonycorp applications:

.. code-block:: terminal

    $ ./tools/dogfood-symfonycorp \
        /path/to/symfony-lsp \
        /path/to/symfony-lsp-tree-sitter \
        ../../symfonycorp

Require ready source and runtime indexes, no request errors, no server errors
and a successful server exit for every application. Compare response counts and
latency with the development build when a packaged result differs.

Neovim Verification
-------------------

Install the tagged repository with ``vim.pack`` or lazy.nvim in a disposable
Neovim profile. Remove any previous Symfony LSP installation from that profile,
then call ``require('symfony_lsp').setup()``. Confirm that the plugin downloads
the archive matching the current platform, verifies its checksum and starts the
same server version as the plugin.

Run ``:checkhealth symfony_lsp``, ``:SymfonyLspIndexStatus`` and a code lens.
Confirm that the statusline component reaches the ready state, the index
commands return project status and the code lens opens its locations in the
quickfix list.

VS Code Verification
--------------------

Install ``symfonycorp.symfony-lsp`` from the Visual Studio Marketplace into a
disposable VS Code profile or test installation. Select the prerelease channel
when the GitHub release is a prerelease. Confirm that Marketplace selected the
current platform package, then confirm that the extension starts its bundled
server, forwards workspace trust and settings, recognizes Twig documents,
exposes the expected server version and provides index commands and status.

Post-Release Work
-----------------

The release command adds the empty ``Unreleased`` section, confirms
``resources/version`` remains ``dev`` and waits for post-release CI. After
verification:

1. Record any release-only fix as a new commit, never by moving the tag.
2. Confirm the Marketplace lists every supported platform for the version.
3. Keep generated archives, checksums and dogfood JSON under ignored ``var/``
   paths.

See :doc:`Testing and Validation </testing>` and
:doc:`Dogfooding Symfony LSP </dogfooding>` for the validation details behind
this checklist.

.. _`Microsoft Entra admin center`: https://entra.microsoft.com/
.. _`Visual Studio Marketplace publisher management`: https://marketplace.visualstudio.com/manage
