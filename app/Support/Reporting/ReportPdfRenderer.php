<?php

namespace App\Support\Reporting;

use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportResult;
use App\Models\Company;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final class ReportPdfRenderer
{
    public function __construct(
        private readonly WeasyPrintRuntime $runtime,
        private readonly ReportPdfComposer $composer,
    ) {}

    /** @param array<string, mixed> $configuration */
    public function render(ReportResult $result, Company $company, array $configuration = []): string
    {
        $status = $this->runtime->status();
        if (! $status['available'] || $status['binary'] === null) {
            throw new ReportPdfException($status['reason'] ?? 'unavailable', $status['message']);
        }

        $document = $this->composer->compose($result, $company, $configuration);
        $view = match ($result->definition->kind) {
            ReportKind::Contracts => 'reports.contracts',
            default => 'reports.pdf',
        };

        $html = view($view, [
            'document' => $document,
        ])->render();

        try {
            $process = Process::timeout((int) config('reporting.timeout'))
                ->input($html)
                ->run([$status['binary'], '-', '-']);
        } catch (ProcessTimedOutException $exception) {
            throw new ReportPdfException('timeout', 'WeasyPrint ha superato il tempo massimo di rendering.', $exception);
        } catch (\Throwable $exception) {
            throw new ReportPdfException('render_failed', 'Impossibile avviare WeasyPrint.', $exception);
        }

        if (! $process->successful() || ! str_starts_with($process->output(), '%PDF-')) {
            throw new ReportPdfException('render_failed', 'WeasyPrint non ha prodotto un PDF valido. '.$process->errorOutput());
        }

        return $process->output();
    }
}
