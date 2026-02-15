<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\MagicActions\BaseMagicAction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class ActionRegistry
{
    /**
     * @var array<string, class-string<BaseMagicAction>>
     */
    private array $handles = [];

    /**
     * @var array<string, BaseMagicAction>
     */
    private array $instances = [];

    public static function classNameToHandle(string $className): string
    {
        $className = basename(str_replace('\\', '/', $className));

        return Str::kebab($className);
    }

    public static function handleToClassName(string $handle): string
    {
        return Str::studly($handle);
    }

    public function discoverFromNamespace(string $namespace): void
    {
        foreach (File::files($this->resolveDiscoveryDirectory()) as $file) {
            if ($file->getFilename() === 'BaseMagicAction.php') {
                continue;
            }

            $className = $file->getBasename('.php');
            $fqcn = $namespace.'\\'.$className;

            if (class_exists($fqcn) && is_subclass_of($fqcn, BaseMagicAction::class)) {
                try {
                    $instance = new $fqcn();
                    $this->handles[$instance->getHandle()] = $fqcn;
                } catch (Throwable) {
                    continue;
                }
            }
        }
    }

    public function getClassPath(string $handle): ?string
    {
        return $this->handles[$handle] ?? null;
    }

    public function getInstance(string $handle): ?BaseMagicAction
    {
        if (! isset($this->handles[$handle])) {
            return null;
        }

        if (! isset($this->instances[$handle])) {
            $class = $this->handles[$handle];
            $this->instances[$handle] = new $class();
        }

        return $this->instances[$handle];
    }

    public function getAllHandles(): array
    {
        return array_keys($this->handles);
    }

    public function getAllInstances(): array
    {
        foreach ($this->handles as $handle => $class) {
            if (! isset($this->instances[$handle])) {
                $this->instances[$handle] = new $class();
            }
        }

        return $this->instances;
    }

    private function resolveDiscoveryDirectory(): string
    {
        $vendorDirectory = base_path('vendor/el-schneider/statamic-magic-actions/src/MagicActions');

        if (File::isDirectory($vendorDirectory)) {
            return $vendorDirectory;
        }

        return __DIR__.'/../MagicActions';
    }
}
