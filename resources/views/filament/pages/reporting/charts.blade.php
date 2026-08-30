@if ($report['charts'] !== [])
    <section class="mp2-report-analytics" aria-labelledby="report-analytics-title">
        <div class="mp2-report-section-heading">
            <div>
                <p class="mp2-report-kicker">Visualizzazioni</p>
                <h3 id="report-analytics-title">Lettura analitica</h3>
            </div>
        </div>
        <div class="mp2-report-chart-grid">
            @foreach ($report['charts'] as $chart)
                @livewire(
                    \App\Filament\Widgets\ReportChart::class,
                    [
                        'headingText' => $chart['heading'],
                        'descriptionText' => $chart['description'],
                        'chartType' => $chart['type'],
                        'variant' => $chart['variant'],
                        'chartData' => $chart['data'],
                    ],
                    key('report-chart-'.$chart['id'].'-'.md5(json_encode($chart['data'])))
                )
            @endforeach
        </div>
    </section>
@endif
