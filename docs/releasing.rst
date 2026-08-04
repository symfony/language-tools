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
* repository: ``lsp``;
* entity type: ``Environment``;
* environment: ``vscode-marketplace``;
* audience: ``api://AzureADTokenExchange``.

The resulting subject must be exactly
``repo:symfony/lsp:environment:vscode-marketplace``. Record the application
client ID and tenant ID.

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
``symfony`` publisher as a Contributor.

Create a GitHub environment named ``vscode-marketplace``. Limit deployments to
``main`` and tags matching ``v*``, configure required reviewers when approval is
desired and add these environment variables:

* ``AZURE_CLIENT_ID``: the application client ID;
* ``AZURE_TENANT_ID``: the directory tenant ID.

The identifiers aren't secrets. The federated credential only accepts GitHub
OIDC tokens issued for the named environment.

Use the manual dispatch only for the first publication or to recover a failed
Marketplace job for an existing release. The workflow refuses a VSIX whose
embedded prerelease marker doesn't match the GitHub release and never repackages
an existing asset:

.. code-block:: terminal

    $ gh workflow run publish-vscode.yaml \
        --ref main \
        -f tag=vX.Y.Z

Preparing the Release Commit
----------------------------

Choose a semantic version ``X.Y.Z`` and update ``CHANGELOG.md``. Move the
``Unreleased`` entries under a dated version heading:

.. code-block:: text

    ## X.Y.Z (YYYY-MM-DD)

Update the VS Code package and lock file without creating a tag:

.. code-block:: terminal

    $ cd editor/vscode
    $ npm version X.Y.Z --no-git-tag-version
    $ cd ../..

Update versioned installation examples in ``docs/index.rst`` and
``docs/editors/vscode.rst``. Don't change ``resources/version``; tagged release
jobs replace its value only inside their isolated checkout.

Review the complete release diff, then create one release-preparation commit.
The commit should contain version metadata, documentation and CHANGELOG changes
only.

Pre-Tag Validation
------------------

Run the complete local quality suite:

.. code-block:: terminal

    $ composer test
    $ composer phpstan
    $ composer cs-check
    $ cd editor/vscode
    $ npm ci
    $ npm run check
    $ cd ../..
    $ composer server:benchmark

Build the sidecar and run all Symfonycorp applications:

.. code-block:: terminal

    $ composer tree-sitter:build-sidecar
    $ ./tools/dogfood-symfonycorp \
        ./bin/symfony-lsp \
        ./build/symfony-lsp-tree-sitter \
        ../../symfonycorp

Push the release-preparation commit with an explicit destination and wait for
``PHP quality``, ``Symfony compatibility`` and ``VS Code integration`` to pass.
Never derive a push destination from a local branch's upstream.

Tagging
-------

Create the tag only after the preparation commit and all CI workflows pass:

.. code-block:: terminal

    $ git tag vX.Y.Z
    $ git push origin refs/tags/vX.Y.Z:refs/tags/vX.Y.Z

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

Monitoring the Workflow
-----------------------

Watch every release job instead of waiting only for the final release job:

.. code-block:: terminal

    $ gh run list --workflow=release.yaml
    $ gh run watch <run-id>

A failed job means the release is incomplete. Fix the root cause on ``main`` and
prepare a new version. A Marketplace-only failure can be retried through the
manual publication workflow after fixing its automation or identity setup.
Never force-push or move a tag that has already been published.

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

Install ``symfony.symfony-lsp`` from the Visual Studio Marketplace into a
disposable VS Code profile or test installation. Select the prerelease channel
when the GitHub release is a prerelease. Confirm that Marketplace selected the
current platform package, then confirm that the extension starts its bundled
server, forwards workspace trust and settings, recognizes Twig documents,
exposes the expected server version and provides index commands and status.

Post-Release Work
-----------------

After verification:

1. Add an empty ``Unreleased`` section to ``CHANGELOG.md`` on ``main``.
2. Record any release-only fix as a new commit, never by moving the tag.
3. Confirm ``resources/version`` is still ``dev``.
4. Confirm the Marketplace lists every supported platform for the version.
5. Confirm regular CI passes after the post-release commit.
6. Keep generated archives, checksums and dogfood JSON under ignored ``var/``
   paths.

See :doc:`Testing and Validation </testing>` and
:doc:`Dogfooding Symfony LSP </dogfooding>` for the validation details behind
this checklist.

.. _`Microsoft Entra admin center`: https://entra.microsoft.com/
.. _`Visual Studio Marketplace publisher management`: https://marketplace.visualstudio.com/manage
