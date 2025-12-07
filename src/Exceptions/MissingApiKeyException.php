<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Exceptions;

use Exception;

final class MissingApiKeyException extends Exception
{
    public function __construct()
    {
        parent::__construct('OpenAI API key is not configured');
    }
}
