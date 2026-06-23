<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Contracts\RequiresContext;
use InvalidArgumentException;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Stringable;
use Throwable;

final class ContextResolver
{
    public function resolve(RequiresContext $action, Entry|Asset $target, string $fieldHandle): array
    {
        $resolved = [];

        foreach ($action->contextRequirements() as $variable => $resolver) {
            if (! is_string($variable) || $variable === '') {
                continue;
            }

            $resolved[$variable] = $this->resolveRequirement($variable, $resolver, $target, $fieldHandle);
        }

        return $resolved;
    }

    private function resolveRequirement(
        string $variable,
        mixed $resolver,
        Entry|Asset $target,
        string $fieldHandle
    ): mixed {
        if (is_string($resolver)) {
            return $this->resolveBuiltInRequirement($resolver, $target, $fieldHandle);
        }

        if (is_callable($resolver)) {
            return $resolver($target, $fieldHandle);
        }

        throw new InvalidArgumentException(__('magic-actions::magic-actions.errors.invalid_context_variable', ['variable' => $variable]));
    }

    private function resolveBuiltInRequirement(string $resolver, Entry|Asset $target, string $fieldHandle): mixed
    {
        return match (true) {
            $resolver === 'taxonomy_terms' => $this->resolveTaxonomyTerms($target, $fieldHandle),
            $resolver === 'entry_content' => $this->resolveEntryContent($target, $fieldHandle),
            $resolver === 'asset_metadata' => $this->resolveAssetMetadata($target),
            str_starts_with($resolver, 'entry_field:') => $this->resolveEntryField(
                $target,
                mb_substr($resolver, mb_strlen('entry_field:'))
            ),
            default => throw new InvalidArgumentException(__('magic-actions::magic-actions.errors.unsupported_resolver', ['resolver' => $resolver])),
        };
    }

    private function resolveTaxonomyTerms(Entry|Asset $target, string $fieldHandle): string
    {
        $fieldConfig = $this->resolveFieldConfig($target, $fieldHandle);
        $taxonomy = $this->resolveTaxonomyHandle($fieldConfig);

        if ($taxonomy === null) {
            return '';
        }

        $titles = collect($this->loadTerms($taxonomy))
            ->map(fn (mixed $term): string => $this->resolveTermTitle($term))
            ->filter(fn (string $title): bool => $title !== '')
            ->unique()
            ->values()
            ->all();

        return implode(', ', $titles);
    }

    private function resolveEntryContent(Entry|Asset $target, string $fieldHandle): string
    {
        if (! $target instanceof Entry) {
            return '';
        }

        $fieldConfig = $this->resolveFieldConfig($target, $fieldHandle);
        $sourceHandle = $fieldConfig['magic_actions_source'] ?? null;

        if (is_string($sourceHandle) && $sourceHandle !== '') {
            return $this->extractText($target->get($sourceHandle));
        }

        $contentValue = $target->get('content');
        if ($contentValue !== null) {
            return $this->extractText($contentValue);
        }

        $data = $this->safeInvokeMethod($target, 'data');

        if (is_object($data) && method_exists($data, 'all')) {
            try {
                return $this->extractText($data->all());
            } catch (Throwable) {
                return '';
            }
        }

        if (is_array($data)) {
            return $this->extractText($data);
        }

        return '';
    }

    private function resolveEntryField(Entry|Asset $target, string $fieldHandle): mixed
    {
        if (! $target instanceof Entry || $fieldHandle === '') {
            return '';
        }

        return $target->get($fieldHandle);
    }

    private function resolveAssetMetadata(Entry|Asset $target): string
    {
        if (! $target instanceof Asset) {
            return '';
        }

        $filename = $this->extractAssetFilename($target);
        $extension = $this->stringify($this->safeInvokeMethod($target, 'extension'));
        $sizeBytes = $this->toNumericString(
            $this->safeInvokeMethod($target, 'size')
                ?? $this->safeInvokeMethod($target, 'fileSize')
        );
        $width = $this->toNumericString($this->safeInvokeMethod($target, 'width'));
        $height = $this->toNumericString($this->safeInvokeMethod($target, 'height'));

        $metadata = $this->safeInvokeMethod($target, 'metadata');

        if (is_array($metadata)) {
            $width ??= $this->toNumericString($metadata['width'] ?? null);
            $height ??= $this->toNumericString($metadata['height'] ?? null);
            $sizeBytes ??= $this->toNumericString($metadata['size'] ?? ($metadata['filesize'] ?? null));
        }

        if ($extension === '' && $filename !== '' && str_contains($filename, '.')) {
            $extension = mb_strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        }

        $parts = [];

        if ($filename !== '') {
            $parts[] = "filename: {$filename}";
        }

        if ($extension !== '') {
            $parts[] = "extension: {$extension}";
        }

        if ($sizeBytes !== null) {
            $parts[] = "size: {$sizeBytes} bytes";
        }

        if ($width !== null && $height !== null) {
            $parts[] = "dimensions: {$width}x{$height}";
        }

        return implode(', ', $parts);
    }

