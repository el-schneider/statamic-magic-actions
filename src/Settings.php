<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use Illuminate\Support\Facades\File;
use Statamic\Facades\YAML;

final class Settings
{
    public static function data(): array
    {
        $path = self::path();

        if (! File::exists($path)) {
            return [];
        }

        $parsed = YAML::parse(File::get($path));

        return is_array($parsed) ? $parsed : [];
    }

    public static function save(array $data): void
    {
        $path = self::path();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, YAML::dump($data));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return data_get(self::data(), $key, $default);
    }

    public static function path(): string
    {
        return config(
            'statamic.magic-actions.settings_path',
            base_path('content/magic-actions/settings.yaml')
        );
    }
}
