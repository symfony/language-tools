<?php

namespace Symfony\Lsp\Check;

use Amp\CancelledException;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationException;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Runtime\PartialRuntimeMetadataException;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

final class CheckProjectAnalyzer
{
    public function __construct(
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly CheckErrorFactory $errors,
        private readonly CheckProfiler $profiler,
    ) {
    }

    public function analyze(CheckPlan $plan, CheckRunCancellation $cancellation, bool $verbose): CheckProjectAnalysis
    {
        $errors = [];
        $preparedHashes = [];
        $preparedTexts = [];
        $diagnosable = [];
        $complete = [];
        $statuses = [];
        $canceled = false;

        try {
            foreach ($plan->projects as $project) {
                $root = $project->rootPath;
                $cancellation->checkpoint();
                $this->profiler->projectPhase(
                    $project,
                    'sourceIndex',
                    fn () => $this->sourceScanner->refreshProject($project->project, $cancellation->cancellation()),
                );
                $cancellation->checkpoint();
                $status = $this->statuses->status($project->project);
                $statuses[$root] = $status;
                if ('ready' !== $status['source']['state']) {
                    $errors[] = $this->errors->sourceIndex(
                        $project,
                        $plan->workspace,
                        $status['source']['error'] ?? 'Source indexing did not complete.',
                    );
                    $complete[$root] = false;

                    continue;
                }

                $prepared = $this->profiler->projectPhase($project, 'filePreparation', function () use ($plan, $project, $root, $cancellation, &$errors, &$preparedHashes, &$preparedTexts): bool {
                    $preparedCount = 0;
                    foreach ($plan->filesByProject[$root] as $file) {
                        $cancellation->checkpoint();
                        $cancellation->yieldIfNeeded(++$preparedCount);
                        $text = file_get_contents($file->path);
                        if (false === $text) {
                            $errors[] = $this->errors->selectedFileUnreadable($file, $plan->workspace);

                            return false;
                        }
                        $hash = hash('sha256', $text);
                        if (!$file->excluded && $hash !== $this->sourceScanner->indexedHash($project->project, $file->path)) {
                            $this->sourceScanner->refreshUri($file->uri);
                        }
                        if (!$file->excluded && $hash !== $this->sourceScanner->indexedHash($project->project, $file->path)) {
                            $errors[] = $this->errors->filePreparation($file, $plan->workspace);

                            return false;
                        }
                        $preparedHashes[$file->path] = $hash;
                        if ($file->excluded) {
                            $preparedTexts[$file->path] = $text;
                        }
                    }

                    return true;
                });
                if (!$prepared) {
                    $complete[$root] = false;

                    continue;
                }

                if ($this->runtimeConfiguration->runtimeIndexing($project->project)) {
                    $ready = $this->profiler->projectPhase($project, 'runtimeIndex', function () use ($plan, $project, $root, $cancellation, $verbose, &$errors, &$diagnosable, &$complete, &$statuses): bool {
                        $runtimeError = null;
                        try {
                            $this->runtimeInitializer->initialize($project->project, cancellation: $cancellation->cancellation());
                        } catch (CancelledException $error) {
                            throw $error;
                        } catch (\Throwable $error) {
                            $runtimeError = $error;
                        }
                        $cancellation->checkpoint();
                        $status = $this->statuses->status($project->project);
                        $statuses[$root] = $status;
                        if ('ready' === $status['runtime']['state']) {
                            return true;
                        }

                        $errors[] = $this->errors->runtime(
                            $project,
                            $plan->workspace,
                            $runtimeError,
                            $status['runtime']['error'] ?? 'Runtime indexing did not complete.',
                            $verbose,
                        );
                        $complete[$root] = false;
                        if ($runtimeError instanceof ConfigurationValidationException || $runtimeError instanceof PartialRuntimeMetadataException) {
                            $diagnosable[$root] = true;
                        }

                        return false;
                    });
                    if (!$ready) {
                        continue;
                    }
                }

                $diagnosable[$root] = true;
                $complete[$root] = true;
            }
        } catch (CancelledException) {
            $canceled = true;
        }

        return new CheckProjectAnalysis(
            $errors,
            $preparedHashes,
            $preparedTexts,
            $diagnosable,
            $complete,
            $statuses,
            $canceled,
        );
    }
}
