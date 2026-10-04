<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\Proposals\ProposalPdfComposer;
use App\Support\Reporting\ReportPdfException;
use App\Support\Reporting\ReportPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ProposalPdfController
{
    public function __invoke(
        Request $request,
        TenantCompany $tenant,
        Proposal $proposal,
        ProposalPdfComposer $composer,
        ReportPdfRenderer $renderer,
    ): Response {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->can('view', $proposal), 403);

        $selection = Validator::make($request->only(['orientation', 'blocks_configured', 'blocks']), [
            'orientation' => ['sometimes', 'string', Rule::in(['portrait', 'landscape'])],
            'blocks_configured' => ['sometimes', 'boolean'],
            'blocks' => ['sometimes', 'array', 'max:20'],
            'blocks.*' => ['string', 'max:100'],
        ])->validate();
        $configuration = ['orientation' => $selection['orientation'] ?? 'portrait'];
        if ($selection['blocks_configured'] ?? false) {
            $configuration['blocks'] = $selection['blocks'] ?? [];
        }

        try {
            $document = $composer->compose($proposal, $configuration);
            $pdf = $renderer->renderHtml(view('proposals.pdf', compact('document'))->render());
        } catch (ReportPdfException $exception) {
            Log::error('Proposal PDF rendering failed.', [
                'reason' => $exception->reason,
                'company_id' => $proposal->company_id,
                'proposal_id' => $proposal->id,
                'exception' => $exception,
            ]);

            return response('Il servizio PDF non è al momento disponibile. Riprova più tardi.', 503, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $company = Str::slug($proposal->company->name);
        $filename = sprintf('proposta-%s-%s-%s-%s.pdf', $company, $proposal->exercise->year, $proposal->id, now()->format('Ymd-His'));
        $disposition = $request->route()->getName() === 'proposals.pdf.preview' ? 'inline' : 'attachment';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
