<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentPreviewController extends Controller
{
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        abort_unless(Gate::allows('view', $attachment), 404);
        abort_unless($attachment->media_type === 'application/pdf', 404);
        abort_unless(Storage::disk($attachment->storage_disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->storage_disk)->response($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
