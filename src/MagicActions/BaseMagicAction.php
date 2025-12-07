<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Prism\Prism\Schema\ObjectSchema;

abstract class BaseMagicAction implements MagicAction
{
    public const string TITLE = '';

    public function getTitle(): string
    {
        return static::TITLE;
    }

    public function getHandle(): string
    {
        return $this->deriveHandle();
    }

    private function deriveHandle(): string
    {
        $className = class_basename(static::class);
        // Convert CamelCase to kebab-case
        $handle = preg_replace('/([a-z])([A-Z])/', '$1-$2', $className);
        return strtolower($handle);
    }

    abstract public function config(): array;

    abstract public function system(): string;

    abstract public function prompt(): string;

    abstract public function schema(): ?ObjectSchema;

    abstract public function rules(): array;
}
