<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentDownloadController extends Controller
{
    public function __invoke(Attachment $attachment): StreamedResponse
    {
        abort_unless(Gate::allows('view', $attachment), 404);
        abort_unless(Storage::disk($attachment->storage_disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->storage_disk)->download($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->media_type ?? 'application/octet-stream',
        ]);
    }
}
