<?php

namespace ErnestDefoe\Hangout;

/** A refusal the browser turns into a sentence: `code` names the locale string. */
class HangoutException extends \RuntimeException
{
    /** @param array<string, mixed> $meta */
    public function __construct(public readonly string $errorCode, public readonly int $status = 422, public readonly array $meta = [])
    {
        parent::__construct($errorCode);
    }
}
