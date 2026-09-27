<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Symfony\Lsp\Document\Document;

final class TwigCallableShapeCompletionTest extends TwigCallableProviderTestCase
{
    private const EXTENSION = <<<'PHP'
        <?php
        namespace App\Twig;

        use Twig\Extension\AbstractExtension;
        use Twig\TwigFilter;
        use Twig\TwigFunction;

        /**
         * @psalm-type ButtonOptions = array{size?: 'sm'|'md'|'lg', variant?: 'primary'|'secondary'|string, icon?: IconOptions}
         * @psalm-type IconOptions = array{name?: string, position?: 'start'|'end'}
         * @phpstan-type Attributes = array{
         *     class?: string,
         *     'data-id'?: int,
         *     role: 'button'|'link',
         * }
         */
        final class ButtonExtension extends AbstractExtension
        {
            public function getFunctions(): array
            {
                return [
                    new TwigFunction('button_direct', $this->direct(...)),
                    new TwigFunction('button_alias', $this->alias(...)),
                    new TwigFunction('button_imported', [ButtonRuntime::class, 'render']),
                    new TwigFunction('button_callable', [$this, 'second'], ['needs_environment' => true]),
                    new TwigFunction('button_untyped', $this->untyped(...)),
                ];
            }

            public function getFilters(): array
            {
                return [new TwigFilter('with_attributes', [$this, 'withAttributes'])];
            }

            /**
             * @param array{size?: 'sm'|'md'|'lg', variant?: 'primary'|'secondary'} $options
             */
            public function direct(array $options): string { return ''; }

            /**
             * @param ButtonOptions $options
             */
            public function alias(array $options): string { return ''; }

            /**
             * @param string                 $label
             * @param ?ButtonOptions         $options
             * @phpstan-param Attributes|null $attributes
             * @param array<string, mixed>   $attributes
             */
            public function second(\Twig\Environment $environment, string $label, ?array $options = null, ?array $attributes = null): string { return ''; }

            /**
             * @param Attributes $attributes
             */
            public function withAttributes(string $value, array $attributes): string { return $value; }

            public function untyped(array $options): string { return ''; }
        }
        PHP;

    private const RUNTIME = <<<'PHP'
        <?php
        namespace App\Twig;

        use Twig\Extension\RuntimeExtensionInterface;

        /**
         * @psalm-import-type ButtonOptions from ButtonExtension
         * @phpstan-import-type IconOptions from \App\Twig\ButtonExtension as Icon
         */
        final class ButtonRuntime implements RuntimeExtensionInterface
        {
            /**
             * @param ButtonOptions $options
             * @param Icon          $icon
             */
            public function render(array $options, array $icon = []): string { return ''; }
        }
        PHP;

    public function testCompletesTheKeysAndLiteralValuesOfAnArrayShapeParameter(): void
    {
        $complete = $this->completion();

        self::assertSame(['size', 'variant'], $complete('{{ button_direct({ '));
        self::assertSame(['sm', 'md', 'lg'], $complete("{{ button_direct({ size: '"));
        self::assertSame(['size', 'variant', 'icon'], $complete('{{ button_alias({ '));
        self::assertSame(['name', 'position'], $complete('{{ button_alias({ icon: { '));
        self::assertSame(['primary', 'secondary'], $complete("{{ button_imported({ variant: '"));
    }

    public function testFollowsAliasesImportsAndArgumentPositions(): void
    {
        $complete = $this->completion();

        self::assertSame(['start', 'end'], $complete('{{ button_imported({ icon: { position: "'));
        self::assertSame(['name', 'position'], $complete('{{ button_imported({}, { '));
        self::assertSame(['name', 'position'], $complete('{{ button_imported(icon: { '));
        self::assertSame(['size', 'variant', 'icon'], $complete("{{ button_callable('Save', { "));
        self::assertSame(['size', 'variant', 'icon'], $complete('{{ button_callable(options: { '));
        self::assertSame(['class', 'data-id', 'role'], $complete("{{ button_callable('Save', {}, { "));
        self::assertSame(['button', 'link'], $complete("{% set html = button_callable(attributes: { role: '"));
        self::assertSame(['class', 'data-id', 'role'], $complete("{{ 'Save'|with_attributes({ "));
        self::assertSame([], $complete('{{ button_callable({ '));
        self::assertSame([], $complete('{{ button_untyped({ '));
    }

    public function testFiltersByPrefixAndSkipsKeysAlreadyWritten(): void
    {
        $complete = $this->completion();

        self::assertSame(['variant'], $complete('{{ button_alias({ va'));
        self::assertSame(['variant', 'icon'], $complete("{{ button_alias({ size: 'md', "));
        self::assertSame(['icon'], $complete("{{ button_alias({ 'size': 'md', variant: 'primary', "));
        self::assertSame(['md'], $complete("{{ button_alias({ size: 'm"));
        self::assertSame(['size'], $complete("{{ button_alias({ 's"));
        self::assertSame(['primary', 'secondary'], $complete("{{ button_alias({ size: 'sm', variant: '"));
        self::assertSame([], $complete("{{ button_alias({ icon: { name: '"));
    }

    public function testInsertsKeysThatAreNotNamesAsQuotedStrings(): void
    {
        $items = $this->items("{{ button_callable('Save', {}, { data");

        self::assertSame(['data-id'], array_column($items, 'label'));
        self::assertSame("'data-id'", $items[0]['textEdit']['newText']);
        self::assertSame(['start' => ['line' => 0, 'character' => 33], 'end' => ['line' => 0, 'character' => 37]], $items[0]['textEdit']['range']);
        self::assertSame('(optional) int', $items[0]['detail']);

        $items = $this->items("{{ button_callable('Save', {}, { 'da");
        self::assertSame('data-id', $items[0]['textEdit']['newText']);
        self::assertSame(['role'], array_column($this->items("{{ button_callable('Save', {}, { r"), 'label'));
        self::assertSame("'button'|'link'", $this->items("{{ button_callable('Save', {}, { r")[0]['detail']);
    }

    public function testIgnoresHashesOutsideRecognizedCallableArguments(): void
    {
        $complete = $this->completion();

        self::assertSame([], $complete('{{ { '));
        self::assertSame([], $complete('{{ item.button_direct({ '));
        self::assertSame([], $complete("{{ button_direct({ size: 'sm' ~ '"));
        self::assertSame([], $complete('{{ button_direct(foo({ '));
        self::assertSame([], $complete('{{ button_direct([{ '));
        self::assertSame([], $complete('{{ button_direct({ size: { '));
        self::assertSame([], $complete('{{ unknown({ '));
        self::assertSame([], $complete('{{ button_direct({ size: "#{ '));
    }

    /** @return \Closure(string): list<mixed> */
    private function completion(): \Closure
    {
        return fn (string $text): array => array_column($this->items($text), 'label');
    }

    /** @return list<array{label: string, detail: string, textEdit: array{range: mixed, newText: string}}> */
    private function items(string $text): array
    {
        $environment = $this->providers([
            'file:///workspace/src/Twig/ButtonExtension.php' => self::EXTENSION,
            'file:///workspace/src/Twig/ButtonRuntime.php' => self::RUNTIME,
        ]);
        $uri = 'file:///workspace/templates/probe.html.twig';
        $environment['documents']->open(new Document($uri, 'twig', 1, $text));
        $position = $environment['converter']->toPosition($text, \strlen($text));

        /** @var list<array{label: string, detail: string, textEdit: array{range: mixed, newText: string}}> $items */
        $items = $environment['completion']->complete($environment['requests']->positioned([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]));

        return $items;
    }
}
