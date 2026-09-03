<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\Company;
use App\Models\User;
use App\Support\Reporting\ReportPdfException;
use App\Support\Reporting\ReportPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReportPdfController
{
    public function __invoke(Request $request, BuildReport $buildReport, ReportPdfRenderer $renderer): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $input = $request->input('definition');
        if (! is_array($input)) {
            throw ValidationException::withMessages(['definition' => 'La definizione del report è obbligatoria.']);
        }
        try {
            $definition = ReportDefinition::fromArray($input);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['definition' => $exception->getMessage()]);
        }
        $report = $buildReport->execute($user, $definition);
        $companyModel = Company::query()->findOrFail($definition->companyId);
        $company = Str::slug((string) $report->header['company_name']);
        $kind = Str::slug($definition->kind->value);
        $filename = sprintf('report-%s-%s-%s-%s.pdf', $company, $report->header['exercise_year'], $kind, now()->format('Ymd-His'));
        $selection = Validator::make($request->only(['blocks_configured', 'columns_configured', 'blocks', 'columns']), [
            'blocks_configured' => ['sometimes', 'boolean'],
            'columns_configured' => ['sometimes', 'boolean'],
            'blocks' => ['sometimes', 'array', 'max:200'],
            'blocks.*' => ['string', 'max:100'],
            'columns' => ['sometimes', 'array', 'max:100'],
            'columns.*' => ['string', 'max:100'],
        ])->validate();
        $configuration = [];
        if ($selection['blocks_configured'] ?? false) {
            $configuration['blocks'] = $selection['blocks'] ?? [];
        }
        if ($selection['columns_configured'] ?? false) {
            $configuration['columns'] = $selection['columns'] ?? [];
        }

        try {
            $pdf = $renderer->render($report, $companyModel, $configuration);
        } catch (ReportPdfException $exception) {
            Log::error('PDF report rendering failed.', [
                'reason' => $exception->reason,
                'company_id' => $definition->companyId,
                'report_kind' => $definition->kind->value,
                'exception' => $exception,
            ]);

            return response('Il servizio PDF non è al momento disponibile. Riprova più tardi.', 503, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $disposition = $request->route()->getName() === 'reports.pdf.preview' ? 'inline' : 'attachment';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
