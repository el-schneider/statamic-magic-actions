<?php

declare(strict_types=1);

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'alt_text_response',
    description: 'Alt text description for image',
    properties: [
        new StringSchema('alt_text', 'Alt text description'),
    ],
    requiredFields: ['alt_text']
);
