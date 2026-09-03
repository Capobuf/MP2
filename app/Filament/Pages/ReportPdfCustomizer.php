<?php

namespace App\Filament\Pages;

use App\Actions\Reporting\BuildReport;
use App\Domain\Reporting\ReportDefinition;
use App\Models\Company;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\Reporting\ReportPdfComposer;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

final class ReportPdfCustomizer extends Page
{
    protected string $view = 'filament.pages.report-pdf-customizer';

    protected static ?string $slug = 'reports/pdf/customize';

    protected static ?string $title = 'Personalizza PDF';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public array $definition = [];

    /** @var array<int, array{id: string, label: string, group: string}> */
    public array $availableBlocks = [];

    /** @var array<int, array{id: string, label: string, group: string}> */
    public array $availableColumns = [];

    /** @var array<int, string> */
    public array $selectedBlocks = [];

    /** @var array<int, string> */
    public array $selectedColumns = [];

    public string $orientation = 'landscape';

    public static function canAccess(): bool
    {
        return Reports::canAccess();
    }

    public function mount(BuildReport $buildReport, ReportPdfComposer $composer): void
    {
        abort_unless(self::canAccess(), 403);
        $input = request()->input('definition');
        if (! is_array($input)) {
            throw ValidationException::withMessages(['definition' => 'La definizione del report è obbligatoria.']);
        }

        try {
            $definition = ReportDefinition::fromArray($input);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['definition' => $exception->getMessage()]);
        }

        $user = auth()->user();
        abort_unless($user instanceof User, 401);
        $document = $composer->compose(
            $buildReport->execute($user, $definition),
            $this->company(),
            ['orientation' => $this->orientation],
        );

        $this->definition = $definition->toArray();
        $this->availableBlocks = $document['available_blocks'];
        $this->availableColumns = $document['available_columns'];
        $this->selectedBlocks = $document['selected_blocks'];
        $this->selectedColumns = $document['selected_columns'];
    }

    public function selectAll(): void
    {
        $this->selectedBlocks = array_column($this->availableBlocks, 'id');
        $this->selectedColumns = array_column($this->availableColumns, 'id');
    }

    public function selectNone(): void
    {
        $this->selectedBlocks = [];
        $this->selectedColumns = [];
    }

    public function previewUrl(): string
    {
        return route('reports.pdf.preview', $this->routeParameters());
    }

    public function downloadUrl(): string
    {
        return route('reports.pdf.download', $this->routeParameters());
    }

    /** @return array<string, mixed> */
    private function routeParameters(): array
    {
        return [
            'definition' => $this->definition,
            'orientation' => $this->orientation,
            'blocks_configured' => true,
            'columns_configured' => true,
            'blocks' => array_values($this->selectedBlocks),
            'columns' => array_values($this->selectedColumns),
        ];
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
