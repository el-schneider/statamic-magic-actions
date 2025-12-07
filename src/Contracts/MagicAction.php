<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Contracts;

use Prism\Prism\Schema\ObjectSchema;

interface MagicAction
{
    public function getTitle(): string;

    public function getHandle(): string;

    /**
     * Configuration for the magic action
     *
     * @return array Contains: type, provider, model, parameters
     */
    public function config(): array;

    /**
     * System prompt for the AI model
     *
     * Can contain Blade syntax and will be rendered with provided variables
     */
    public function system(): string;

    /**
     * User prompt template for the AI model
     *
     * Can contain Blade syntax and will be rendered with provided variables
     */
    public function prompt(): string;

    /**
     * Optional schema for structured output from the AI model
     */
    public function schema(): ?ObjectSchema;

    /**
     * Validation rules for expected prompt variables
     *
     * @return array Laravel validation rules keyed by variable name
     *               Example: ['text' => 'required|string', 'image' => 'required|string']
     */
    public function rules(): array;

    /**
     * Unwrap structured responses from Prism
     *
     * @param  array  $structured  The structured response from Prism
     * @return mixed The unwrapped response (value for single fields, array for multiple fields)
     */
    public function unwrap(array $structured): mixed;
}
