<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Server\Utf8StringTruncator;

/**
 * One project served by the wiring the server runs on, for the tests of the
 * features that read its documents, its source index and its runtime metadata.
 *
 * A test opens the documents it describes, indexes them through the registered
 * source index providers, loads the runtime metadata sections the bridge would
 * report, and then asks the container for the collaborator it exercises.
 */
final class ProjectTestKit
{
    private const LANGUAGE_IDS = [
        'php' => 'php',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'xml' => 'xml',
        'twig' => 'twig',
        'json' => 'json',
        'js' => 'javascript',
        'ts' => 'typescript',
        'xlf' => 'xml',
    ];

    private readonly Container $container;
    private readonly Project $project;

    /** @var array<string, Document> */
    private array $documents = [];

    public function __construct(string $rootPath = '/workspace', ?string $rootUri = null, ?string $vendorPath = 'vendor')
    {
        $this->container = TestContainer::create();
        $this->container->set(ServerLogger::class, new ServerLogger(null, new SensitiveDataRedactor(new Utf8StringTruncator())));
        $this->project = new Project($rootPath, $rootUri ?? 'file://'.$rootPath, $vendorPath);
        $this->get(ProjectRegistry::class)->replace([$this->project]);
    }

    public function project(): Project
    {
        return $this->project;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $id) {
            throw new \LogicException(\sprintf('The service "%s" is not an instance of it.', $id));
        }

        return $service;
    }

    /** Opens a document, as an editor showing it does. */
    public function open(string $uri, string $text, ?string $languageId = null, int $version = 1): self
    {
        $document = new Document($uri, $languageId ?? self::languageId($uri), $version, $text);
        $this->documents[$uri] = $document;
        $this->get(DocumentStore::class)->open($document);

        return $this;
    }

    /**
     * Indexes the sources of the project through every registered source index
     * provider, taking the open documents when none are given.
     *
     * @param array<string, string> $sources the text of each source, by URI
     */
    public function index(array $sources = []): self
    {
        if ([] === $sources) {
            $sources = array_map(static fn (Document $document): string => $document->text, $this->documents);
        }
        $pipeline = $this->get(SourceIndexProviderPipeline::class);
        $pipeline->begin($this->project);
        foreach ($sources as $uri => $text) {
            $pipeline->index($this->project, new SourceDocument($uri, $this->documents[$uri]->languageId ?? self::languageId($uri), $text));
        }
        $pipeline->finish($this->project);

        return $this;
    }

    /**
     * Loads one section of the runtime metadata the bridge reports.
     *
     * @param array<string, mixed> $payload
     */
    public function runtime(string $section, array $payload): self
    {
        $this->get(RuntimeSnapshotLoaderRegistry::class)->load($this->project, ['sections' => [$section => $payload]]);

        return $this;
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    public function offset(string $uri, int $offset): array
    {
        return LspRequests::offset($uri, $this->text($uri), $offset);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    public function at(string $uri, string $needle): array
    {
        return LspRequests::at($uri, $this->text($uri), $needle);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    public function inside(string $uri, string $needle): array
    {
        return LspRequests::inside($uri, $this->text($uri), $needle);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    public function after(string $uri, string $needle): array
    {
        return LspRequests::after($uri, $this->text($uri), $needle);
    }

    /**
     * @param list<array<array-key, mixed>>|null $items
     *
     * @return list<mixed>
     */
    public function labels(?array $items): array
    {
        return self::column($items, 'label');
    }

    /**
     * @param list<array<array-key, mixed>>|null $diagnostics
     *
     * @return list<mixed>
     */
    public function codes(?array $diagnostics): array
    {
        return self::column($diagnostics, 'code');
    }

    /**
     * @param list<array<array-key, mixed>>|null $diagnostics
     *
     * @return list<mixed>
     */
    public function messages(?array $diagnostics): array
    {
        return self::column($diagnostics, 'message');
    }

    /**
     * @param list<array<array-key, mixed>>|null $locations
     *
     * @return list<mixed>
     */
    public function targets(?array $locations): array
    {
        return self::column($locations, 'uri');
    }

    /**
     * @param list<array<array-key, mixed>>|null $codeLenses
     *
     * @return list<mixed>
     */
    public function titles(?array $codeLenses): array
    {
        $titles = [];
        foreach ($codeLenses ?? [] as $codeLens) {
            $command = $codeLens['command'] ?? null;
            $titles[] = \is_array($command) ? $command['title'] ?? null : null;
        }

        return $titles;
    }

    /** @param array<array-key, mixed>|null $hover */
    public function hoverText(?array $hover): string
    {
        $contents = $hover['contents'] ?? null;
        $value = \is_array($contents) ? $contents['value'] ?? null : null;

        return \is_string($value) ? $value : '';
    }

    private function text(string $uri): string
    {
        return ($this->documents[$uri] ?? throw new \InvalidArgumentException(\sprintf('The document "%s" is not open.', $uri)))->text;
    }

    /**
     * @param list<array<array-key, mixed>>|null $items
     *
     * @return list<mixed>
     */
    private static function column(?array $items, string $key): array
    {
        return array_column($items ?? [], $key);
    }

    private static function languageId(string $uri): string
    {
        $extension = strtolower(pathinfo($uri, \PATHINFO_EXTENSION));

        return self::LANGUAGE_IDS[$extension] ?? throw new \InvalidArgumentException(\sprintf('The language of "%s" cannot be told from its extension.', $uri));
    }
}
