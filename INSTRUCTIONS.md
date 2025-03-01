This addon needs a big larger refactor.

The current approach is not sustainable. Originally I was thinking on performing all the requests to AI APIs on the front end but with longer running vision and transcription jobs this is no longer a good approach. I also was thinking on using the Google dotprompt package for parsing my Markdown-style prompts into actual API calls. But I think this is also a dead end.

Because of that, there is also no longer a need to pass prompts data to the front-end, identifiers for the prompts will be enough. All prompt files will still need to live inside one folder which will also help us with naturally enforcing unique handles as no two prompt files can have the same name.

The vision for the refactor is the following.

## Using frontmatter enabled markdown files to easily provide custom prompts.

The Markdown should be mappable to a php-nested array in roughly this way. We need to write custom functionality to transform markdown files into configuration we can use when performing API requests.

```md
---
model: openai/gpt-3.5-turbo-0613
response_format:
  type: json_schema
  json_schema:
    name: email_schema
    schema:
      type: object
      properties:
        email:
          type: string
          description: The email address that appears in the input
      additionalProperties: false
---

<system>
Your task is to extract the email address from the input.
</system>

<user>
{{text}}
</user>
```

```php
[
    'model' => 'gpt-3.5-turbo-0613',
    'messages' => [
        ['role' => 'system', 'content' => 'Your task is to extract the email address from the input.'],
        ['role' => 'user', 'content' => '{{text}}'],
    ],
    'response_format' = [
        "type" => "json_schema",
        "json_schema" => [
            "name" => "email_schema",
            "schema" => [
                "type" => "object",
                "properties" => [
                    "email" => [
                        "description" => "The email address that appears in the input",
                        "type" => "string"
                    ]
                ],
                "additionalProperties" => false
            ]
        ]
    ]
];

]
```

## Providing a custom internal API with endpoints for /completion, /vision and /transcribe

The field actions should trigger these endpoints instead of directly triggering the API provider's endpoints.
Each AI operation should be dispatched as a job to the queue.
POST request should trigger a job.
GET requests should inform about the current state of the running job and return the result of the operation once it's finished.
This way after a job has been dispatched from the front end we can use polling to figure out when it's done. We can leverage the built-in Statamic Actions feature, that displays a loading indicator while waiting for a promise to resolve inside the run function. The jobs should update the fielddata in question on the backend, while the action updates the fielddata on the frontend.

## Implement OpenAI for now, but keep our approach flexible

Why we will only focus on using OpenAI as a service? We want to be able to have prompts with configs of other providers in the future too.
