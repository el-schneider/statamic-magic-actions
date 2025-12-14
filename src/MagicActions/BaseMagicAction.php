<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Prism\Prism\Schema\ObjectSchema;

use function count;
use function reset;

abstract class BaseMagicAction implements MagicAction
{
    public const string TITLE = '';

    abstract public function type(): string;

    abstract public function schema(): ?ObjectSchema;

    abstract public function rules(): array;

    public function parameters(): array
    {
        return [];
    }

    public function models(): array
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

    /**
     * Unwrap structured responses from Prism.
     *
     * When using ObjectSchema with a name, Prism wraps the response fields in that object.
     * For example, ObjectSchema named 'meta_description_response' returns:
     * ['meta_description_response' => ['description' => '...']]
     *
     * This method extracts the inner value so the frontend receives clean data.
     * Default behavior for single-field responses returns just the field value.
     * For multi-field responses, returns the unwrapped fields object.
     * Override this method in subclasses for custom unwrapping logic.
     *
     * @param  array  $structured  The structured response from Prism
     * @return mixed The unwrapped response (value for single fields, array for multiple fields)
     */
    final public function unwrap(array $structured): mixed
    {
        // Prism returns the fields object directly
        // For single-field schemas, extract just the field value
        // e.g., ['description' => 'text'] becomes 'text'
        // e.g., ['tags' => ['tag1', 'tag2']] becomes ['tag1', 'tag2']

        if (count($structured) === 1) {
            return reset($structured);
        }

        // Multiple fields - return as is
        return $structured;
    }

    final public function icon(): ?string
    {
        return null;
    }

    private function deriveHandle(): string
    {
        return \ElSchneider\StatamicMagicActions\Services\ActionRegistry::classNameToHandle(static::class);
    }
}
