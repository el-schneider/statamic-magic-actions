<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;

final class AssetsService
{
    /**
     * Get an asset by its ID
     */
    public function getAssetById(string $assetId)
    {
        return Asset::find($assetId);
    }

    /**
     * Get the temporary path for an asset
     */
    public function getAssetTempPath($asset): string
    {
        $tempPath = storage_path('app/temp/'.uniqid().'-'.$asset->filename());
        Storage::disk('local')->makeDirectory('temp');

        // Save the asset content to the temporary file
        file_put_contents($tempPath, $asset->contents());

        return $tempPath;
    }

    /**
     * Clean up a temporary file
     */
    public function cleanupTempFile(string $tempPath): void
    {
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }
}
