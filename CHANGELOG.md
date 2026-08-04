# Changelog

## Unreleased

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
