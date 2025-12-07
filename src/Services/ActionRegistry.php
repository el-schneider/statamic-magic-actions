<?php

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\MagicActions\BaseMagicAction;
use ReflectionClass;

/**
 * Registry for managing Magic Action metadata and discovery.
 *
 * Centralizes action handle resolution and class discovery to prevent
 * duplication across ServiceProvider, FieldConfigService, and ActionLoader.
 */
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

    /**
     * Discover and register all MagicAction classes from a given namespace.
     *
     * @param string $namespace The namespace to scan (e.g., 'ElSchneider\\StatamicMagicActions\\MagicActions')
     */
    public function discoverFromNamespace(string $namespace): void
    {
        // Try vendor path first (production), then local path (development)
        $baseDir = base_path('vendor/el-schneider/statamic-magic-actions/src/MagicActions');
        if (!is_dir($baseDir)) {
            $baseDir = __DIR__ . '/../MagicActions';
        }
        $files = glob($baseDir . '/*.php');

        foreach ($files ?? [] as $file) {
            if ($file === dirname($file) . '/BaseMagicAction.php') {
                continue;
            }

            $className = basename($file, '.php');
            $fqcn = $namespace . '\\' . $className;

            if (class_exists($fqcn) && is_subclass_of($fqcn, BaseMagicAction::class)) {
                try {
                    $instance = new $fqcn();
                    $this->handles[$instance->getHandle()] = $fqcn;
                } catch (\Throwable $e) {
                    // Skip actions that fail to instantiate
                    continue;
                }
            }
        }
    }

    /**
     * Get the class path for an action by handle.
     */
    public function getClassPath(string $handle): ?string
    {
        return $this->handles[$handle] ?? null;
    }

    /**
     * Get an action instance by handle (cached).
     */
    public function getInstance(string $handle): ?BaseMagicAction
    {
        if (!isset($this->handles[$handle])) {
            return null;
        }

        if (!isset($this->instances[$handle])) {
            $class = $this->handles[$handle];
            $this->instances[$handle] = new $class();
        }

        return $this->instances[$handle];
    }

    /**
     * Get all registered action handles.
     *
     * @return array<string>
     */
    public function getAllHandles(): array
    {
        return array_keys($this->handles);
    }

    /**
     * Get all registered actions as [handle => instance] pairs.
     *
     * @return array<string, BaseMagicAction>
     */
    public function getAllInstances(): array
    {
        foreach ($this->handles as $handle => $class) {
            if (!isset($this->instances[$handle])) {
                $this->instances[$handle] = new $class();
            }
        }

        return $this->instances;
    }
}
