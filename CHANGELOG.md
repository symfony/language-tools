# Changelog

## Unreleased

- Tolerate console noise around the debug:router JSON output when loading the routes section

## 0.10.0 (2026-08-18)

- Detect lazy Stimulus controllers declared with attached line comments or double-quoted block comments
- Support Docker-only applications by mapping project paths through the new containerProjectRoot setting
- Support Zed on Linux and macOS through an auto-installing extension
- Run Zed validation with the active Rustup toolchain and no Python dependency

## 0.9.1 (2026-08-18)

- Support OpenCode as a custom LSP client on Linux and macOS
- Discover kernels outside the App namespace through the Composer PSR-4 autoload roots
- Keep application error details out of bridge section warnings
- Keep stray project output and error display off the project bridge stdout so the payload stays parseable
- Decode the project bridge payload from its trailing JSON line instead of the whole stdout stream
- Include the bridge error output in runtime indexing failure messages
- Log runtime metadata failures to the server log instead of hiding them behind the generic notification

## 0.9.0 (2026-08-18)

- Ignore Composer packages when discovering full-stack applications
- Report PHP fatal errors on stderr instead of corrupting the JSON-RPC stdout channel
- Raise the server memory limit to 2G and support SYMFONY_LSP_MEMORY_LIMIT and symfonyLsp.memoryLimit overrides
- Skip unreadable directories during project discovery, source indexing, and template indexing
- Honor .gitignore rules during project discovery and source indexing while keeping dotenv files indexed
- Skip package manager lock files during source indexing
- Compile the Tree-sitter parser into the bundled Linux and macOS servers for in-process parsing
- Compile the Tree-sitter parser into the bundled Windows server for in-process parsing
- Collect PHP garbage on a fixed cadence during source scans to keep indexing time linear
- Resolve the vendored Tree-sitter Unicode headers instead of system ICU headers
- Remove the Tree-sitter sidecar executable in favor of the compiled-in parser
- Add a `--socket` server option that connects to a listening client
- Use the socket transport in the VS Code extension on Windows
- Stream the source index to an appendable store instead of holding every payload in memory
- Discard stale translation catalogue caches during targeted runtime refreshes
- Skip persisting and restoring empty source facts payloads
- Benchmark source index scaling and enforce per-file memory budgets in CI

## 0.8.6 (2026-08-15)

- Rename every user-facing product string from Symfony LSP to Symfony Language Tools

## 0.8.5 (2026-08-13)

- Publish a guided Marketplace overview with a capability tour for the VS Code extension

## 0.8.3 (2026-08-13)

- Replace the first-party Neovim plugin with an nvim-lspconfig integration
- Isolate runtime refresh benchmarks from active editor processes

## 0.8.2 (2026-08-11)

- Focus public documentation on installation, supported behavior and troubleshooting

## 0.8.1 (2026-08-11)

- Validate Neovim package installation from tagged checkouts
- Name the VS Code extension Symfony Language Tools

## 0.8.0 (2026-08-11)

- Publish the VS Code extension as symfony.language-tools

## 0.7.0 (2026-08-11)

- Publish VS Code extensions under the symfony Marketplace publisher
- Keep application errors out of runtime snapshots and index status responses
- Verify downloaded build tools and PHP runtimes before creating release binaries
- Include third-party licenses and the minimal PHP runtime in release packages

## 0.6.0 (2026-08-08)

- Serialize runtime refreshes across watched changes and index commands
- Watch application directories so Neovim detects nested file changes
- Skip runtime refreshes for reference-only source changes and require debug mode for runtime indexing
- Prevent JSON-RPC listener deadlocks after watched Composer file changes
- Add a self-installing Neovim client with status and index controls
- Register watched workspace files through the LSP protocol
- Rebuild containers directly for refresh plans that cannot safely reuse them
- Benchmark save-triggered refresh latency for every runtime metadata section

## 0.5.0 (2026-08-07)

- Refresh only runtime domains affected by source changes when their dependencies are known
- Use associative-array JSON-RPC decoding from json-rpc-peer

## 0.4.2 (2026-08-07)

- Normalize structured JSON-RPC values at the LSP boundary
- Retry failed release workflows once automatically
- Reuse fresh debug caches during runtime refreshes

## 0.4.0 (2026-08-07)

- Skip runtime refreshes for changes limited to ordinary PHP method bodies

## 0.3.4 (2026-08-06)

- Retry individual Marketplace package publications after transient failures
- Show failed workflow logs and recovery commands during releases

## 0.3.3 (2026-08-06)

- Support stable and prerelease GitHub and Marketplace releases
- Track supported Symfony branches from Symfony release metadata
- Use Symfony components for service wiring and filesystem operations
- Require PHP 8.4.1 or later
- Stop VS Code index status polling and report failures when the server stops
- Preserve terminal behavior for commands run by the release tool
- Retry transient Symfony release metadata failures in compatibility checks

## 0.3.2 (2026-08-05)

- Add the Symfony icon to the VS Code extension
- Fix packaged VS Code extension startup with its bundled Tree-sitter sidecar

## 0.3.1 (2026-08-05)

- Add a release command that prepares, validates, publishes and monitors a version
- Publish release VSIX packages automatically to the Visual Studio Marketplace

## 0.3.0 (2026-08-04)

- Add self-contained VS Code packages with index status and controls
- Add Doctrine entity, repository, and mapped field support
- Add Stimulus and Live Component support
- Add AssetMapper path and importmap entrypoint support
- Add form option, validation constraint, serializer group, and mapped metadata support
- Add Twig render-context variables, globals, and component support
- Fix runtime refreshes triggered by generated cache and dependency files

## 0.2.0 (2026-08-01)

- Add a server benchmark for indexing, latency, cancellation, and memory targets
- Add quick fixes for missing templates, translations, and route parameters
- Add tolerant extraction for incomplete JSON, XLIFF, and dotenv resources
- Fix canceled requests being reported as internal server errors
- Fix route features for injected router properties
- Fix false configuration and service diagnostics
- Fix indexing templates without a Twig file extension

## 0.1.4 (2026-08-01)

- Fix runtime bridge namespaces in release builds

## 0.1.3 (2026-08-01)

- Fix runtime metadata parsing when console output precedes JSON
- Fix runtime indexing when the Twig debug command is unavailable
- Fix version reporting and persistent cache namespaces in release builds

## 0.1.2 (2026-08-01)

- Add Symfony-aware completion, navigation, references, hover and diagnostics
- Add route, service, parameter, template, translation and environment indexing
- Add configuration, Messenger, event and Security indexing
- Add tolerant PHP, Twig and YAML source parsing
- Add best-effort rename support for routes, services, parameters and translations
- Add trusted runtime indexing across supported Symfony branches
- Add persistent source indexes and live document overlays
- Add a VS Code client with workspace trust and project settings
- Add standalone Linux, macOS and Windows release archives
