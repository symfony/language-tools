<?php

function symfonyLspBridgeConsoleSection(SymfonyLspBridgeContext $context): array
{
    $commands = [];
    $warnings = [];
    $complete = true;
    if (!class_exists(Symfony\Component\Console\Command\Command::class)) {
        $complete = false;
    } else {
        try {
            [$commands, $complete, $warnings] = symfonyLspBridgeConsoleDefinitions($context->application(), $context->project());
        } catch (Throwable) {
            $complete = false;
            $warnings[] = 'The Console command definitions are unavailable.';
        }
    }
    sort($warnings);

    return symfonyLspBridgeFinalizeSection([
        'complete' => $complete,
        'commands' => $commands,
        'warnings' => $warnings,
    ]);
}

/** @return array{list<array{class: string, file: string, arguments: list<string>, options: list<string>, complete: bool}>, bool, list<string>} */
function symfonyLspBridgeConsoleDefinitions(object $application, string $project): array
{
    if (!method_exists($application, 'all') || !method_exists($application, 'getDefinition')) {
        return [[], false, ['The Console application definition is unavailable.']];
    }
    $applicationDefinition = $application->getDefinition();
    $applicationArguments = symfonyLspBridgeConsoleDefinitionNames($applicationDefinition, 'getArguments');
    $applicationOptions = symfonyLspBridgeConsoleDefinitionNames($applicationDefinition, 'getOptions');
    $commands = [];
    $complete = true;
    $warnings = [];
    foreach ($application->all() as $command) {
        if (!is_object($command)) {
            $complete = false;
            continue;
        }
        try {
            $command = symfonyLspBridgeConsoleResolveCommand($command);
            $class = symfonyLspBridgeConsoleCommandClass($command);
            if (null === $class) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();
            if (!is_string($file) || !symfonyLspBridgeConsoleProjectFile($project, $file)) {
                continue;
            }
            if (!method_exists($command, 'getDefinition')) {
                $complete = false;
                continue;
            }
            $definition = $command->getDefinition();
            $arguments = array_values(array_unique([
                ...$applicationArguments,
                ...symfonyLspBridgeConsoleDefinitionNames($definition, 'getArguments'),
            ]));
            $options = array_values(array_unique([
                ...$applicationOptions,
                ...symfonyLspBridgeConsoleDefinitionNames($definition, 'getOptions'),
            ]));
            sort($arguments);
            sort($options);
            $key = strtolower(ltrim($class, '\\'));
            $item = [
                'class' => ltrim($class, '\\'),
                'file' => realpath($file) ?: $file,
                'arguments' => $arguments,
                'options' => $options,
                'complete' => true,
            ];
            if (isset($commands[$key])) {
                $previous = $commands[$key];
                $item['arguments'] = array_values(array_unique([...$previous['arguments'], ...$item['arguments']]));
                $item['options'] = array_values(array_unique([...$previous['options'], ...$item['options']]));
                sort($item['arguments']);
                sort($item['options']);
                $item['complete'] = $previous['complete']
                    && $previous['arguments'] === $arguments
                    && $previous['options'] === $options;
            }
            $commands[$key] = $item;
        } catch (Throwable) {
            $complete = false;
            $warnings[] = 'A Console command definition is unavailable.';
        }
    }
    ksort($commands);

    return [array_values($commands), $complete, array_values(array_unique($warnings))];
}

function symfonyLspBridgeConsoleResolveCommand(object $command): object
{
    if (class_exists(Symfony\Component\Console\Command\LazyCommand::class)
        && $command instanceof Symfony\Component\Console\Command\LazyCommand
    ) {
        return $command->getCommand();
    }

    return $command;
}

function symfonyLspBridgeConsoleCommandClass(object $command): ?string
{
    if (method_exists($command, 'getCode')) {
        try {
            $code = $command->getCode();
            if (is_array($code) && (is_object($code[0] ?? null) || is_string($code[0] ?? null))) {
                return is_object($code[0]) ? $code[0]::class : $code[0];
            }
            if (is_object($code) && !$code instanceof Closure) {
                return $code::class;
            }
            if ($code instanceof Closure) {
                $reflection = new ReflectionFunction($code);
                if (is_object($target = $reflection->getClosureThis())) {
                    return $target::class;
                }
                if (null !== $scope = $reflection->getClosureScopeClass()) {
                    return $scope->getName();
                }
            }
        } catch (Throwable) {
        }
    }

    return $command::class;
}

/** @return list<string> */
function symfonyLspBridgeConsoleDefinitionNames(mixed $definition, string $method): array
{
    if (!is_object($definition) || !method_exists($definition, $method)) {
        return [];
    }
    $values = $definition->$method();
    if (!is_array($values)) {
        return [];
    }
    $names = [];
    foreach ($values as $name => $value) {
        if (is_string($name)) {
            $names[] = $name;
        } elseif (is_object($value) && method_exists($value, 'getName') && is_string($value->getName())) {
            $names[] = $value->getName();
        }
    }
    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

function symfonyLspBridgeConsoleProjectFile(string $project, string $file): bool
{
    $root = realpath($project);
    $file = realpath($file);
    if (false === $root || false === $file) {
        return false;
    }
    $root = rtrim(str_replace('\\', '/', $root), '/').'/';
    $file = str_replace('\\', '/', $file);

    return '\\' === DIRECTORY_SEPARATOR
        ? 0 === strncasecmp($file, $root, strlen($root))
        : str_starts_with($file, $root);
}
