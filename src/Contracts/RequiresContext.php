<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Contracts;

interface RequiresContext
{
    /**
     * Return the context keys this action needs.
     * Each key maps to a resolver method name or closure.
     * Example: ['available_tags' => 'taxonomy_terms', 'content' => 'entry_content']
     */
    public function contextRequirements(): array;
}
