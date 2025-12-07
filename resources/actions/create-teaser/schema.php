<?php

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'teaser_response',
    description: 'Generated teaser text for content preview',
    properties: [
        new StringSchema('data', 'Teaser text (approximately 300 characters)'),
    ],
    requiredFields: ['data']
);
