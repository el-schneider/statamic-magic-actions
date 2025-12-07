<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Tests\Unit\Services;

use ElSchneider\StatamicMagicActions\MagicActions\ProposeTitle;
use ElSchneider\StatamicMagicActions\Services\ActionRegistry;
use Tests\TestCase;

final class ActionRegistryTest extends TestCase
{
    private ActionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ActionRegistry();
        $this->registry->discoverFromNamespace('ElSchneider\\StatamicMagicActions\\MagicActions');
    }

    public function test_discovers_magic_actions(): void
    {
        $handles = $this->registry->getAllHandles();
        $this->assertNotEmpty($handles);
        $this->assertContains('propose-title', $handles);
    }

    public function test_gets_instance_by_handle(): void
    {
        $instance = $this->registry->getInstance('propose-title');
        $this->assertInstanceOf(ProposeTitle::class, $instance);
    }

    public function test_caches_instances(): void
    {
        $instance1 = $this->registry->getInstance('propose-title');
        $instance2 = $this->registry->getInstance('propose-title');
        $this->assertSame($instance1, $instance2);
    }

    public function test_returns_null_for_unknown_handle(): void
    {
        $instance = $this->registry->getInstance('nonexistent-action');
        $this->assertNull($instance);
    }
}
