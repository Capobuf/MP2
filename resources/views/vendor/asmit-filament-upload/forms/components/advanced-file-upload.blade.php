@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\Facades\FilamentView;

    $pdfPreviewHeight = $getPdfPreviewHeight();
    $pdfScrollbar = 1;
    $pdfDisplayPage = $getPdfDisplayPage();
    $pdfToolbar = $getPdfToolbar();
    $pdfNavPanes = $getPdfNavPanes();
    $pdfZoomLevel = $getPdfZoomLevel();
    $pdfView = $getPdfFitType();
@endphp

<div>
    <div
        x-data="advancedFileUpload({
            pdfPreviewHeight: @js($pdfPreviewHeight),
            pdfScrollbar: @js($pdfScrollbar),
            pdfDisplayPage: @js($pdfDisplayPage),
            pdfToolbar: @js($pdfToolbar),
            pdfNavPanes: @js($pdfNavPanes),
            pdfZoom: @js($pdfZoomLevel),
            pdfView: @js($pdfView),
            allowPdfPreview: @js($isPreviewable()),
        })"
        @if (FilamentView::hasSpaMode())
            x-load="visible || event (ax-modal-opened)"
        @else
            x-load
        @endif
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('filepond-pdf', 'asmit/filament-upload') }}"
        x-load-css="[@js(FilamentAsset::getStyleHref(id: 'filepond-pdf', package: 'asmit/filament-upload'))]"
    >
        @if (view()->exists('filament-forms::components.file-upload'))
            @include('filament-forms::components.file-upload')
        @else
            {!! $toEmbeddedHtml() !!}
        @endif
    </div>
</div>
