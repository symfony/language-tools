<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Tools\ContentLengthProcessClient;

/**
 * Executes the behavioral scenarios of a manifest against a running language server.
 *
 * Every document change stays in memory, so a run never writes to the sources it
 * asserts on, and every scenario is reported, including the ones that could not run.
 *
 * @phpstan-type ScenarioCheckReport array{phase: string, method: string, status: string, milliseconds: float, fingerprint: string|null, failures: list<string>}
 * @phpstan-type ScenarioReport array{id: string, status: string, checks: list<ScenarioCheckReport>, failures: list<string>}
 * @phpstan-type ScenarioRunReport array{scenarioCount: int, scenarios: list<ScenarioReport>, requestCount: int, assertionFailures: int, violations: list<array{scenario: string, method: string, message: string}>, transportFailure: string|null}
 *
 * @phpstan-import-type Scenario from ScenarioManifest
 * @phpstan-import-type ScenarioEdit from ScenarioManifest
 * @phpstan-import-type ScenarioExpectation from ScenarioManifest
 * @phpstan-import-type ScenarioExpectations from ScenarioManifest
 */
final class ScenarioRunner
{
    public const DIAGNOSTICS = 'diagnostics';

    private const METHODS = [
        'completion' => 'textDocument/completion',
        'hover' => 'textDocument/hover',
        'definition' => 'textDocument/definition',
        'references' => 'textDocument/references',
        'documentLink' => 'textDocument/documentLink',
        'codeLens' => 'textDocument/codeLens',
        'codeAction' => 'textDocument/codeAction',
        'prepareRename' => 'textDocument/prepareRename',
        'rename' => 'textDocument/rename',
    ];
    private const DOCUMENT_METHODS = ['documentLink', 'codeLens', 'codeAction'];
    private const SYNCHRONIZATION_COMMAND = 'symfony.indexStatus';
    private const SYNCHRONIZATION_ATTEMPTS = 3;
    private const SYNCHRONIZATION_DELAY_MICROSECONDS = 50_000;

    private int $requestId = 0;
    private int $requestCount = 0;
    private int $documentVersion = 0;
    private string $scenarioId = '';
    private bool $modified = false;
    private ?string $transportFailure = null;
    /** @var array<string, array{languageId: string, version: int, text: string, original: string}> */
    private array $documents = [];
    /** @var list<array{scenario: string, method: string, message: string}> */
    private array $violations = [];
    /** @var array<string, ScenarioCheckReport> */
    private array $checks = [];

    public function __construct(
        private readonly ContentLengthProcessClient $client,
        private readonly ScenarioLocator $locator,
        private readonly ResponseAssertions $assertions,
        private readonly ProtocolValidator $validator,
        private readonly WorkspaceEditApplier $applier,
        private readonly ResponseFingerprint $fingerprint,
        private readonly float $requestTimeout,
    ) {
    }

    /**
     * @return ScenarioRunReport
     */
    public function run(ScenarioManifest $manifest, string $projectRoot): array
    {
        $this->reset();
        $projectRoot = realpath($projectRoot) ?: $projectRoot;
        $scenarios = [];
        foreach ($manifest->scenarios as $definition) {
            $scenarios[] = $this->runScenario($definition, $projectRoot);
        }

        return $this->report($scenarios);
    }

    /**
     * Reports every scenario as failed when the server never became usable.
     *
     * @return ScenarioRunReport
     */
    public static function unavailable(ScenarioManifest $manifest, string $reason): array
    {
        $scenarios = [];
        foreach ($manifest->scenarios as $definition) {
            $checks = [];
            foreach (self::expectedChecks($definition) as [$phase, $method]) {
                $checks[] = self::erroredCheck($phase, $method, $reason);
            }
            $scenarios[] = ['id' => $definition['id'], 'status' => 'error', 'checks' => $checks, 'failures' => [$reason]];
        }

        return self::reportOf($scenarios, 0, [], $reason);
    }

    private function reset(): void
    {
        $this->requestId = 0;
        $this->requestCount = 0;
        $this->documentVersion = 0;
        $this->transportFailure = null;
        $this->documents = [];
        $this->violations = [];
    }

    /**
     * @param list<ScenarioReport> $scenarios
     *
     * @return ScenarioRunReport
     */
    private function report(array $scenarios): array
    {
        return self::reportOf($scenarios, $this->requestCount, $this->violations, $this->transportFailure);
    }

