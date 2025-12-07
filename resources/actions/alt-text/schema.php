<?php

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'alt_text_response',
    description: 'Alt text description for image',
    properties: [
        new StringSchema('data', 'Alt text description'),
    ],
    requiredFields: ['data']
);
