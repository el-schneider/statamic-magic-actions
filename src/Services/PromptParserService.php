<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Symfony\Component\Yaml\Yaml;

final class PromptParserService
{
    /**
     * Parse a markdown prompt with frontmatter into a structured array
     */
    public function parse(string $promptContent): array
    {
        try {
            // Check if there's frontmatter
            if (! preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $promptContent, $matches)) {
                return $this->parseSimpleFormat($promptContent);
            }

            $frontmatter = $matches[1];
            $markdownContent = $matches[2];

            // Parse frontmatter using Symfony Yaml
            $config = Yaml::parse($frontmatter);

            // Parse markdown content to extract message roles
            $messages = $this->parseMessages($markdownContent);

            // Extract model information
            $modelInfo = $this->parseModelInfo($config['model'] ?? 'openai/gpt-4o');

            $result = [
                ...$config,
                'provider' => $modelInfo['provider'],
                'model' => $modelInfo['model'],
                'messages' => $messages,
            ];

            // Add response_format if specified
            if (isset($config['response_format'])) {
                $result['response_format'] = $config['response_format'];
            }

            // Extract validation rules if specified
            if (isset($config['output']['validation'])) {
                $result['validation_rules'] = $this->parseValidationRules($config['output']['validation']);
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Error parsing prompt: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Replace variables in messages with actual values
     */
    public function renderVariables(array $promptData, array $variables): array
    {
        $result = $promptData;

        // Process messages
        foreach ($result['messages'] as &$message) {
            foreach ($variables as $key => $value) {
                // Replace both formats: {{key}} and {{ key }} (with spaces)
                $message['content'] = str_replace(['{{'.$key.'}}', '{{ '.$key.' }}'], $value, $message['content']);
            }
        }

        return $result;
    }

    /**
     * Parse validation rules from output schema
     */
    private function parseValidationRules(array $validationConfig): array
    {
        $rules = [];

        foreach ($validationConfig as $field => $rule) {
            if (is_array($rule)) {
                // Handle nested validation rules with expanded syntax
                if (isset($rule['rules'])) {
                    $rules[$field] = $rule['rules'];
                }

                // Handle nested fields
                if (isset($rule['properties']) && is_array($rule['properties'])) {
                    foreach ($rule['properties'] as $nestedField => $nestedRule) {
                        $fullField = $field.'.'.$nestedField;
                        if (is_array($nestedRule) && isset($nestedRule['rules'])) {
                            $rules[$fullField] = $nestedRule['rules'];
                        } else {
                            $rules[$fullField] = $nestedRule;
                        }
                    }
                }

                // Handle array items validation
                if (isset($rule['items']) && is_array($rule['items'])) {
                    if (isset($rule['items']['rules'])) {
                        $rules[$field.'.*'] = $rule['items']['rules'];
                    } elseif (isset($rule['items']['type'])) {
                        // Convert type to Laravel validation rule
                        $rules[$field.'.*'] = $this->typeToValidationRule($rule['items']['type']);
                    }
                }
            } else {
                // Simple string rule
                $rules[$field] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Convert type string to Laravel validation rule
     */
    private function typeToValidationRule(string $type): string
    {
        return match ($type) {
            'string' => 'string',
            'integer', 'int' => 'integer',
            'float', 'double', 'number' => 'numeric',
            'boolean', 'bool' => 'boolean',
            'array' => 'array',
            'object' => 'array',
            default => 'string',
        };
    }

    /**
     * Parse model information from string like "openai/gpt-4o"
     */
    private function parseModelInfo(string $modelString): array
    {
        $parts = explode('/', $modelString);

        if (count($parts) === 2) {
            return [
                'provider' => $parts[0],
                'model' => $parts[1],
            ];
        }

        // Default to OpenAI if no provider specified
        return [
            'provider' => 'openai',
            'model' => $modelString,
        ];
    }

    /**
     * Parse markdown content to extract message roles
     */
    private function parseMessages(string $markdownContent): array
    {
        $messages = [];
        $roles = ['system', 'user', 'assistant'];

        try {
            // Wrap the content in a root element to make it valid XML
            $xmlContent = "<root>{$markdownContent}</root>";

            // Use libxml to suppress errors but capture them for logging
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlContent);
            $errors = libxml_get_errors();
            libxml_clear_errors();

            if ($xml === false) {
                Log::warning('XML parsing failed', ['errors' => $errors]);
            }

            // Process each role element
            foreach ($roles as $role) {

                foreach ($xml->{$role} as $roleElement) {

                    $content = $this->parseRoleContent($roleElement);

                    if (! empty($content)) {
                        $messages[] = [
                            'role' => $role,
                            'content' => $content,
                        ];
                    }
                }
            }

            // Fallback if no messages were found
            if (empty($messages)) {
                Log::warning('No role elements found in XML');
            }

            return $messages;
        } catch (Exception $e) {
            Log::warning('Error parsing XML content: '.$e->getMessage());
        }
    }

    /**
     * Parse role element content into structured format
     */
    private function parseRoleContent(SimpleXMLElement $roleElement): array|string
    {
        // Check if we have child elements that need special handling
        $hasSpecialChildren = false;
        $content = [];

        // Check if there are child elements directly using SimpleXMLElement methods
        $children = $roleElement->children();
        $childCount = count($children);

        // If there are child elements, process as structured content
        if ($childCount > 0) {
            $hasSpecialChildren = true;

            // Get the text content directly from the element
            $textContent = trim((string) $roleElement);

            if (! empty($textContent)) {
                $content[] = [
                    'type' => 'text',
                    'text' => $textContent,
                ];
            }

            // Process each child element
            foreach ($children as $childName => $childElement) {
                // Skip text nodes
                if ($childName === 'text') {
                    continue;
                }

                $attributes = [];
                foreach ($childElement->attributes() as $attrName => $attrValue) {
                    $attributes[(string) $attrName] = (string) $attrValue;
                }

                $childContent = [
                    'type' => (string) $childName,
                    (string) $childName => $attributes,
                ];

                $content[] = $childContent;
            }
        } else {
            // Simple text content
            return trim((string) $roleElement);
        }

        return $hasSpecialChildren ? $content : trim((string) $roleElement);
    }

    /**
     * Handle legacy format without proper frontmatter/tags
     */
    private function parseSimpleFormat(string $promptContent): array
    {
        // For simple prompts, treat the entire content as a system message
        return [
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => trim($promptContent),
                ],
            ],
        ];
    }
}