    /**
     * @param list<ScenarioReport>                                           $scenarios
     * @param list<array{scenario: string, method: string, message: string}> $violations
     *
     * @return ScenarioRunReport
     */
    private static function reportOf(array $scenarios, int $requestCount, array $violations, ?string $transportFailure): array
    {
        $assertionFailures = 0;
        foreach ($scenarios as $scenario) {
            foreach ($scenario['checks'] as $check) {
                $assertionFailures += \count($check['failures']);
            }
        }

        return [
            'scenarioCount' => \count($scenarios),
            'scenarios' => $scenarios,
            'requestCount' => $requestCount,
            'assertionFailures' => $assertionFailures,
            'violations' => $violations,
            'transportFailure' => $transportFailure,
        ];
    }

    /**
     * @param Scenario $definition
     *
     * @return ScenarioReport
     */
    private function runScenario(array $definition, string $projectRoot): array
    {
        $this->scenarioId = $definition['id'];
        $this->checks = [];
        $this->modified = false;
        $failures = [];
        try {
            if (null !== $this->transportFailure) {
                throw new ServerTransportException(\sprintf('The server is unavailable: %s', $this->transportFailure));
            }
            $this->execute($definition, $projectRoot);
        } catch (\Throwable $exception) {
            $failures[] = $exception->getMessage();
        } finally {
            try {
                $this->restore($definition, $projectRoot);
            } catch (\Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }
        $checks = $this->reportedChecks($definition, $failures);
        $status = [] === $failures ? 'pass' : 'error';
        foreach ($checks as $check) {
            if ('error' === $check['status']) {
                $status = 'error';
            } elseif ('fail' === $check['status'] && 'pass' === $status) {
                $status = 'fail';
            }
        }

        return ['id' => $this->scenarioId, 'status' => $status, 'checks' => $checks, 'failures' => $failures];
    }

    /**
     * @param Scenario $definition
     */
    private function execute(array $definition, string $projectRoot): void
    {
        $document = $this->locator->locate($projectRoot, $definition, $this->overlays());
        $this->openDocument($document);
        $this->check('baseline', $definition, $definition['expect'], $document->uri, $document->position, $projectRoot);
        $edit = $definition['edit'] ?? null;
        if (null === $edit) {
            return;
        }
        $this->applyTextEdit($edit, $projectRoot);
        $query = $this->query($definition, $edit, $projectRoot);
        $this->check('edit', $definition, $edit['expect'], $query->uri, $query->position, $projectRoot);
        $title = $edit['applyCodeAction'] ?? null;
        if (null === $title) {
            return;
        }
        $afterFix = $edit['afterFix'] ?? null;
        if (null === $afterFix || [] === $afterFix) {
            throw new ScenarioStepException(\sprintf('The scenario applies "%s" without declaring what must hold afterwards.', $title));
        }
        $this->applyCodeAction($title, $query->uri, $query->position, $projectRoot);
        $fixed = $this->query($definition, $edit, $projectRoot);
        $this->check('afterFix', $definition, $afterFix, $fixed->uri, $fixed->position, $projectRoot);
    }

    /**
     * @param Scenario     $definition
     * @param ScenarioEdit $edit
     */
    private function query(array $definition, array $edit, string $projectRoot): ScenarioDocument
    {
        return $this->locator->locate($projectRoot, [
            'file' => $definition['file'],
            'anchor' => $edit['anchor'] ?? $definition['anchor'],
            'offset' => isset($edit['anchor']) ? ($edit['offset'] ?? 0) : $definition['offset'],
        ], $this->overlays());
    }

    /**
     * @param ScenarioEdit $edit
     */
    private function applyTextEdit(array $edit, string $projectRoot): void
    {
        $target = $this->locator->locate($projectRoot, ['file' => $edit['file'], 'anchor' => $edit['before']], $this->overlays());
        $this->openDocument($target);
        $this->changeDocument($target->uri, substr_replace($target->text, $edit['after'], $target->anchorOffset, \strlen($edit['before'])));
    }

    private function applyCodeAction(string $title, string $uri, Position $position, string $projectRoot): void
    {
        $diagnostics = $this->diagnostics($uri) ?? throw new ScenarioStepException($this->missingDiagnostics($uri));
        if ([] === $diagnostics) {
            throw new ScenarioStepException(\sprintf('The server reported no diagnostic to fix with "%s".', $title));
        }
        $actions = $this->result('codeAction', $this->request('textDocument/codeAction', $this->codeActionParameters($uri, $position, $diagnostics)), $projectRoot, $uri);
        $matching = [];
        foreach (\is_array($actions) ? $actions : [] as $action) {
            if (\is_array($action) && $title === ($action['title'] ?? null)) {
                $matching[] = $action;
            }
        }
        if (1 !== \count($matching)) {
            throw new ScenarioStepException(\sprintf('The server offered %d code actions titled "%s" instead of exactly one.', \count($matching), $title));
        }
        $edit = $matching[0]['edit'] ?? null;
        if (!\is_array($edit)) {
            throw new ScenarioStepException(\sprintf('The code action "%s" does not carry a workspace edit.', $title));
        }
        foreach ($this->applier->apply($edit, $this->overlays(), $projectRoot) as $changedUri => $text) {
            $this->changeDocument($changedUri, $text);
        }
    }

    /**
     * @param Scenario             $definition
     * @param ScenarioExpectations $expect
     */
    private function check(string $phase, array $definition, array $expect, string $uri, Position $position, string $projectRoot): void
    {
        foreach ($expect as $method => $expectation) {
            $startedAt = microtime(true);
            try {
                $result = $this->resolve($method, $definition, $uri, $position, $projectRoot);
                $projected = $this->assertions->project($method, $result, $projectRoot, $uri, $this->position($position));
                $failures = $this->assertions->compare($method, $projected, $expectation);
                $this->record($phase, $method, [] === $failures ? 'pass' : 'fail', $startedAt, $this->fingerprint->hash($result, $projectRoot), $failures);
            } catch (ServerTransportException $exception) {
                $this->record($phase, $method, 'error', $startedAt, null, [$exception->getMessage()]);

                throw $exception;
            } catch (\Throwable $exception) {
                $this->record($phase, $method, 'error', $startedAt, null, [$exception->getMessage()]);
            }
        }
    }

    /**
     * @param Scenario $definition
     */
    private function resolve(string $method, array $definition, string $uri, Position $position, string $projectRoot): mixed
    {
        if (self::DIAGNOSTICS === $method) {
            return $this->diagnostics($uri) ?? throw new ScenarioStepException($this->missingDiagnostics($uri));
        }
        $lspMethod = self::METHODS[$method] ?? throw new ScenarioStepException(\sprintf('Method "%s" is not part of the scenario protocol.', $method));

        return $this->result($method, $this->request($lspMethod, $this->parameters($method, $definition, $uri, $position)), $projectRoot, $uri);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function result(string $method, array $response, string $projectRoot, string $uri): mixed
    {
        $error = $response['error'] ?? null;
        if (null !== $error) {
            $message = \is_array($error) && \is_string($error['message'] ?? null) ? $error['message'] : json_encode($error);

            throw new ScenarioStepException(\sprintf('The %s request failed: %s', $method, $message));
        }
        $result = $response['result'] ?? null;
        foreach ($this->validator->validate(self::METHODS[$method] ?? $method, $result, $projectRoot, $this->overlays(), $uri) as $violation) {
            $this->violations[] = ['scenario' => $this->scenarioId, 'method' => $method, 'message' => $violation];
        }

        return $result;
    }

    /**
     * @param Scenario $definition
     *
     * @return array<string, mixed>
     */
    private function parameters(string $method, array $definition, string $uri, Position $position): array
    {
        if ('codeAction' === $method) {
            return $this->codeActionParameters($uri, $position, $this->diagnostics($uri) ?? throw new ScenarioStepException($this->missingDiagnostics($uri)));
        }
        $parameters = ['textDocument' => ['uri' => $uri]];
        if (!\in_array($method, self::DOCUMENT_METHODS, true)) {
            $parameters['position'] = $this->position($position);
        }
        if ('references' === $method) {
            $parameters['context'] = ['includeDeclaration' => true];
        }
        if ('rename' === $method) {
            $parameters['newName'] = $definition['newName'] ?? throw new ScenarioStepException('The scenario expects rename results without declaring "newName".');
        }

        return $parameters;
    }

    /**
     * @param list<mixed> $diagnostics
     *
     * @return array<string, mixed>
     */
    private function codeActionParameters(string $uri, Position $position, array $diagnostics): array
    {
        $start = null;
        $end = null;
        foreach ($diagnostics as $diagnostic) {
            $range = \is_array($diagnostic) ? ($diagnostic['range'] ?? null) : null;
            if (!\is_array($range)) {
                continue;
            }
            $rangeStart = $this->boundary($range['start'] ?? null);
            $rangeEnd = $this->boundary($range['end'] ?? null);
            if (null === $rangeStart || null === $rangeEnd) {
                continue;
            }
            $start = null === $start || $this->earlier($rangeStart, $start) ? $rangeStart : $start;
            $end = null === $end || $this->earlier($end, $rangeEnd) ? $rangeEnd : $end;
        }
        $start ??= $this->position($position);
        $end ??= $this->position($position);

        return [
            'textDocument' => ['uri' => $uri],
            'range' => ['start' => $start, 'end' => $end],
            'context' => ['diagnostics' => $diagnostics],
        ];
    }

    /**
     * @return array{line: int, character: int}|null
     */
    private function boundary(mixed $position): ?array
    {
        return \is_array($position) && \is_int($position['line'] ?? null) && \is_int($position['character'] ?? null)
            ? ['line' => $position['line'], 'character' => $position['character']]
            : null;
    }

    /**
     * @param array{line: int, character: int} $left
     * @param array{line: int, character: int} $right
     */
    private function earlier(array $left, array $right): bool
    {
        return [$left['line'], $left['character']] < [$right['line'], $right['character']];
    }

    /**
     * @return array{line: int, character: int}
     */
    private function position(Position $position): array
    {
        return ['line' => $position->line, 'character' => $position->character];
    }

    /**
     * @return list<mixed>|null the diagnostics published for the current version of the document
     */
    private function diagnostics(string $uri): ?array
    {
        $version = $this->documents[$uri]['version'] ?? throw new ScenarioStepException(\sprintf('Document "%s" is not open.', $uri));
        $published = $this->published($uri, $version);
        for ($attempt = 0; null === $published && $attempt < self::SYNCHRONIZATION_ATTEMPTS; ++$attempt) {
            if (0 < $attempt) {
                usleep(self::SYNCHRONIZATION_DELAY_MICROSECONDS);
            }
            $this->request('workspace/executeCommand', ['command' => self::SYNCHRONIZATION_COMMAND]);
            $published = $this->published($uri, $version);
        }

        return $published;
    }

    /**
     * @return list<mixed>|null
     */
    private function published(string $uri, int $version): ?array
    {
        foreach (array_reverse($this->client->notifications()) as $notification) {
            $parameters = $notification['params'] ?? null;
            if ('textDocument/publishDiagnostics' !== ($notification['method'] ?? null) || !\is_array($parameters)) {
                continue;
            }
            $diagnostics = $parameters['diagnostics'] ?? null;
            if ($uri === ($parameters['uri'] ?? null) && $version === ($parameters['version'] ?? null) && \is_array($diagnostics)) {
                return array_values($diagnostics);
            }
        }

        return null;
    }

    private function missingDiagnostics(string $uri): string
    {
        return \sprintf('The server published no diagnostics for version %d of "%s".', $this->documents[$uri]['version'] ?? 0, $uri);
    }

    private function openDocument(ScenarioDocument $document): void
    {
        if (isset($this->documents[$document->uri])) {
            return;
        }
        $version = ++$this->documentVersion;
        $this->notify('textDocument/didOpen', ['textDocument' => [
            'uri' => $document->uri,
            'languageId' => $document->languageId,
            'version' => $version,
            'text' => $document->text,
        ]]);
        $this->documents[$document->uri] = [
            'languageId' => $document->languageId,
            'version' => $version,
            'text' => $document->text,
            'original' => $document->text,
        ];
    }

    private function changeDocument(string $uri, string $text): void
    {
        $document = $this->documents[$uri] ?? throw new ScenarioStepException(\sprintf('Document "%s" is not open.', $uri));
        $this->modified = true;
        $version = ++$this->documentVersion;
        $this->notify('textDocument/didChange', [
            'textDocument' => ['uri' => $uri, 'version' => $version],
            'contentChanges' => [['text' => $text]],
        ]);
        $this->documents[$uri] = ['languageId' => $document['languageId'], 'version' => $version, 'text' => $text, 'original' => $document['original']];
    }

    /**
     * @param Scenario $definition
     */
    private function restore(array $definition, string $projectRoot): void
    {
        try {
            if (null !== $this->transportFailure) {
                return;
            }
            $restored = $this->modified;
            foreach ($this->documents as $uri => $document) {
                if ($document['text'] !== $document['original']) {
                    $this->changeDocument($uri, $document['original']);
                }
            }
            if ($restored) {
                $baseline = $this->locator->locate($projectRoot, $definition, $this->overlays());
                $this->check('restored', $definition, $definition['expect'], $baseline->uri, $baseline->position, $projectRoot);
            }
        } finally {
            $documents = $this->documents;
            $this->documents = [];
            foreach (array_keys($documents) as $uri) {
                if (null === $this->transportFailure) {
                    $this->notify('textDocument/didClose', ['textDocument' => ['uri' => $uri]]);
                }
            }
        }
    }

    /**
     * @param Scenario     $definition
     * @param list<string> $failures
     *
     * @return list<ScenarioCheckReport>
     */
    private function reportedChecks(array $definition, array $failures): array
    {
        $reason = $failures[0] ?? 'The scenario did not run this check.';
        $checks = [];
        foreach (self::expectedChecks($definition) as [$phase, $method]) {
            $checks[] = $this->checks[$phase.'|'.$method] ?? self::erroredCheck($phase, $method, $reason);
        }

        return $checks;
    }

    /**
     * @return ScenarioCheckReport
     */
    private static function erroredCheck(string $phase, string $method, string $reason): array
    {
        return [
            'phase' => $phase,
            'method' => $method,
            'status' => 'error',
            'milliseconds' => 0.0,
            'fingerprint' => null,
            'failures' => [$reason],
        ];
    }

    /**
     * @param Scenario $definition
     *
     * @return list<array{string, string}>
     */
    private static function expectedChecks(array $definition): array
    {
        $checks = [];
        $baseline = array_keys($definition['expect']);
        foreach ($baseline as $method) {
            $checks[] = ['baseline', $method];
        }
        $edit = $definition['edit'] ?? null;
        if (null === $edit) {
            return $checks;
        }
        foreach (array_keys($edit['expect']) as $method) {
            $checks[] = ['edit', $method];
        }
        foreach (array_keys($edit['afterFix'] ?? []) as $method) {
            $checks[] = ['afterFix', $method];
        }
        foreach ($baseline as $method) {
            $checks[] = ['restored', $method];
        }

        return $checks;
    }

    /**
     * @param list<string> $failures
     */
    private function record(string $phase, string $method, string $status, float $startedAt, ?string $fingerprint, array $failures): void
    {
        $this->checks[$phase.'|'.$method] = [
            'phase' => $phase,
            'method' => $method,
            'status' => $status,
            'milliseconds' => round((microtime(true) - $startedAt) * 1000, 1),
            'fingerprint' => $fingerprint,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function request(string $method, array $parameters): array
    {
        $this->assertConnected();
        ++$this->requestCount;
        try {
            return $this->client->request('scenario-'.++$this->requestId, $method, $parameters, $this->requestTimeout);
        } catch (\Throwable $exception) {
            $this->transportFailure = $exception->getMessage();

            throw new ServerTransportException(\sprintf('The %s request broke the server connection: %s', $method, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function notify(string $method, array $parameters): void
    {
        $this->assertConnected();
        try {
            $this->client->notify($method, $parameters, $this->requestTimeout);
        } catch (\Throwable $exception) {
            $this->transportFailure = $exception->getMessage();

            throw new ServerTransportException(\sprintf('The %s notification broke the server connection: %s', $method, $exception->getMessage()), 0, $exception);
        }
    }

    private function assertConnected(): void
    {
        if (null !== $this->transportFailure) {
            throw new ServerTransportException(\sprintf('The server is unavailable: %s', $this->transportFailure));
        }
    }

    /**
     * @return array<string, string>
     */
    private function overlays(): array
    {
        return array_map(static fn (array $document): string => $document['text'], $this->documents);
    }
}
