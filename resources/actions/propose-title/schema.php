<?php

use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

return new ObjectSchema(
    name: 'title_response',
    description: 'Proposed title for content',
    properties: [
        new StringSchema('title', 'Proposed title'),
    ],
    requiredFields: ['title']
);
