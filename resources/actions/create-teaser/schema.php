<?php

declare(strict_types=1);

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'teaser_response',
    description: 'Generated teaser text for content preview',
    properties: [
        new StringSchema('teaser', 'Teaser text (approximately 300 characters)'),
    ],
    requiredFields: ['teaser']
);
