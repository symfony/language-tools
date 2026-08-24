<?php

namespace Symfony\Lsp\Check;

use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\TimeoutCancellation;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DiagnosticCollector;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

use function Amp\delay;

final class CheckRunner
{
    public function __construct(
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly ProjectDiscovery $projectDiscovery,
        private readonly ProjectRegistry $projects,
        private readonly ProjectSettings $projectSettings,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly CheckFileSelector $fileSelector,
        private readonly DocumentStore $documents,
        private readonly DiagnosticCollector $diagnostics,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly PositionConverter $positions,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly BaselineManager $baseline,
        private readonly string $version,
    ) {
    }

    public function run(CheckOptions $options): CheckResult
    {
        if (!\function_exists('symfony_lsp_tree_sitter_parse')) {
            throw new CheckOperationalException('The Symfony Language Tools Tree-sitter parser is unavailable. Run: composer tree-sitter:build');
        }

        $deadline = microtime(true) + $options->timeout;
        $timeout = new TimeoutCancellation($options->timeout);
        $workspace = $this->workspace($options->workspace);
        $folder = ['uri' => $this->uriToPathConverter->toUri($workspace)];
        $this->projectConfiguration->load([$folder], $options->configurationPath);
        $this->runtimeConfiguration->configure($options->overrides);
        $this->assertBeforeDeadline($deadline, $options->timeout);

        $projectRoots = [] !== $options->projectRoots
            ? $this->projectRoots($workspace, $options->projectRoots)
            : ($this->projectConfiguration->projectRoots($workspace) ?? []);
        $projects = $this->projectDiscovery->discover([$folder], $projectRoots);
        if ([] !== $options->projectRoots) {
            $this->validateProjectRoots($projectRoots, $projects);
        }
        $this->projectConfiguration->validateProjects($projects);
        $this->projects->replace($projects);
        if ([] === $projects) {
            throw new InvalidConfigurationException('No Symfony project was discovered in the workspace.');
        }
        $this->projectSettings->applyFileSettings($options->overrides);
        $this->assertBeforeDeadline($deadline, $options->timeout);
        $files = $this->fileSelector->select($workspace, $options->selectors);
        $this->assertBeforeDeadline($deadline, $options->timeout);

        $filesByProject = [];
        $selectedProjects = [];
        foreach ($files as $file) {
            $root = $file->project->rootPath();
            $filesByProject[$root][] = $file;
            $selectedProjects[$root] = $file->project;
        }

        $signal = new DeferredCancellation();
        $cancellation = new CompositeCancellation($timeout, $signal->getCancellation());
        $timedOut = false;
        $this->installSignalHandlers($signal);

        $projectResults = [];
        $errors = [];
        $incompleteProjects = [];
        $preparedHashes = [];
        $ready = [];
        try {
            foreach ($selectedProjects as $project) {
                $this->expireDeadline($deadline, $signal, $timedOut);
                $cancellation->throwIfRequested();
                try {
                    $this->sourceScanner->refreshProject($project, $cancellation);
                } catch (CancelledException $error) {
                    throw $error;
                }
                $this->expireDeadline($deadline, $signal, $timedOut);
                $cancellation->throwIfRequested();
                $status = $this->statuses->status($project);
                $projectId = $this->projectConfiguration->projectId($project);
                if ('ready' !== $status['source']['state']) {
                    $errors[] = [
                        'category' => 'operational',
                        'message' => $status['source']['error'] ?? 'Source indexing did not complete.',
                        'project' => $projectId,
                    ];
                    $projectResults[] = $this->projectResult($project, $status, false);
                    continue;
                }

                $prepared = true;
                $preparedCount = 0;
                foreach ($filesByProject[$project->rootPath()] as $file) {
                    $this->expireDeadline($deadline, $signal, $timedOut);
                    $cancellation->throwIfRequested();
                    if (0 === ++$preparedCount % 64) {
                        delay(0, cancellation: $cancellation);
                    }
                    $text = file_get_contents($file->path);
                    if (false === $text) {
                        $errors[] = [
                            'category' => 'operational',
                            'message' => \sprintf('The selected file "%s" became unreadable.', $file->workspacePath),
                            'project' => $projectId,
                        ];
                        $prepared = false;
                        break;
                    }
                    $hash = hash('sha256', $text);
                    if ($hash !== $this->sourceScanner->indexedHash($project, $file->path)) {
                        $this->sourceScanner->refreshUri($file->uri);
                    }
                    if ($hash !== $this->sourceScanner->indexedHash($project, $file->path)) {
                        $errors[] = [
                            'category' => 'operational',
                            'message' => \sprintf('The selected file "%s" could not be prepared for diagnostics.', $file->workspacePath),
                            'project' => $projectId,
                        ];
                        $prepared = false;
                        break;
                    }
                    $preparedHashes[$file->path] = $hash;
                }
                if (!$prepared) {
                    $projectResults[] = $this->projectResult($project, $status, false);
                    continue;
                }

                if ($this->runtimeConfiguration->runtimeIndexing($project)) {
                    $runtimeError = null;
                    try {
                        $this->runtimeInitializer->initialize($project, cancellation: $cancellation);
                    } catch (CancelledException $error) {
                        throw $error;
                    } catch (\Throwable $error) {
                        $runtimeError = $error;
                    }
                    $this->expireDeadline($deadline, $signal, $timedOut);
                    $cancellation->throwIfRequested();
                    $status = $this->statuses->status($project);
                    if ('ready' !== $status['runtime']['state']) {
                        $errors[] = [
                            'category' => 'operational',
                            'message' => $this->runtimeError($runtimeError, $status['runtime']['error'] ?? 'Runtime indexing did not complete.'),
                            'project' => $this->projectConfiguration->projectId($project),
                        ];
                        $projectResults[] = $this->projectResult($project, $status, false);
                        continue;
                    }
                }

                $ready[$project->rootPath()] = true;
                $projectResults[] = $this->projectResult($project, $status, true);
            }

            $diagnostics = [];
            $diagnosedCount = 0;
            foreach ($files as $file) {
                $this->expireDeadline($deadline, $signal, $timedOut);
                $cancellation->throwIfRequested();
                if (0 === ++$diagnosedCount % 64) {
                    delay(0, cancellation: $cancellation);
                }
                if (!isset($ready[$file->project->rootPath()])) {
                    continue;
                }
                $cancellation->throwIfRequested();
                $text = file_get_contents($file->path);
                $projectId = $this->projectConfiguration->projectId($file->project);
                if (false === $text) {
                    $errors[] = [
                        'category' => 'operational',
                        'message' => \sprintf('The selected file "%s" became unreadable.', $file->workspacePath),
                        'project' => $projectId,
                    ];
                    $incompleteProjects[$projectId] = true;
                    continue;
                }
                if (hash('sha256', $text) !== ($preparedHashes[$file->path] ?? null)) {
                    $errors[] = [
                        'category' => 'operational',
                        'message' => \sprintf('The selected file "%s" changed during the diagnostics check.', $file->workspacePath),
                        'project' => $projectId,
                    ];
                    $incompleteProjects[$projectId] = true;
                    continue;
                }

                $this->documents->open(new Document($file->uri, $file->languageId, 0, $text));
                try {
                    $params = ['textDocument' => ['uri' => $file->uri]];
                    foreach ($this->diagnostics->collect($params) ?? [] as $diagnostic) {
                        $this->expireDeadline($deadline, $signal, $timedOut);
                        $cancellation->throwIfRequested();
                        $collected = CheckDiagnostic::fromProtocol(
                            $file,
                            $projectId,
                            $text,
                            $diagnostic,
                            $this->positions,
                        );
                        if (!$this->diagnosticCodes->contains($collected->code)) {
                            throw new \UnexpectedValueException(\sprintf('Diagnostic code "%s" is not registered.', $collected->code));
                        }
                        $diagnostics[] = $collected;
                    }
                } catch (CancelledException $error) {
                    throw $error;
                } catch (\Throwable) {
                    $errors[] = [
                        'category' => 'operational',
                        'message' => \sprintf('Diagnostic collection failed for "%s".', $file->workspacePath),
                        'project' => $projectId,
                    ];
                    $incompleteProjects[$projectId] = true;
                } finally {
                    $this->documents->close($file->uri);
                }
            }
            $this->expireDeadline($deadline, $signal, $timedOut);
            $cancellation->throwIfRequested();
        } catch (CancelledException) {
            $errors[] = [
                'category' => 'operational',
                'message' => $timedOut || $timeout->isRequested()
                    ? \sprintf('The diagnostics check timed out after %s seconds.', $options->timeout)
                    : 'The diagnostics check was canceled.',
            ];
            $diagnostics ??= [];
        } finally {
            $this->restoreSignalHandlers();
        }

        $reportedProjects = [];
        foreach ($projectResults as $projectResult) {
            $reportedProjects[$projectResult->id] = true;
        }
        foreach ($selectedProjects as $project) {
            $projectId = $this->projectConfiguration->projectId($project);
            if (!isset($reportedProjects[$projectId])) {
                $projectResults[] = $this->projectResult($project, $this->statuses->status($project), false);
            }
        }
        $projectResults = array_map(
            static fn (CheckProjectResult $project): CheckProjectResult => isset($incompleteProjects[$project->id]) ? $project->withComplete(false) : $project,
            $projectResults,
        );
        usort($projectResults, static fn (CheckProjectResult $left, CheckProjectResult $right): int => strcmp($left->id, $right->id));
        usort($diagnostics, static fn (CheckDiagnostic $left, CheckDiagnostic $right): int => [
            $left->project,
            $left->path,
            $left->startLine,
            $left->startCharacter,
            $left->endLine,
            $left->endCharacter,
            $left->code,
            $left->message,
        ] <=> [
            $right->project,
            $right->path,
            $right->startLine,
            $right->startCharacter,
            $right->endLine,
            $right->endCharacter,
            $right->code,
            $right->message,
        ]);

        if (microtime(true) >= $deadline && [] === $errors) {
            $errors[] = [
                'category' => 'operational',
                'message' => \sprintf('The diagnostics check timed out after %s seconds.', $options->timeout),
            ];
            $projectResults = array_map(
                static fn (CheckProjectResult $project): CheckProjectResult => $project->withComplete(false),
                $projectResults,
            );
        }

        $stale = [];
        $baselinePath = null;
        if ([] === $errors) {
            $baseline = $this->baseline->apply($workspace, $options, $diagnostics);
            $diagnostics = $baseline['diagnostics'];
            $stale = $baseline['stale'];
            $baselinePath = $baseline['path'];
            if (microtime(true) >= $deadline) {
                $errors[] = [
                    'category' => 'operational',
                    'message' => \sprintf('The diagnostics check timed out after %s seconds.', $options->timeout),
                ];
                $projectResults = array_map(
                    static fn (CheckProjectResult $project): CheckProjectResult => $project->withComplete(false),
                    $projectResults,
                );
            }
        }
        $blockingCount = $this->blockingCount($diagnostics, $options->blockingCodes)
            + ($options->strictBaseline ? \count($stale) : 0);

        return new CheckResult(
            $this->version,
            [] === $errors,
            $projectResults,
            $diagnostics,
            $stale,
            $baselinePath,
            $options->baselineMode,
            $options->strictBaseline,
            $errors,
            $blockingCount,
        );
    }

