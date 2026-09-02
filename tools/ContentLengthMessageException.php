<?php

namespace Symfony\Lsp\Tools;

final class ContentLengthMessageException extends \UnexpectedValueException
{
    public const HEADER_TOO_LARGE = 'header_too_large';
    public const MALFORMED_HEADER = 'malformed_header';
    public const DUPLICATE_HEADER = 'duplicate_header';
    public const MISSING_HEADER = 'missing_header';
    public const BODY_NOT_OBJECT = 'body_not_object';
    public const BODY_KEYS_NOT_STRINGS = 'body_keys_not_strings';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
