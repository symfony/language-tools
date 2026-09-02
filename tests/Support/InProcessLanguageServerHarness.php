<?php

namespace Symfony\Lsp\Tests\Support;

use Amp\ByteStream\ReadableIterableStream;
use Symfony\Lsp\Server\LanguageServerFactory;
use Symfony\Lsp\Tools\ContentLengthMessageCodec;

use function Amp\delay;

final class InProcessLanguageServerHarness
{
    public function __construct(
        private readonly LanguageServerFactory $factory = new LanguageServerFactory(),
        private readonly ContentLengthMessageCodec $codec = new ContentLengthMessageCodec(),
        private readonly float $timeout = 15.0,
    ) {
    }

    /**
     * @param list<array<string, mixed>|LanguageServerTranscriptAction|ProtocolMessageExpectation> $steps
     */
    public function run(array $steps): LanguageServerTranscript
    {
        $output = new CapturingWritableStream();
        $input = new ReadableIterableStream((function () use ($steps, $output): \Generator {
            $messageOffset = 0;
            $pendingFrames = '';
            foreach ($steps as $step) {
                if (\is_array($step)) {
                    $pendingFrames .= $this->codec->encode($step);

                    continue;
                }
                if ('' !== $pendingFrames) {
                    yield $pendingFrames;
                    $pendingFrames = '';
                }
                if ($step instanceof LanguageServerTranscriptAction) {
                    $step->run();

                    continue;
                }

                $deadline = microtime(true) + $this->timeout;
                do {
                    $messages = $this->codec->decodeAvailable($output->contents());
                    foreach (\array_slice($messages, $messageOffset, preserve_keys: true) as $index => $message) {
                        if ($step->matches($message)) {
                            $messageOffset = $index + 1;

                            continue 3;
                        }
                    }
                    delay(0.001);
                } while (microtime(true) < $deadline);

                throw new \RuntimeException(\sprintf("Timed out waiting for %s.\nRaw transcript:\n%s\nDecoded transcript:\n%s", $step->description, $output->contents(), json_encode($this->codec->decodeAvailable($output->contents()), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)));
            }
            if ('' !== $pendingFrames) {
                yield $pendingFrames;
            }
        })());

        $exitCode = $this->factory->create($input, $output)->run();
        $raw = $output->contents();

        return new LanguageServerTranscript($exitCode, $raw, $this->codec->decode($raw));
    }
}
