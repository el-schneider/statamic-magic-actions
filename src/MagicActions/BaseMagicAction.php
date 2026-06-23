<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use ElSchneider\StatamicMagicActions\Contracts\RequiresContext;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use Prism\Prism\Schema\ObjectSchema;

abstract class BaseMagicAction implements MagicAction, RequiresContext
{
    public const string TITLE = '';

    abstract public function type(): string;

    abstract public function schema(): ?ObjectSchema;

    abstract public function rules(): array;

    public function contextRequirements(): array
    {
        return [
            'text' => 'entry_content',
        ];
    }

    public function parameters(): array
    {
        return [];
    }

    public function models(): array
    {
        return [];
    }

    public function acceptedMimeTypes(): array
    {
        return [];
    }

    public function system(): string
    {
        return '';
    }

    public function prompt(): string
    {
        return '';
    }

    final public function getTitle(): string
    {
        return static::TITLE;
    }

    final public function getHandle(): string
    {
        return $this->deriveHandle();
    }

    final public function unwrap(array $structured): mixed
    {
        if (count($structured) === 1) {
            return reset($structured);
        }

        return $structured;
    }

    final public function icon(): ?string
    {
        return null;
    }

    public function constrainToExistingTerms(): bool
    {
        return false;
    }

    public function supportsBulk(): bool
    {
        return false;
    }

    public function bulkTargetType(): string
    {
        return 'entry';
    }

    public function bulkConfirmationText(): string
    {
        return __('magic-actions::magic-actions.bulk.confirm', ['title' => static::TITLE]);
    }

    public function bulkButtonText(): string
    {
        return __('magic-actions::magic-actions.bulk.button', ['title' => static::TITLE]);
    }

    public function supportsFieldSelection(): bool
    {
        return false;
    }

    private function deriveHandle(): string
    {
        return ActionRegistry::classNameToHandle(static::class);
    }
}
