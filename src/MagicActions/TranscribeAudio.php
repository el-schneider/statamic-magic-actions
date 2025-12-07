<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use Prism\Prism\Schema\ObjectSchema;

final class TranscribeAudio extends BaseMagicAction
{
    public const string TITLE = 'Transcribe Audio';
    public const string HANDLE = 'transcribe-audio';

    public function config(): array
    {
        return [
            'type' => 'audio',
            'provider' => 'openai',
            'model' => 'whisper-1',
            'parameters' => [
                'language' => 'en',
            ],
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

    public function system(): string
    {
        return <<<'BLADE'
You are a transcription assistant. Transcribe the provided audio accurately.
BLADE;
    }

    public function prompt(): string
    {
        return <<<'BLADE'
Transcribe this audio file.
BLADE;
    }
}
