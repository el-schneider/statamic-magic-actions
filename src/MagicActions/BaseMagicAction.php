<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Prism\Prism\Schema\ObjectSchema;

abstract class BaseMagicAction implements MagicAction
{
    public const string TITLE = '';
    public const string HANDLE = '';

    public function getTitle(): string
    {
        return static::TITLE;
    }

    public function getHandle(): string
    {
        return static::HANDLE;
    }

    abstract public function config(): array;

    abstract public function system(): string;

    abstract public function prompt(): string;

    abstract public function schema(): ?ObjectSchema;

    abstract public function rules(): array;
}
