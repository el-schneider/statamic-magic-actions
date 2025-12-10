<?php

declare(strict_types=1);

namespace Tests;

abstract class BrowserTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withVite();

        $this->setupStatamicAssets();
    }

    protected function setupStatamicAssets(): void
    {
        $addonRoot = dirname(__DIR__);
        $testbenchPublic = public_path();

        // Setup Statamic CP assets
        $vendorStatamicDir = $testbenchPublic.'/vendor/statamic/cp';
        if (! is_dir($vendorStatamicDir)) {
            mkdir($vendorStatamicDir, 0755, true);
        }

        $statamicBuildSource = $addonRoot.'/vendor/statamic/cms/resources/dist/build';
        $statamicBuildDestination = $vendorStatamicDir.'/build';

        if (! file_exists($statamicBuildDestination) && is_dir($statamicBuildSource)) {
            symlink($statamicBuildSource, $statamicBuildDestination);
        }

        // Setup addon assets
        $addonAssetsSource = $addonRoot.'/resources/dist';
        $addonAssetsDestination = $testbenchPublic.'/vendor/statamic-magic-actions';

        if (! file_exists($addonAssetsDestination) && is_dir($addonAssetsSource)) {
            symlink($addonAssetsSource, $addonAssetsDestination);
        }
    }
}
