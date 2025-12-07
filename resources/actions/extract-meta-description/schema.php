<?php

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'meta_description_response',
    description: 'SEO-optimized meta description for content',
    properties: [
        new StringSchema('data', 'Meta description (max 160 characters)'),
    ],
    requiredFields: ['data']
);
