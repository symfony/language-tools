Releasing Symfony LSP
=====================

Symfony LSP releases contain standalone server binaries for each supported
platform, matching Tree-sitter sidecars, self-contained VS Code extensions and
a checksum manifest. The GitHub release workflow builds and publishes them from
a version tag.

Release Invariants
------------------

A release must satisfy these rules:

* the tag points to a commit that passed every regular CI workflow;
* the VS Code package version matches the tag;
* ``resources/version`` remains ``dev`` in source control;
* release jobs derive the server and protocol version from the tag;
* the server and sidecar from one release stay together;
* every archive and VSIX appears in ``SHA256SUMS``;
* every platform VSIX is published under the matching Marketplace version;
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
        -f tag=vX.Y.Z

Running a Release
-----------------

Start from a clean ``main`` branch synchronized with ``origin/main``, then run
one command with the next semantic version:

.. code-block:: terminal

    $ ./tools/release X.Y.Z

Type the version again at the confirmation prompt. Pass ``--yes`` only for an
intentional non-interactive invocation.

Before changing release metadata, the command runs PHPUnit, PHPStan, coding
standards, the VS Code checks, the server benchmark and all eight Symfonycorp
applications. It then:

1. moves the ``Unreleased`` entries under the dated version heading;
2. updates the VS Code package and lock file without creating an npm tag;
3. updates versioned examples in ``docs/index.rst`` and
   ``docs/editors/vscode.rst``;
4. verifies that only release metadata files changed and commits them;
5. pushes ``main`` with an explicit refspec;
6. waits for ``PHP quality``, ``Symfony compatibility`` and
   ``VS Code integration``;
7. creates and pushes the version tag only after those workflows pass;
8. waits for the complete GitHub and Marketplace release workflow;
9. creates the empty ``Unreleased`` section, pushes that post-release commit
   and waits for regular CI again.

The command never changes ``resources/version`` and never moves a published
tag. Rerun the same command after a network interruption; it resumes a prepared
commit or a tag that already reached the remote. Fix a local or pre-tag CI
failure and rerun the same version. Once the tag reaches the remote, any fix
requires a new version.

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
executables. After every platform succeeds, the release job publishes a GitHub
prerelease. The Marketplace job then downloads those published assets, verifies
the checksums and embedded versions and publishes all five VSIX packages with
the same prerelease status.

Artifact Verification
---------------------

Inspect the release and download every asset into an ignored directory:

.. code-block:: terminal

    $ gh release view vX.Y.Z
    $ mkdir -p var/release/vX.Y.Z
    $ gh release download vX.Y.Z --dir var/release/vX.Y.Z
    $ cd var/release/vX.Y.Z
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
        X.Y.Z

The CLI version and ``initialize`` response version must both equal ``X.Y.Z``.

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
