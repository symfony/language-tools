<?php

namespace Symfony\Lsp\Parser\Xml;

final class XmlDocument
{
    /** @var array<int, XmlElementStart> */
    private array $elements = [];

    /** @var array<int, XmlElementEnd> */
    private array $ends = [];

    /**
     * @param list<XmlElementStart|XmlElementEnd|XmlText|XmlOpaque> $events
     * @param list<XmlDiagnostic>                                   $diagnostics
     */
    public function __construct(
        public readonly array $events,
        public readonly array $diagnostics = [],
        public readonly bool $truncated = false,
    ) {
        foreach ($events as $event) {
            if ($event instanceof XmlElementStart) {
                $this->elements[$event->identity] = $event;
            } elseif ($event instanceof XmlElementEnd && null !== $event->identity) {
                $this->ends[$event->identity] = $event;
            }
        }
    }

    public function requireComplete(): void
    {
        if ($this->truncated) {
            throw new \RuntimeException('XML analysis stopped after reaching its structural limit.');
        }
    }

    /** @return list<XmlElementStart> */
    public function elements(): array
    {
        return array_values($this->elements);
    }

    public function end(int $identity): ?XmlElementEnd
    {
        return $this->ends[$identity] ?? null;
    }
}
