<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions;

use Illuminate\Support\Facades\File;
use Statamic\Facades\YAML;

final class Settings
{
    public static function data(): array
    {
        if (! File::exists(self::path())) {
            return [];
        }

        return YAML::parse(File::get(self::path())) ?? [];
    }

    public static function save(array $data): void
    {
        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), YAML::dump($data));
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