    /** @param array{root: string, source: array{state: string, error?: string}, runtime: array{state: string, error?: string, stage?: string}} $status */
    private function projectResult(Project $project, array $status, bool $complete): CheckProjectResult
    {
        $reason = $this->runtimeConfiguration->sourceOnlyReason($project);
        if (null !== $reason) {
            $status['runtime'] = ['state' => 'disabled', 'reason' => $reason];
        }

        return new CheckProjectResult(
            $this->projectConfiguration->projectId($project),
            $this->runtimeConfiguration->environment($project),
            null === $reason ? 'runtime' : 'source-only',
            $reason,
            $status['source'],
            $status['runtime'],
            $complete,
        );
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     * @param list<string>|null     $blockingCodes
     */
    private function blockingCount(array $diagnostics, ?array $blockingCodes): int
    {
        return \count(array_filter($diagnostics, static function (CheckDiagnostic $diagnostic) use ($blockingCodes): bool {
            if ('matched' === $diagnostic->baselineState) {
                return false;
            }

            return null === $blockingCodes
                ? 1 === $diagnostic->severity
                : \in_array($diagnostic->code, $blockingCodes, true);
        }));
    }

    private function assertBeforeDeadline(float $deadline, float $timeout): void
    {
        if (microtime(true) >= $deadline) {
            throw new CheckOperationalException(\sprintf('The diagnostics check timed out after %s seconds.', $timeout));
        }
    }

    private function expireDeadline(float $deadline, DeferredCancellation $cancellation, bool &$timedOut): void
    {
        if (!$timedOut && microtime(true) >= $deadline) {
            $timedOut = true;
            $cancellation->cancel();
        }
    }

    private function runtimeError(?\Throwable $error, string $fallback): string
    {
        if (null === $error) {
            return $fallback;
        }
        $message = $error->getMessage();
        if (preg_match('/^(?:The project bridge |Unable to (?:start|install) the project bridge)/', $message)) {
            return $message;
        }

        return $fallback;
    }

    private function workspace(string $workspace): string
    {
        $workspace = Path::canonicalize(Path::isAbsolute($workspace) ? $workspace : Path::join((string) getcwd(), $workspace));
        if (!is_dir($workspace)) {
            throw new InvalidConfigurationException(\sprintf('The workspace "%s" is not a directory.', $workspace));
        }
        if (!is_readable($workspace)) {
            throw new InvalidConfigurationException(\sprintf('The workspace "%s" is unreadable.', $workspace));
        }

        return $workspace;
    }

    /**
     * @param list<string>  $roots
     * @param list<Project> $projects
     */
    private function validateProjectRoots(array $roots, array $projects): void
    {
        $discovered = [];
        foreach ($projects as $project) {
            $discovered[Path::canonicalize($project->rootPath())] = true;
        }
        foreach ($roots as $root) {
            if (!isset($discovered[$root])) {
                throw new InvalidConfigurationException(\sprintf('The project root "%s" was not discovered as a Symfony project.', $root));
            }
        }
    }

    /**
     * @param list<string> $roots
     *
     * @return list<string>
     */
    private function projectRoots(string $workspace, array $roots): array
    {
        $resolved = [];
        $realWorkspace = realpath($workspace);
        foreach ($roots as $root) {
            $path = Path::canonicalize(Path::isAbsolute($root) ? $root : Path::join($workspace, $root));
            $realPath = realpath($path);
            if (($workspace !== $path && !Path::isBasePath($workspace, $path))
                || (false !== $realWorkspace
                    && false !== $realPath
                    && Path::canonicalize($realWorkspace) !== Path::canonicalize($realPath)
                    && !Path::isBasePath(Path::canonicalize($realWorkspace), Path::canonicalize($realPath)))
            ) {
                throw new InvalidConfigurationException(\sprintf('The project root "%s" is outside the workspace.', $root));
            }
            $resolved[] = $path;
        }

        return array_values(array_unique($resolved));
    }

    private function installSignalHandlers(DeferredCancellation $cancellation): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(\SIGINT, static fn () => $cancellation->cancel());
        pcntl_signal(\SIGTERM, static fn () => $cancellation->cancel());
    }

    private function restoreSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        pcntl_signal(\SIGINT, \SIG_DFL);
        pcntl_signal(\SIGTERM, \SIG_DFL);
    }
}
