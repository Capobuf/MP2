<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Proposals\ProposalPdfComposer;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

class CustomizeProposalPdf extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProposalResource::class;

    protected string $view = 'filament.resources.proposals.pages.customize-proposal-pdf';

    /** @var list<array{id: string, label: string, group: string}> */
    public array $availableBlocks = [];

    /** @var list<string> */
    public array $selectedBlocks = [];

    public string $orientation = 'portrait';

    public function mount(int|string $record, ProposalPdfComposer $composer): void
    {
        $this->record = $this->resolveRecord($record);
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->can('view', $this->proposal()), 403);

        $document = $composer->compose($this->proposal(), ['orientation' => $this->orientation]);
        $this->availableBlocks = $document['available_blocks'];
        $this->selectedBlocks = $document['selected_blocks'];
    }

    public function getTitle(): string
    {
        return 'Stampa Proposta #'.$this->proposal()->id;
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function selectAll(): void
    {
        $this->selectedBlocks = array_column($this->availableBlocks, 'id');
    }

    public function selectNone(): void
    {
        $this->selectedBlocks = [];
    }

    public function previewUrl(): string
    {
        return route('proposals.pdf.preview', $this->routeParameters());
    }

    public function downloadUrl(): string
    {
        return route('proposals.pdf.download', $this->routeParameters());
    }

    public function downloadFilename(): string
    {
        return sprintf(
            'proposta-%s-%s-%s.pdf',
            Str::slug($this->proposal()->company->name),
            $this->proposal()->exercise->year,
            $this->proposal()->id,
        );
    }

    /** @return array<string, mixed> */
    private function routeParameters(): array
    {
        return [
            'tenant' => $this->proposal()->company->tenantCompany,
            'proposal' => $this->proposal(),
            'orientation' => $this->orientation,
            'blocks_configured' => true,
            'blocks' => $this->selectedBlocks,
        ];
    }

    private function proposal(): Proposal
    {
        /** @var Proposal $proposal */
        $proposal = $this->getRecord();

        return $proposal;
    }
}
