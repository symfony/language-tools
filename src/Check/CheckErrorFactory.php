<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Feature\Configuration\ConfigurationValidationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeMetadataException;
use Symfony\Lsp\Runtime\UnsupportedSymfonyVersionException;
use Symfony\Lsp\Server\SensitiveDataRedactor;

/**
 * @phpstan-import-type CheckError from CheckResult
 * @phpstan-import-type CheckErrorCause from CheckResult
 * @phpstan-import-type RuntimeMetadataCause from RuntimeMetadataException
 * @phpstan-import-type RuntimeMetadataSectionError from RuntimeMetadataException
 */
final class CheckErrorFactory
{
    public function __construct(
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly SensitiveDataRedactor $redactor,
    ) {
    }

    /** @return CheckError */
    public function sourceIndex(Project $project, string $workspace, string $message): array
    {
        return $this->error('operational', $message, $workspace, [
            'project' => $this->projectConfiguration->projectId($project),
        ]);
    }

    /** @return CheckError */
    public function selectedFileUnreadable(CheckFile $file, string $workspace): array
    {
        return $this->fileError(
            $file,
            $workspace,
            \sprintf('The selected file "%s" became unreadable.', $file->workspacePath),
        );
    }

    /** @return CheckError */
    public function filePreparation(CheckFile $file, string $workspace): array
    {
        return $this->fileError(
            $file,
            $workspace,
            \sprintf('The selected file "%s" could not be prepared for diagnostics.', $file->workspacePath),
        );
    }

    /** @return CheckError */
    public function fileChanged(CheckFile $file, string $workspace): array
    {
        return $this->fileError(
            $file,
            $workspace,
            \sprintf('The selected file "%s" changed during the diagnostics check.', $file->workspacePath),
        );
    }

    /** @return CheckError */
    public function runtime(Project $project, string $workspace, ?\Throwable $cause, string $fallback, bool $verbose): array
    {
        $configurationFailure = $cause instanceof ConfigurationValidationException;
        $error = $this->error(
            $configurationFailure ? 'configuration' : 'operational',
            $this->runtimeMessage($cause, $fallback),
            $workspace,
            [
                'project' => $this->projectConfiguration->projectId($project),
                'environment' => $this->runtimeConfiguration->environment($project),
            ],
        );
        if (null !== $cause && !$configurationFailure) {
            $error['cause'] = $this->cause($cause, $workspace, $verbose);
        }

        return $error;
    }

    /** @return CheckError */
    public function diagnosticProvider(string $provider, \Throwable $cause, CheckFile $file, string $workspace): array
    {
        $error = $this->error(
            'operational',
            \sprintf('Diagnostic provider "%s" failed for "%s".', $provider, $file->workspacePath),
            $workspace,
            [
                'project' => $this->projectConfiguration->projectId($file->project),
                'environment' => $this->runtimeConfiguration->environment($file->project),
                'workspacePath' => $file->workspacePath,
                'provider' => $provider,
            ],
        );
        $error['cause'] = $this->cause($cause, $workspace);

        return $error;
    }

    /** @return CheckError */
    public function diagnosticProcessing(\Throwable $cause, CheckFile $file, string $workspace): array
    {
        $error = $this->error(
            'operational',
            \sprintf('Diagnostic result processing failed for "%s".', $file->workspacePath),
            $workspace,
            [
                'project' => $this->projectConfiguration->projectId($file->project),
                'environment' => $this->runtimeConfiguration->environment($file->project),
                'workspacePath' => $file->workspacePath,
            ],
        );
        $error['cause'] = $this->cause($cause, $workspace);

        return $error;
    }

    /** @return CheckError */
    public function cancellation(CheckRunCancellation $cancellation, string $workspace): array
    {
        return $this->error(
            'operational',
            $cancellation->timedOut()
                ? \sprintf('The diagnostics check timed out after %s seconds.', $cancellation->timeoutSeconds)
                : 'The diagnostics check was canceled.',
            $workspace,
        );
    }

    /** @return CheckError */
    public function timeout(float $timeout, string $workspace): array
    {
        return $this->error(
            'operational',
            \sprintf('The diagnostics check timed out after %s seconds.', $timeout),
            $workspace,
        );
    }

    /** @return CheckError */
    public function internal(\Throwable $cause, string $workspace): array
    {
        $error = $this->error(
            'operational',
            'The diagnostics check failed because of an internal error.',
            $workspace,
        );
        $error['cause'] = $this->cause($cause, $workspace);

        return $error;
    }

    /** @return CheckError */
    private function fileError(CheckFile $file, string $workspace, string $message): array
    {
        return $this->error('operational', $message, $workspace, [
            'project' => $this->projectConfiguration->projectId($file->project),
        ]);
    }

    /**
     * @param array{project?: string, environment?: string, workspacePath?: string, provider?: string} $details
     *
     * @return CheckError
     */
    private function error(string $category, string $message, string $workspace, array $details = []): array
    {
        return [
            'category' => $category,
            'message' => $this->redactor->redact($message, [$workspace]),
            ...$details,
        ];
    }

    /**
     * Section causes come from the application, so they stay behind `--verbose`.
     *
     * @return CheckErrorCause
     */
    private function cause(\Throwable $cause, string $workspace, bool $verbose = false): array
    {
        $entry = [
            'class' => $cause::class,
            'message' => $this->redactor->redact($cause->getMessage(), [$workspace]),
        ];
        if ($verbose && $cause instanceof RuntimeMetadataException && [] !== $cause->sectionErrors) {
            $entry['sections'] = array_map(
                fn (array $sectionError): array => [
                    'section' => $sectionError['section'],
                    'chain' => array_map(fn (array $link): array => $this->causeLink($link, $workspace), $sectionError['chain']),
                ],
                $cause->sectionErrors,
            );
        }

        return $entry;
    }

    /**
     * @param RuntimeMetadataCause $link
     *
     * @return RuntimeMetadataCause
     */
    private function causeLink(array $link, string $workspace): array
    {
        return [
            'class' => $link['class'],
            'message' => $this->redactor->redact($link['message'], [$workspace]),
            ...isset($link['origin']) ? ['origin' => $this->redactor->redact($link['origin'], [$workspace])] : [],
            'frames' => array_map(fn (string $frame): string => $this->redactor->redact($frame, [$workspace]), $link['frames']),
        ];
    }

    private function runtimeMessage(?\Throwable $cause, string $fallback): string
    {
        if (null === $cause) {
            return $fallback;
        }
        if ($cause instanceof UnsupportedSymfonyVersionException) {
            return $cause->getMessage();
        }
        $message = $cause->getMessage();
        if (preg_match('/^(?:The project bridge |Unable to (?:start|install) the project bridge)/', $message)) {
            return $message;
        }

        return $fallback;
    }
}
