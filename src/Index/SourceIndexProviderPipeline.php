<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Project\Project;

final class SourceIndexProviderPipeline
{
    /** @var list<SourceIndexProviderInterface> */
    private array $providers;

    /** @param iterable<SourceIndexProviderInterface> $providers */
    public function __construct(private readonly SourceIndexPayloadCodec $codec, iterable $providers)
    {
        $providers = \is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
        $this->codec->validate($providers);
        $this->providers = $providers;
    }

    public function begin(Project $project): void
    {
        foreach ($this->providers as $provider) {
            $provider->begin($project);
        }
    }

    /** @return array<string, string> */
    public function index(Project $project, SourceDocument $document): array
    {
        $payloads = [];
        foreach ($this->providers as $provider) {
            $payloads[$provider->name()] = $this->encode($provider, $provider->index($project, $document));
        }

        return $payloads;
    }

    /** @param array<string, string> $payloads */
    public function restore(Project $project, array $payloads): void
    {
        foreach ($this->providers as $provider) {
            $payload = $payloads[$provider->name()] ?? null;
            if (!\is_string($payload)) {
                throw new \UnexpectedValueException('A source index provider payload is missing.');
            }
            if ('' !== $payload) {
                $provider->restore($project, $this->codec->decode($provider->name(), $payload));
            }
        }
    }

    public function finish(Project $project): void
    {
        foreach ($this->providers as $provider) {
            $provider->finish($project);
        }
    }

    /** @param array<string, string> $previousPayloads */
    public function replace(Project $project, SourceDocument $document, array $previousPayloads): SourceIndexProviderReplacement
    {
        $payloads = [];
        $factsChanged = false;
        $changedProviders = [];
        foreach ($this->providers as $provider) {
            $name = $provider->name();
            $data = $provider->replace($project, $document);
            $payloads[$name] = $this->encode($provider, $data);
            $previousPayload = $previousPayloads[$name] ?? null;
            if ($payloads[$name] === $previousPayload) {
                continue;
            }
            $factsChanged = true;
            if ('' === $previousPayload) {
                if ([] === $provider->runtimeDeclarations($data)) {
                    continue;
                }
            } elseif (\is_string($previousPayload)) {
                try {
                    $previousData = $this->codec->decode($name, $previousPayload);
                    if (serialize($provider->runtimeDeclarations($data)) === serialize($provider->runtimeDeclarations($previousData))) {
                        continue;
                    }
                } catch (\UnexpectedValueException) {
                }
            }
            $changedProviders[] = $name;
        }

        return new SourceIndexProviderReplacement($payloads, $factsChanged, $changedProviders);
    }

    public function remove(Project $project, string $uri): void
    {
        foreach ($this->providers as $provider) {
            $provider->remove($project, $uri);
        }
    }

    public function overlay(Project $project, Document $document): void
    {
        foreach ($this->providers as $provider) {
            $provider->overlay($project, $document);
        }
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        foreach ($this->providers as $provider) {
            $provider->removeOverlay($project, $uri);
        }
    }

    private function encode(SourceIndexProviderInterface $provider, ?SourceFactsInterface $facts): string
    {
        return null === $facts || $facts->isEmpty() ? '' : $this->codec->encode($provider->name(), $facts);
    }
}
