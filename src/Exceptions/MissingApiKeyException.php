<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Exceptions;

use Exception;

final class MissingApiKeyException extends Exception
{
    protected $message = 'API key is not configured';
}