    private function resolveFieldConfig(Entry|Asset $target, string $fieldHandle): array
    {
        $blueprint = $target->blueprint();
        if (! $blueprint) {
            return [];
        }

        $field = $blueprint->field($fieldHandle);
        if (! $field) {
            return [];
        }

        $config = $field->config();

        return is_array($config) ? $config : [];
    }

    private function resolveTaxonomyHandle(array $fieldConfig): ?string
    {
        $taxonomy = $fieldConfig['taxonomy'] ?? null;

        if (is_string($taxonomy) && $taxonomy !== '') {
            return $taxonomy;
        }

        $taxonomies = $fieldConfig['taxonomies'] ?? null;

        if (! is_array($taxonomies)) {
            return null;
        }

        foreach ($taxonomies as $handle) {
            if (is_string($handle) && $handle !== '') {
                return $handle;
            }
        }

        return null;
    }

    private function loadTerms(string $taxonomyHandle): iterable
    {
        try {
            $terms = Term::query()->where('taxonomy', $taxonomyHandle)->get();
            if (is_iterable($terms)) {
                return $terms;
            }
        } catch (Throwable) {
            // Fall through to the next strategy.
        }

        try {
            $terms = Term::whereTaxonomy($taxonomyHandle)->get();
            if (is_iterable($terms)) {
                return $terms;
            }
        } catch (Throwable) {
            // Fall through to taxonomy fallback.
        }

        $taxonomy = Taxonomy::findByHandle($taxonomyHandle);
        if ($taxonomy && method_exists($taxonomy, 'terms')) {
            try {
                $terms = $taxonomy->terms();
                if (is_iterable($terms)) {
                    return $terms;
                }
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }

    private function resolveTermTitle(mixed $term): string
    {
        if (is_scalar($term) || $term instanceof Stringable) {
            return $this->stringify($term);
        }

        if (! is_object($term)) {
            return '';
        }

        $title = $this->safeInvokeMethod($term, 'title');
        $normalizedTitle = $this->stringify($title);
        if ($normalizedTitle !== '') {
            return $normalizedTitle;
        }

        $title = $this->safeInvokeMethod($term, 'value', ['title']);
        $normalizedTitle = $this->stringify($title);
        if ($normalizedTitle !== '') {
            return $normalizedTitle;
        }

        $title = $this->safeInvokeMethod($term, 'get', ['title']);
        $normalizedTitle = $this->stringify($title);
        if ($normalizedTitle !== '') {
            return $normalizedTitle;
        }

        $slug = $this->safeInvokeMethod($term, 'slug');

        return $this->stringify($slug);
    }

    private function extractAssetFilename(Asset $asset): string
    {
        $filename = $this->stringify(
            $this->safeInvokeMethod($asset, 'basename')
                ?? $this->safeInvokeMethod($asset, 'filename')
        );

        if ($filename !== '') {
            return $filename;
        }

        $path = $this->stringify($this->safeInvokeMethod($asset, 'path'));
        if ($path !== '') {
            return (string) pathinfo($path, PATHINFO_BASENAME);
        }

        return $this->stringify($asset->id());
    }

    private function extractText(mixed $content): string
    {
        if ($content === null) {
            return '';
        }

        if (is_string($content)) {
            return mb_trim($content);
        }

        if (is_scalar($content) || $content instanceof Stringable) {
            return mb_trim((string) $content);
        }

        if (is_array($content)) {
            if (($content['type'] ?? null) === 'text' && isset($content['text']) && is_string($content['text'])) {
                return mb_trim($content['text']);
            }

            $fragments = [];

            foreach ($content as $key => $value) {
                if (is_string($key) && in_array($key, ['type', 'attrs', 'marks'], true)) {
                    continue;
                }

                $text = $this->extractText($value);
                if ($text !== '') {
                    $fragments[] = $text;
                }
            }

            return implode("\n", $fragments);
        }

        if (is_object($content)) {
            $toArray = $this->safeInvokeMethod($content, 'toArray');

            if (is_array($toArray)) {
                return $this->extractText($toArray);
            }

            return $this->extractText(get_object_vars($content));
        }

        return '';
    }

    private function safeInvokeMethod(object $target, string $method, array $arguments = []): mixed
    {
        if (! method_exists($target, $method)) {
            return null;
        }

        try {
            return $target->{$method}(...$arguments);
        } catch (Throwable) {
            return null;
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return mb_trim($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof Stringable) {
            return mb_trim((string) $value);
        }

        return '';
    }

    private function toNumericString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (string) (int) $value;
        }

        return null;
    }
}
