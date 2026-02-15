<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use ElSchneider\StatamicMagicActions\Actions\DynamicBulkAction;
use Statamic\Actions\Action;

/**
 * Automatically registers DynamicBulkAction instances for all MagicActions
 * that declare supportsBulk() => true.
 *
 * This eliminates the need for per-action wrapper classes. Adding bulk support
 * to a new MagicAction requires only returning true from supportsBulk().
 */
final class BulkActionRegistrar
{
    public function __construct(
        private readonly ActionRegistry $registry,
    ) {}

    /**
     * Register all bulk-enabled MagicActions as Statamic Actions.
     *
     * Injects configured DynamicBulkAction instances into Statamic's
     * action extensions collection, keyed by unique handles.
     */
    public function registerBulkActions(): void
    {
        $extensions = app('statamic.extensions');
        $actionBindings = $extensions[Action::class] ?? collect();

        foreach ($this->registry->getAllInstances() as $handle => $magicAction) {
            if (! $magicAction->supportsBulk()) {
                continue;
            }

            $bindingKey = 'magic-actions.bulk.'.$handle;

            // Register a container binding that returns a configured DynamicBulkAction
            app()->bind($bindingKey, function () use ($handle): DynamicBulkAction {
                $adapter = new DynamicBulkAction();
                $adapter->setMagicActionHandle($handle);

                return $adapter;
            });

            // Register in Statamic's action collection
            $actionBindings['magic-bulk-'.$handle] = $bindingKey;
        }

        $extensions[Action::class] = $actionBindings;
        app()->instance('statamic.extensions', $extensions);
    }
}
