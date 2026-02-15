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

    public function acceptedMimeTypes(): array
    {
        return ['audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/webm', 'audio/ogg', 'audio/flac'];
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

    public function supportsBulk(): bool
    {
        return true;
    }

    public function bulkTargetType(): string
    {
        return 'asset';
    }

    public function bulkConfirmationText(): string
    {
        return 'Transcribe this asset?|Transcribe these :count assets?';
    }

    public function bulkButtonText(): string
    {
        return 'Transcribe Audio|Transcribe Audio for :count Assets';
    }
}
