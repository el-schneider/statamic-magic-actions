<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Actions;

use ElSchneider\StatamicMagicActions\Services\ActionExecutor;
use Illuminate\Support\Facades\Log;
use Statamic\Actions\Action;
use Statamic\Contracts\Assets\Asset;
use Throwable;

final class GenerateAltText extends Action
{
    /**
     * @var array<int, string>
     */
    private const IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'svg',
        'bmp',
        'avif',
        'tiff',
        'tif',
        'heic',
        'heif',
    ];

    protected $confirm = true;

    public function __construct(private readonly ActionExecutor $actionExecutor)
    {
        parent::__construct();
    }

    public static function title()
    {
        return __('Generate Alt Text');
    }

    public function visibleTo($item)
    {
        if (($this->context['view'] ?? 'list') !== 'list') {
            return false;
        }

        if (! $item instanceof Asset) {
            return false;
        }

        return $this->isImageAsset($item);
    }

    public function visibleToBulk($items)
    {
        if ($items->whereInstanceOf(Asset::class)->count() !== $items->count()) {
            return false;
        }

        return $items->contains(fn ($item): bool => $item instanceof Asset && $this->isImageAsset($item));
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Generate alt text for this asset?|Generate alt text for these :count assets?';
    }

    public function buttonText()
    {
        /** @translation */
        return 'Generate Alt Text|Generate Alt Text for :count Assets';
    }

    public function run($items, $values)
    {
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($items as $item) {
            if (! $item instanceof Asset || ! $this->isImageAsset($item)) {
                $skipped++;

                continue;
            }

            try {
                $this->actionExecutor->execute('alt-text', $item, 'alt');
                $queued++;
            } catch (Throwable $e) {
                $failed++;

                Log::warning('Bulk alt text action failed for asset', [
                    'asset_id' => (string) $item->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->resultMessage($queued, $skipped, $failed);
    }

    private function isImageAsset(Asset $asset): bool
    {
        if ($asset->isImage()) {
            return true;
        }

        $mimeType = mb_strtolower(mb_trim((string) $asset->mimeType()));
        if (str_starts_with($mimeType, 'image/')) {
            return true;
        }

        $extension = mb_strtolower(mb_trim((string) $asset->extension()));

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    private function resultMessage(int $queued, int $skipped, int $failed): string
    {
        $messages = [];

        if ($queued > 0) {
            $messages[] = trans_choice(
                'Queued alt text generation for :count asset.|Queued alt text generation for :count assets.',
                $queued,
                ['count' => $queued]
            );
        }

        if ($skipped > 0) {
            $messages[] = trans_choice(
                ':count asset was skipped.|:count assets were skipped.',
                $skipped,
                ['count' => $skipped]
            );
        }

        if ($failed > 0) {
            $messages[] = trans_choice(
                ':count asset failed to queue.|:count assets failed to queue.',
                $failed,
                ['count' => $failed]
            );
        }

        return $messages === [] ? __('No assets were processed.') : implode(' ', $messages);
    }
}
