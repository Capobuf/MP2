<?php

namespace App\Filament\Forms;

use App\Models\Attachment;
use Asmit\FilamentUpload\Enums\PdfViewFit;
use Asmit\FilamentUpload\Forms\Components\AdvancedFileUpload;

class AttachmentUpload extends AdvancedFileUpload
{
    public static function forStoredAttachment(Attachment $attachment): static
    {
        return static::make('attachment_'.$attachment->id)
            ->label($attachment->original_name)
            ->default($attachment->storage_path)
            ->disk($attachment->storage_disk)
            ->visibility('private')
            ->disabled()
            ->dehydrated(false)
            ->deletable(false)
            ->downloadable()
            ->getUploadedFileUsing(fn (): array => [
                'name' => $attachment->original_name,
                'size' => $attachment->size_bytes,
                'type' => $attachment->media_type,
                'url' => $attachment->media_type === 'application/pdf'
                    ? route('attachments.preview', $attachment)
                    : route('attachments.download', $attachment),
            ])
            ->getDownloadableFileUrlUsing(fn (): string => route('attachments.download', $attachment));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->pdfPreviewHeight(480)
            ->pdfDisplayPage(1)
            ->pdfToolbar(true)
            ->pdfZoomLevel(100)
            ->pdfFitType(PdfViewFit::FIT)
            ->pdfNavPanes(false);
    }
}
