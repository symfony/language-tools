<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentProcessorChainValidator
{
    private const ARGUMENT_PROCESSORS = ['default', 'enum', 'key'];
    private const BUILT_IN_PROCESSORS = ['base64', 'bool', 'const', 'csv', 'default', 'defined', 'enum', 'file', 'float', 'int', 'json', 'key', 'not', 'query_string', 'require', 'resolve', 'shuffle', 'string', 'trim', 'url', 'urlencode'];

    /**
     * @param list<string> $processorChain
     *
     * @return list<EnvironmentProcessorChainIssue>
     */
    public function validate(array $processorChain, EnvironmentIndex $index): array
    {
        $installedProcessors = $index->processors();
        $issues = [];
        $skipNext = false;
        $previousProcessor = null;
        foreach ($processorChain as $processor) {
            if ($skipNext) {
                $skipNext = false;
                $previousProcessor = null;
                continue;
            }
            if ('' === $processor) {
                $issues[] = new EnvironmentProcessorChainIssue('env.malformed_chain', 'Environment processor chains cannot contain empty segments.');
                continue;
            }
            if (\in_array($processor, self::ARGUMENT_PROCESSORS, true)) {
                $skipNext = true;
            }
            $customProcessorArgument = null !== $previousProcessor && isset($installedProcessors[$previousProcessor]) && !\in_array($previousProcessor, self::BUILT_IN_PROCESSORS, true);
            if ($index->processorsComplete() && !$customProcessorArgument && !isset($installedProcessors[$processor])) {
                $issues[] = new EnvironmentProcessorChainIssue('env.unknown_processor', \sprintf('Environment processor "%s" is not installed.', $processor));
            }
            $previousProcessor = $processor;
        }

        return $issues;
    }
}
