<?php

declare(strict_types=1);

namespace Tests;

use ElSchneider\StatamicMagicActions\ServiceProvider;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonName = 'el-schneider/statamic-magic-actions';

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            \Prism\Prism\PrismServiceProvider::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Register view namespace for testing (after services are booted)
        app('view')->addNamespace('magic-actions', __DIR__.'/../resources/actions');
    }

    protected function tearDown(): void
    {
        // Override to create directory with proper permissions
        if (isset($this->fakeStacheDirectory) && is_string($this->fakeStacheDirectory)) {
            app('files')->deleteDirectory($this->fakeStacheDirectory);
            mkdir($this->fakeStacheDirectory, 0755, true);
            touch($this->fakeStacheDirectory.'/.gitkeep');
        }

        parent::tearDown();
    }

    final public function actingAsSuperAdmin()
    {
        $admin = User::make()
            ->email('admin@test.com')
            ->makeSuper();

        $admin->save();

        return $this->actingAs($admin);
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        // Override config to fix action/handle mismatch in FieldConfigService
        $app['config']->set('statamic.magic-actions.fieldtypes', [
            'Statamic\Fieldtypes\Text' => [
                'actions' => [
                    [
                        'title' => 'Propose Title',
                        'handle' => 'propose-title',
                    ],
                ],
            ],
            'Statamic\Fieldtypes\Textarea' => [
                'actions' => [
                    [
                        'title' => 'Extract Meta Description',
                        'handle' => 'extract-meta-description',
                    ],
                ],
            ],
            'Statamic\Fieldtypes\Bard' => [
                'actions' => [
                    [
                        'title' => 'Transcribe Audio',
                        'handle' => 'transcribe-audio',
                    ],
                ],
            ],
        ]);
    }
}
