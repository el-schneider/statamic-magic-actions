<?php

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'tags_response',
    description: 'Extracted tags from content',
    properties: [
        new ArraySchema('tags', 'Array of tag strings', new StringSchema('tag', 'A single tag')),
    ],
    requiredFields: ['tags']
);
