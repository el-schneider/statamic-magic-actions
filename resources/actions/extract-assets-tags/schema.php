<?php

declare(strict_types=1);

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'asset_tags_response',
    description: 'Tags extracted from image asset',
    properties: [
        new ArraySchema('tags', 'Array of image tag strings', new StringSchema('tag', 'A single descriptive tag')),
    ],
    requiredFields: ['tags']
);
