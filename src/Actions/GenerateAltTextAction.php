<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Actions;

use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use Statamic\Actions\Action;
use Statamic\Contracts\Assets\Asset;
use Throwable;

final class GenerateAltTextAction extends Action
{
    private const string ACTION_HANDLE = 'alt-text';

    private const string FIELD_HANDLE = 'alt';

    private const array IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'svg',
    ];

    public static function title(): string
    {
        return 'Generate Alt Text';
    }

    public function visibleTo($item): bool
    {
        return $item instanceof Asset && $this->isImageAsset($item);
    }

    public function visibleToBulk($items): bool
    {
        return collect($items)->contains(fn ($item): bool => $this->visibleTo($item));
    }

    public function confirmationText(): string
    {
        return 'Generate AI alt text for :count selected assets?';
    }

    public function run($items, $values): string
    {
        $executor = app(ActionExecutor::class);
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach (collect($items) as $item) {
            if (! $item instanceof Asset || ! $this->isImageAsset($item)) {
                $skipped++;

                continue;
            }

            try {
                $executor->execute(self::ACTION_HANDLE, $item, self::FIELD_HANDLE);
                $queued++;
            } catch (Throwable) {
                $failed++;
            }
        }

        if ($queued === 0) {
            if ($failed > 0) {
                return 'No alt text jobs were queued. Check that an alt field is configured for magic actions.';
            }

            return 'No image assets were eligible for alt text generation.';
        }

        $messages = [
            trans_choice('Queued :count alt text job.|Queued :count alt text jobs.', $queued),
        ];

        if ($skipped > 0) {
            $messages[] = trans_choice('Skipped :count non-image asset.|Skipped :count non-image assets.', $skipped);
        }

        if ($failed > 0) {
            $messages[] = trans_choice('Failed to queue :count asset.|Failed to queue :count assets.', $failed);
        }

        return implode(' ', $messages);
    }

    private function isImageAsset(Asset $asset): bool
    {
        if ($asset->isImage()) {
            return true;
        }

        $mimeType = method_exists($asset, 'mimeType') ? mb_strtolower(mb_trim((string) $asset->mimeType())) : '';
        if ($mimeType !== '' && str_starts_with($mimeType, 'image/')) {
            return true;
        }

        $extension = mb_strtolower(mb_trim((string) $asset->extension()));

        return $extension !== '' && in_array($extension, self::IMAGE_EXTENSIONS, true);
    }
}
