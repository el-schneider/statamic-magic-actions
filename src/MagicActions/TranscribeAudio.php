<?php

declare(strict_types=1);

namespace ElSchneider\StatamicMagicActions\MagicActions;

use ElSchneider\StatamicMagicActions\Contracts\MagicAction;
use Prism\Prism\Schema\ObjectSchema;

final class TranscribeAudio implements MagicAction
{
    public function getTitle(): string
    {
        return 'Transcribe Audio';
    }

    public function getHandle(): string
    {
        return 'transcribe-audio';
    }

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
