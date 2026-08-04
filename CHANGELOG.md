# Changelog

## Unreleased

- Added Doctrine entity, repository, and mapped field support.
- Added Stimulus and Live Component support.
- Added AssetMapper path and importmap entrypoint support.
- Added form option, validation constraint, serializer group, and mapped metadata support.
- Added Twig render-context variables, globals, and component support.

## 0.2.0 (2026-08-01)

- Added a server benchmark for indexing, latency, cancellation, and memory targets.
- Added quick fixes for missing templates, translations, and route parameters.
- Added tolerant extraction for incomplete JSON, XLIFF, and dotenv resources.
- Fixed cancelled requests being reported as internal server errors.
- Fixed route features for injected router properties.
- Fixed false configuration and service diagnostics.
- Fixed indexing templates without a Twig file extension.

## 0.1.4 (2026-08-01)

- Fixed runtime bridge namespaces in release builds.

## 0.1.3 (2026-08-01)

- Fixed runtime metadata parsing when console output precedes JSON.
- Fixed runtime indexing when the Twig debug command is unavailable.
- Fixed version reporting and persistent cache namespaces in release builds.

## 0.1.2 (2026-08-01)

- Added Symfony-aware completion, navigation, references, hover and diagnostics.
- Added route, service, parameter, template, translation and environment indexing.
- Added configuration, Messenger, event and Security indexing.
- Added tolerant PHP, Twig and YAML source parsing.
- Added best-effort rename support for routes, services, parameters and translations.
- Added trusted runtime indexing across supported Symfony branches.
- Added persistent source indexes and live document overlays.
- Added a VS Code client with workspace trust and project settings.
- Added standalone Linux, macOS and Windows release archives.
