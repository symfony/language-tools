<?php

namespace Symfony\Lsp\Check;

use Amp\CancelledException;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Feature\DiagnosticCollector;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Project\ProjectConfiguration;

final class CheckDiagnosticExecutor
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly DiagnosticCollector $diagnostics,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly PositionConverter $positions,
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly CheckErrorFactory $errors,
    ) {
    }

    public function execute(CheckPlan $plan, CheckProjectAnalysis $analysis, CheckRunCancellation $cancellation): CheckDiagnosticExecution
    {
        $diagnostics = [];
        $errors = [];
        $incompleteProjects = [];
        $openDocuments = [];
        $excludedOverlays = [];
        $canceled = false;

        try {
            foreach ($plan->files as $file) {
                if (!$file->excluded || !isset($analysis->diagnosableProjects[$file->project->rootPath], $analysis->preparedTexts[$file->path])) {
                    continue;
                }
                $this->documents->open(new Document($file->uri, $file->languageId, 0, $analysis->preparedTexts[$file->path]));
                $openDocuments[$file->uri] = true;
                $excludedOverlays[$file->uri] = true;
                $this->sourceScanner->updateOpenDocument(['textDocument' => ['uri' => $file->uri]], true);
            }

            $diagnosedCount = 0;
            foreach ($plan->files as $file) {
                $cancellation->checkpoint();
                $cancellation->yieldIfNeeded(++$diagnosedCount);
                $root = $file->project->rootPath;
                if (!isset($analysis->diagnosableProjects[$root])) {
                    continue;
                }
                $cancellation->checkpoint();
                $text = file_get_contents($file->path);
                if (false === $text) {
                    $errors[] = $this->errors->selectedFileUnreadable($file, $plan->workspace);
                    $incompleteProjects[$root] = true;

                    continue;
                }
                if (hash('sha256', $text) !== ($analysis->preparedHashes[$file->path] ?? null)) {
                    $errors[] = $this->errors->fileChanged($file, $plan->workspace);
                    $incompleteProjects[$root] = true;

                    continue;
                }

                if (!$file->excluded) {
                    $this->documents->open(new Document($file->uri, $file->languageId, 0, $text));
                    $openDocuments[$file->uri] = true;
                }
                try {
                    $collection = $this->diagnostics->collectDetailed(['textDocument' => ['uri' => $file->uri]], $file->excluded);
                    if (null !== $collection) {
                        $failedProviders = [];
                        foreach ($collection->failures as $failure) {
                            $errors[] = $this->errors->diagnosticProvider($failure->provider, $failure->error, $file, $plan->workspace);
                            $failedProviders[$failure->provider] = true;
                            $incompleteProjects[$root] = true;
                        }
                        foreach ($collection->diagnostics as $diagnostic) {
                            $cancellation->checkpoint();
                            try {
                                $collected = CheckDiagnostic::fromProtocol(
                                    $file,
                                    $this->projectConfiguration->projectId($file->project),
                                    $text,
                                    $diagnostic->diagnostic,
                                    $this->positions,
                                    $diagnostic->provider,
                                );
                                if (!$this->diagnosticCodes->contains($collected->code)) {
                                    throw new \UnexpectedValueException(\sprintf('Diagnostic code "%s" is not registered.', $collected->code));
                                }
                                $diagnostics[] = $collected;
                            } catch (CancelledException $error) {
                                throw $error;
                            } catch (\Throwable $error) {
                                if (!isset($failedProviders[$diagnostic->provider])) {
                                    $errors[] = $this->errors->diagnosticProvider($diagnostic->provider, $error, $file, $plan->workspace);
                                    $failedProviders[$diagnostic->provider] = true;
                                }
                                $incompleteProjects[$root] = true;
                            }
                        }
                    }
                } catch (CancelledException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    $errors[] = $this->errors->diagnosticProcessing($error, $file, $plan->workspace);
                    $incompleteProjects[$root] = true;
                }
                if (!$file->excluded) {
                    $this->documents->close($file->uri);
                    unset($openDocuments[$file->uri]);
                }
            }
            $cancellation->checkpoint();
        } catch (CancelledException) {
            $canceled = true;
        } finally {
            foreach (array_keys($excludedOverlays) as $uri) {
                $this->sourceScanner->restoreClosedDocument(['textDocument' => ['uri' => $uri]]);
            }
            foreach (array_keys($openDocuments) as $uri) {
                $this->documents->close($uri);
            }
        }

        return new CheckDiagnosticExecution($diagnostics, $errors, $incompleteProjects, $canceled);
    }
}
