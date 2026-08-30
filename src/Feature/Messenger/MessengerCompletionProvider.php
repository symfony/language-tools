<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerCompletionProvider implements CompletionProviderInterface
{
    private const AS_MESSAGE_HANDLER = 'Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler';

    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly YamlConfigurationParser $yaml,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly PhpParserInterface $phpParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $before = substr(
            'php' === $request->document->languageId ? $this->phpComments->mask($request->document->text) : $request->document->text,
            0,
            $offset,
        );
        $lineOffset = (int) strrpos("\n".$before, "\n");
        $kind = null;
        $prefix = '';
        $messengerOptionContext = 'yaml' === $request->document->languageId;
        if ('php' === $request->document->languageId
            && preg_match('/(?:#\[\s*|,\s*)([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\([^)]*$/s', $before, $attribute)
        ) {
            $messengerOptionContext = self::AS_MESSAGE_HANDLER === $this->phpParser->parse($request->document->text)->resolveName($attribute[1]);
        }
        if ($messengerOptionContext && preg_match('/(?:\bbus|default_bus)\s*:\s*["\']?([A-Za-z0-9_.-]*)$/', $before, $match)) {
            $kind = MessengerSymbolKind::Bus;
            $prefix = $match[1];
        } elseif ($messengerOptionContext && preg_match('/(?:fromTransport|from_transport|failure_transport)\s*:\s*["\']?([A-Za-z0-9_.-]*)$/', $before, $match)) {
            $kind = MessengerSymbolKind::Transport;
            $prefix = $match[1];
        } elseif (preg_match('/BusNameStamp\s*\(\s*["\']([A-Za-z0-9_.-]*)$/', $before, $match)) {
            $kind = MessengerSymbolKind::Bus;
            $prefix = $match[1];
        } elseif ('yaml' === $request->document->languageId && \array_slice($this->yaml->parentPath($request->document->text, $lineOffset), -3) === ['framework', 'messenger', 'routing'] && preg_match('/:\s*\[?\s*["\']?([A-Za-z0-9_.-]*)$/', substr($before, $lineOffset), $match)) {
            $kind = MessengerSymbolKind::Transport;
            $prefix = $match[1];
        }
        if (null === $kind) {
            return null;
        }
        $names = [];
        $index = $this->indexes->forProject($request->project);
        if (MessengerSymbolKind::Bus === $kind) {
            foreach ($index->buses() as $bus) {
                $names[] = $bus->name();
            }
        } else {
            foreach ($index->transports() as $transport) {
                $names[] = $transport->name();
            }
        }
        $items = [];
        foreach ($names as $name) {
            if (str_starts_with($name, $prefix)) {
                $items[] = $this->completion($name, $request->document->text, $offset - \strlen($prefix), $request->position);
            }
        }

        return $items;
    }

    /** @return array<array-key, mixed> */
    private function completion(string $name, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return ['label' => $name, 'kind' => 12, 'textEdit' => $this->protocol->textEdit(new Range($position, $end), $name)];
    }
}
