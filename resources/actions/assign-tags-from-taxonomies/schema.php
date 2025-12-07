<?php

declare(strict_types=1);

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'assigned_tags_response',
    description: 'Tags assigned from available taxonomy',
    properties: [
        new ArraySchema('tags', 'Array of selected tag strings', new StringSchema('tag', 'A single tag from the taxonomy')),
    ],
    requiredFields: ['tags']
);
