<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\User;
use App\Support\Reporting\ReportPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $company = Str::slug((string) $report->header['company_name']);
        $kind = Str::slug($definition->kind->value);
        $filename = sprintf('report-%s-%s-%s-%s.pdf', $company, $report->header['exercise_year'], $kind, now()->format('Ymd-His'));

        return response($renderer->render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
