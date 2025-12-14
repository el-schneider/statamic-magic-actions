<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ObjectSchema;

final class TranscribeAudio extends BaseMagicAction
{
    public const string TITLE = 'Transcribe Audio';

    public function type(): string
    {
        return 'audio';
    }

    public function models(): array
    {
        return ['openai/whisper-1'];
    }

    public function parameters(): array
    {
        return [
            'language' => 'en',
        ];
    }

    public function schema(): ?ObjectSchema
    {
        return null;
    }

    public function rules(): array
    {
        return [];
    }
}
