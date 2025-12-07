<?php

declare(strict_types=1);

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'meta_description_response',
    description: 'SEO-optimized meta description for content',
    properties: [
        new StringSchema('description', 'Meta description (max 160 characters)'),
    ],
    requiredFields: ['description']
);
