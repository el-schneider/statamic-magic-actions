<?php

declare(strict_types=1);

uses()->group('browser');

it('loads the settings page with system prompt field', function () {
    visit('/cp/magic-actions/settings')
        ->assertSee('Magic Actions Settings')
        ->assertSee('Global System Prompt')
        ->assertSee('Save');
});
