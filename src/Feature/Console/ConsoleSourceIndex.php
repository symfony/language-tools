<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceFactsOverlayOrder;

/** @extends AbstractSourceFactsIndex<ConsoleSourceFacts> */
final class ConsoleSourceIndex extends AbstractSourceFactsIndex
{
    private const COMMAND = 'Symfony\\Component\\Console\\Command\\Command';

    private bool $indexed = false;

    /** @var array<string, list<ConsoleCommandDeclaration>> */
    private array $declarations = [];

    public function __construct()
    {
        parent::__construct(SourceFactsOverlayOrder::PreserveSavedPosition);
    }

    public function definition(string $className): ConsoleEffectiveDefinition
    {
        $this->index();

        return $this->resolve(ltrim($className, '\\'), []);
    }

    /** @return list<ConsoleCommandDeclaration> */
    public function declarations(string $className): array
    {
        $this->index();

        return $this->declarations[strtolower(ltrim($className, '\\'))] ?? [];
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->declarations = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                $this->declarations[strtolower(ltrim($declaration->className(), '\\'))][] = $declaration;
            }
        }
        $this->indexed = true;
    }

    /** @param array<string, true> $visited */
    private function resolve(string $className, array $visited): ConsoleEffectiveDefinition
    {
        if (0 === strcasecmp(self::COMMAND, $className)) {
            return new ConsoleEffectiveDefinition([], [], true, true);
        }
        $key = strtolower($className);
        if (isset($visited[$key])) {
            return new ConsoleEffectiveDefinition([], [], false, false);
        }
        $declarations = $this->declarations[$key] ?? [];
        if (1 !== \count($declarations)) {
            return new ConsoleEffectiveDefinition([], [], false, false);
        }
        $visited[$key] = true;
        $declaration = $declarations[0];
        $arguments = $declaration->arguments();
        $options = $declaration->options();
        $command = $declaration->isCommand();
        $complete = $declaration->isComplete();

        if (null !== $parent = $declaration->parentClassName()) {
            $parentDefinition = $this->resolve(ltrim($parent, '\\'), $visited);
            $arguments = [...$arguments, ...$parentDefinition->arguments()];
            $options = [...$options, ...$parentDefinition->options()];
            $command = $command || $parentDefinition->isCommand();
            $complete = $complete && $parentDefinition->isComplete();
        }
        foreach ($declaration->traits() as $trait) {
            $traitDefinition = $this->resolve(ltrim($trait, '\\'), $visited);
            $arguments = [...$arguments, ...$traitDefinition->arguments()];
            $options = [...$options, ...$traitDefinition->options()];
            $complete = $complete && $traitDefinition->isComplete();
        }

        $arguments = array_values(array_unique($arguments));
        $options = array_values(array_unique($options));
        sort($arguments);
        sort($options);

        return new ConsoleEffectiveDefinition($arguments, $options, $command, $complete);
    }
}
