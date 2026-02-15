<?php

declare(strict_types=1);

dataset('configured fieldtypes', [
    'text' => [
        'type' => 'text',
        'expectedActions' => ['propose-title', 'alt-text', 'image-caption'],
    ],
    'textarea' => [
        'type' => 'textarea',
        'expectedActions' => ['extract-meta-description', 'create-teaser', 'transcribe-audio', 'image-caption'],
    ],
    'bard' => [
        'type' => 'bard',
        'expectedActions' => ['create-teaser', 'transcribe-audio', 'image-caption'],
    ],
    'terms' => [
        'type' => 'terms',
        'expectedActions' => ['extract-tags', 'assign-tags-from-taxonomies', 'extract-assets-tags'],
    ],
]);

dataset('unconfigured fieldtypes', [
    'integer',
    'toggle',
    'select',
    'date',
    'color',
]);

it('shows magic action fields for configured fieldtype', function (string $type, array $expectedActions) {
    $response = $this->actingAsSuperAdmin()
        ->postJson('/cp/fields/edit', [
            'type' => $type,
            'values' => [
                'type' => $type,
                'display' => 'Test Field',
                'handle' => 'test',
            ],
        ]);

    $response->assertOk();

    $config = collect($response->json('fieldtype.config'));

    // Verify magic action fields are present
    $enabledField = $config->firstWhere('handle', 'magic_actions_enabled');
    expect($enabledField)->not->toBeNull()
        ->and($enabledField['type'])->toBe('toggle');

    $sourceField = $config->firstWhere('handle', 'magic_actions_source');
    expect($sourceField)->not->toBeNull()
        ->and($sourceField['type'])->toBe('text');

    $modeField = $config->firstWhere('handle', 'magic_actions_mode');
    expect($modeField)->not->toBeNull()
        ->and($modeField['type'])->toBe('select')
        ->and($modeField['options'])->toHaveKeys(['append', 'replace']);

    $actionField = $config->firstWhere('handle', 'magic_actions_action');
    expect($actionField)->not->toBeNull()
        ->and($actionField['type'])->toBe('select')
        ->and($actionField['multiple'])->toBeTrue()
        ->and($actionField['options'])->toHaveKeys($expectedActions)
        ->and(count($actionField['options']))->toBe(count($expectedActions));
})->with('configured fieldtypes');

it('hides magic action options for unconfigured fieldtype', function (string $type) {
    $response = $this->actingAsSuperAdmin()
        ->postJson('/cp/fields/edit', [
            'type' => $type,
            'values' => [
                'type' => $type,
                'display' => 'Test Field',
                'handle' => 'test',
            ],
        ]);

    $response->assertOk();

    $config = collect($response->json('fieldtype.config'));

    // Verify none of the magic action fields are present
    expect($config->firstWhere('handle', 'magic_actions'))->toBeNull()
        ->and($config->firstWhere('handle', 'magic_actions_enabled'))->toBeNull()
        ->and($config->firstWhere('handle', 'magic_actions_source'))->toBeNull()
        ->and($config->firstWhere('handle', 'magic_actions_mode'))->toBeNull()
        ->and($config->firstWhere('handle', 'magic_actions_action'))->toBeNull();
})->with('unconfigured fieldtypes');
