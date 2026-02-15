<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Listeners;

use ElSchneider\StatamicMagicActions\Services\MagicFieldsConfigBuilder;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Fields\Blueprint;
use Statamic\Statamic;

final class ProvideEntryMagicActionsToScript
{
    public function __construct(private readonly MagicFieldsConfigBuilder $configBuilder) {}

    public function handle(EntryBlueprintFound $event): void
    {
        if (! $this->isOnCPRoute()) {
            return;
        }

        $this->provideMagicActionsToScript($event->blueprint);
    }

    private function provideMagicActionsToScript(Blueprint $blueprint): void
    {
        $magicFields = $this->configBuilder->buildFromBlueprint($blueprint);

        Statamic::provideToScript([
            'magicFields' => $magicFields ?: [],
        ]);
    }

    private function isOnCPRoute(): bool
    {
        $routeName = request()->route()?->getName();

        return is_string($routeName) && str_starts_with($routeName, 'statamic.cp.collections');
    }
}
