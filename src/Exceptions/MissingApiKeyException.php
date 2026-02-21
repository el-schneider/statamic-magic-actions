<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Exceptions;

use Exception;
use Throwable;

final class MissingApiKeyException extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(
            $message !== '' ? $message : __('magic-actions::magic-actions.errors.missing_api_key_generic'),
            $code,
            $previous
        );
    }
}
