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
        return __('magic-actions::messages.confirm_transcribe_audio');
    }

    public function bulkButtonText(): string
    {
        return __('magic-actions::messages.button_transcribe_audio');
    }
}
