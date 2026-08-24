<?php

namespace App\Support\Reporting;

use App\Domain\Reporting\ReportResult;
use Dompdf\Dompdf;
use Dompdf\Options;

final class ReportPdfRenderer
{
    public function render(ReportResult $result): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('chroot', [resource_path('views/reports'), public_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml(view('reports.pdf', ['report' => $result])->render(), 'UTF-8');
        $dompdf->render();

        return $dompdf->output(['compress' => 0]);
    }
}
