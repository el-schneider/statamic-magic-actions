<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Contracts;

use Prism\Prism\Schema\ObjectSchema;

interface MagicAction
{
    public function getTitle(): string;

    public function getHandle(): string;

    /**
     * Type of AI operation (text, vision, audio)
     */
    public function type(): string;

    /**
     * Parameters for the AI request
     *
     * @return array Contains sparse parameter overrides: temperature, max_tokens, language, etc.
     */
    public function parameters(): array;

    /**
     * Allowed models for this action
     *
     * @return array Empty array = use type defaults, single model = force that model,
     *               multiple models = restrict to this list
     *               Format: ['provider/model', 'provider/model']
     */
    public function models(): array;

    /**
     * Accepted MIME type patterns for file-based actions.
     *
     * @return array Empty array = no file type restriction
     */
    public function acceptedMimeTypes(): array;

    /**
     * System prompt for the AI model
     *
     * Can contain Blade syntax and will be rendered with provided variables.
     * Optional for audio actions (which don't use prompts).
     */
    public function system(): string;

    /**
     * User prompt template for the AI model
     *
     * Can contain Blade syntax and will be rendered with provided variables.
     * Optional for audio actions (which don't use prompts).
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

    /**
     * Optional icon for the action (Statamic icon name or SVG string)
     */
    public function icon(): ?string;

    /**
     * Whether taxonomy term results should be constrained to existing terms only.
     *
     * When true, AI-returned terms not found in the taxonomy are silently dropped.
     * When false (default), missing terms are created automatically.
     */
    public function constrainToExistingTerms(): bool;
}
