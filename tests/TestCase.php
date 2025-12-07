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

    }
}
