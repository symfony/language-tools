# Symfony LSP project instructions

Read the relevant development documentation before changing architecture or
adding a feature:

- `docs/architecture.rst`: process boundaries, indexes, refreshes, trust and
  data flow.
- `docs/adding-integrations.rst`: required provider, bridge, cache and test
  patterns.
- `docs/testing.rst`: validation by change type.
- `docs/dogfooding.rst`: the eight-application Symfonycorp matrix.
- `docs/releasing.rst`: release preparation and verification.

## Architecture rules

- Keep `LanguageServerFactory` as the composition root and inject dependencies.
- Keep source facts and effective runtime metadata separate, then merge them in
  providers through narrow indexes.
- Use open-document overlays for unsaved source facts. Never boot the application
  for an unsaved edit.
- Use structured Symfony commands or public APIs in bridge sections. Never parse
  unstable human-readable console output or generated cache implementation
  files.
- Keep generic JSON-RPC framing and transport changes in
  `fabpot/json-rpc-peer`; keep Symfony and LSP semantics in this repository.
- Keep the bridge compatible with every branch in the Symfony compatibility
  workflow.
- Add every persisted source-fact class to `SourceIndexPayloadCodec` and test
  cache restoration.

## Accuracy and privacy

- Match precise Symfony contexts, not common method names in arbitrary code.
- Diagnose only values proven invalid by complete metadata. Treat extensible
  runtime sets conservatively and ignore dynamic expressions.
- Never expose parameter values, environment values, credentials, resolved
  secrets or arbitrary application objects in snapshots, caches, logs, hovers,
  diagnostics or protocol traces.
- Keep untrusted workspaces source-only.
- Never edit generated cache files or dependency-owned files through rename or
  code actions.

## Validation

- Add a regression test for every bug fix.
- Run focused tests while developing, then `composer test`, `composer phpstan`
  and `composer cs-check` before committing.
- Run `composer server:benchmark` for parser, index, runtime or request-path
  changes.
- Run the eight Symfonycorp applications for runtime metadata, source indexing,
  cross-file features, performance-sensitive changes and release verification.
- Run compatibility tests for version-sensitive bridge changes and VS Code E2E
  tests for editor lifecycle or configuration changes.
- Keep dogfood output and release artifacts under ignored `var/` paths.

## Completing a feature

- Update the feature documentation and supported-integration table.
- Add one short imperative entry without trailing punctuation to the
  `Unreleased` CHANGELOG section.
- Update the RFP checklist when a planned capability is complete.
- Add or update a conservative dogfood probe when real applications contain the
  context.
- Commit source, tests and documentation atomically. Do not push or publish a
  release without explicit user confirmation.
