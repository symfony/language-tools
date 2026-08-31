<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Feature\Configuration\ConfigurationValidationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Server\SensitiveDataRedactor;

/** @phpstan-import-type CheckError from CheckResult */
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
    public function runtime(Project $project, string $workspace, ?\Throwable $cause, string $fallback): array
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
            $error['cause'] = $this->cause($cause, $workspace);
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

    /** @return array{class: class-string<\Throwable>, message: string} */
    private function cause(\Throwable $cause, string $workspace): array
    {
        return [
            'class' => $cause::class,
            'message' => $this->redactor->redact($cause->getMessage(), [$workspace]),
        ];
    }

    private function runtimeMessage(?\Throwable $cause, string $fallback): string
    {
        if (null === $cause) {
            return $fallback;
        }
        $message = $cause->getMessage();
        if (preg_match('/^(?:The project bridge |Unable to (?:start|install) the project bridge)/', $message)) {
            return $message;
        }

        return $fallback;
    }
}
