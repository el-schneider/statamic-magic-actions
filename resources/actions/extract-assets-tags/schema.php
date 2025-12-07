<?php

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'asset_tags_response',
    description: 'Tags extracted from image asset',
    properties: [
        new ArraySchema('data', 'Array of image tag strings', new StringSchema('tag', 'A single descriptive tag')),
    ],
    requiredFields: ['data']
);
