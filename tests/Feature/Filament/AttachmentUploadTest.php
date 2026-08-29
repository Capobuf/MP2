<?php

use App\Filament\Forms\AttachmentUpload;
use Asmit\FilamentUpload\Enums\PdfViewFit;
use Asmit\FilamentUpload\Forms\Components\AdvancedFileUpload;

it('configures the plugin PDF preview consistently for MP2 attachment fields', function () {
    $field = AttachmentUpload::make('attachment');

    expect($field)->toBeInstanceOf(AdvancedFileUpload::class)
        ->and($field->getPdfPreviewHeight())->toBe(480)
        ->and($field->getPdfDisplayPage())->toBe(1)
        ->and($field->getPdfToolbar())->toBeTrue()
        ->and($field->getPdfZoomLevel())->toBe(100)
        ->and($field->getPdfFitType())->toBe(PdfViewFit::FIT->value)
        ->and($field->getPdfNavPanes())->toBeFalse();

    expect(view()->getFinder()->find('asmit-filament-upload::forms.components.advanced-file-upload'))
        ->toBe(resource_path('views/vendor/asmit-filament-upload/forms/components/advanced-file-upload.blade.php'))
        ->and(public_path('js/asmit/filament-upload/components/filepond-pdf.js'))->toBeFile()
        ->and(public_path('css/asmit/filament-upload/filepond-pdf.css'))->toBeFile();
});
