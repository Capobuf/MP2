<?php

namespace App\Http\Controllers;

use App\Models\BudgetEvidence;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BudgetEvidenceDownloadController extends Controller
{
    public function __invoke(BudgetEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing('budget');
        abort_unless(Gate::allows('downloadEvidence', $evidence->budget), 404);
        abort_if($evidence->storage_disk === null || $evidence->storage_path === null || $evidence->original_name === null, 404);
        abort_unless(Storage::disk($evidence->storage_disk)->exists($evidence->storage_path), 404);

        return Storage::disk($evidence->storage_disk)->download($evidence->storage_path, $evidence->original_name, ['Content-Type' => $evidence->media_type ?? 'application/octet-stream']);
    }
}
