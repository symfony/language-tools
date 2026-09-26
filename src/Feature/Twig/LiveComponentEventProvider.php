<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class LiveComponentEventProvider implements CompletionProviderInterface, DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    private const AS_LIVE_COMPONENT = 'Symfony\\UX\\LiveComponent\\Attribute\\AsLiveComponent';

    public function __construct(
        private readonly LspRequestFactory $requests,
        private readonly PositionConverter $converter,
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TwigComponentExtractor $extractor,
        private readonly PhpCommentParser $phpComments,
        private readonly PhpParserInterface $phpParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->requests->positioned($params);
        if (null === $request) {
            return null;
        }

        if ('php' !== $request->document->languageId) {
            return null;
        }
        $text = $request->document->text;
        $offset = $request->offset;
        $php = $this->phpParser->parse($text);
        $source = $this->phpComments->mask($text);
        $prefix = $this->completionPrefix($source, $php, $offset);
        if (null === $prefix) {
            return null;
        }
        $start = $this->converter->toPosition($request->document->text, $offset - \strlen($prefix));
        $items = [];
        foreach ($this->indexes->forProject($request->project)->eventNames() as $event) {
            if (str_starts_with($event, $prefix)) {
                $items[] = [
                    'label' => $event,
                    'kind' => 23,
                    'detail' => 'Live component event',
                    'textEdit' => $this->protocol->textEdit(new Range($start, $request->position), $event),
                ];
            }
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $request = $this->requests->positioned($params);
        if (null === $request) {
            return null;
        }

        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return null;
        }
        [$event, $project] = $resolved;
        $details = [\sprintf('Live component event: `%s`', $event->name)];
        foreach ($this->indexes->forProject($project)->events($event->name) as $candidate) {
            if ($candidate->declaration && null !== $candidate->component && null !== $candidate->action) {
                $details[] = \sprintf('Listener: `%s#%s`', $candidate->component, $candidate->action);
            }
        }

        return $this->protocol->markdownHover(implode("\n\n", array_values(array_unique($details))));
    }

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$event, $project] = $resolved;
        $locations = [];
        foreach ($this->indexes->forProject($project)->events($event->name) as $candidate) {
            if ($candidate->declaration) {
                $locations[] = $this->protocol->location($candidate->uri, $candidate->range);
            }
        }

        return $locations;
    }

    public function references(ReferencesRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$event, $project] = $resolved;

        return $this->protocol->locations($request->reported($this->indexes->forProject($project)->events($event->name)));
    }

    private function completionPrefix(string $source, PhpDocument $php, int $offset): ?string
    {
        foreach ($php->methodCalls as $call) {
            if ('emit' !== $call->method
                || PhpMethodReceiverKind::This !== $call->receiverContext->kind
                || null === $call->className
            ) {
                continue;
            }
            $argument = $call->namedOrPositionalArgument('event', 0);
            $start = $argument?->expressionStartOffset;
            $end = $argument?->expressionEndOffset;
            if (null === $start || null === $end || $offset <= $start || $offset > $end || !$this->isLiveComponent($php, $call->className)) {
                continue;
            }
            $before = substr($source, $start, $offset - $start);
            if (preg_match('/^([\'"])([^\'"]*)$/s', $before, $match)) {
                return $match[2];
            }
        }

        return null;
    }

    private function isLiveComponent(PhpDocument $php, string $className): bool
    {
        foreach ($php->attributesOn(PhpAttributeTargetKind::Type, $className) as $attribute) {
            if (self::AS_LIVE_COMPONENT === $attribute->name) {
                return true;
            }
        }

        return false;
    }

    /** @return array{LiveComponentEvent, Project}|null */
    private function resolve(PositionedRequest $request): ?array
    {
        $document = $request->source;
        $event = $this->positionedSymbols->resolve($document, $request->position, $this->extractor->extract($request->project, $document)->events);

        return null === $event ? null : [$event, $request->project];
    }
}
