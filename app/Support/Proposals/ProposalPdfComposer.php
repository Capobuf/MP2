<?php

namespace App\Support\Proposals;

use App\Filament\Resources\Proposals\Schemas\ProposalInfolist;
use App\Models\Company;
use App\Models\Proposal;
use Illuminate\Support\Facades\Storage;

final class ProposalPdfComposer
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function compose(Proposal $proposal, array $configuration = []): array
    {
        $orientation = $configuration['orientation'] ?? 'portrait';
        if (! is_string($orientation) || ! in_array($orientation, ['portrait', 'landscape'], true)) {
            throw new \InvalidArgumentException('Invalid PDF orientation.');
        }

        $overview = ProposalInfolist::overview($proposal);
        /** @var array<int, array<string, mixed>> $overviewItems */
        $overviewItems = $overview['items'];
        $normalizedItems = [];
        foreach ($overviewItems as $key => $item) {
            $item['plan_role'] = match (true) {
                $item['excluded'] => 'Esclusa dalla Proposta',
                $item['origin_key'] === 'Nuova sorgente' => 'Nuova sorgente',
                default => 'Sorgente esistente',
            };
            $normalizedItems[$key] = $item;
        }
        $overview['items'] = $normalizedItems;
        $items = collect($normalizedItems);
        $logo = $this->logoDataUri($proposal->company);
        $availableBlocks = [];

        if ($logo !== null) {
            $availableBlocks[] = $this->option('logo', 'Logo aziendale', 'identity');
        }
        $availableBlocks[] = $this->option('summary', 'Riepilogo della Proposta', 'summary');
        $availableBlocks[] = $this->option('verification', 'Verifiche e readiness', 'verification');
        if ($overview['impacts'] !== []) {
            $availableBlocks[] = $this->option('impacts', 'Impatti per Esercizio', 'impact');
        }
        if ($items->isNotEmpty()) {
            $availableBlocks[] = $this->option('sources', 'Elenco sorgenti', 'source');
        }
        foreach (['expense' => 'Dettagli Spese', 'project' => 'Dettagli Progetti', 'contract' => 'Dettagli Contratti'] as $type => $label) {
            if ($items->contains('type_value', $type)) {
                $availableBlocks[] = $this->option('details:'.$type, $label, 'detail');
            }
        }
        if ($items->contains(fn (array $item): bool => $item['actions'] !== [])) {
            $availableBlocks[] = $this->option('decisions', 'Storico decisioni', 'decision');
        }

        $availableIds = array_column($availableBlocks, 'id');
        $selectedBlocks = array_key_exists('blocks', $configuration) && is_array($configuration['blocks'])
            ? array_values(array_intersect($availableIds, array_values(array_unique(array_filter($configuration['blocks'], 'is_string')))))
            : $availableIds;

        return [
            'orientation' => $orientation,
            'generated_at' => now($proposal->company->timezone)->toDateTimeString(),
            'company' => ['name' => $proposal->company->name],
            'proposal_id' => $proposal->id,
            'overview' => $overview,
            'logo' => $logo,
            'available_blocks' => $availableBlocks,
            'selected_blocks' => $selectedBlocks,
        ];
    }

    /** @return array{id: string, label: string, group: string} */
    private function option(string $id, string $label, string $group): array
    {
        return compact('id', 'label', 'group');
    }

    private function logoDataUri(Company $company): ?string
    {
        $disk = $company->getAttribute('logo_disk');
        $path = $company->getAttribute('logo_path');
        $mediaType = $company->getAttribute('logo_media_type');
        if (! is_string($disk) || ! is_string($path) || ! in_array($mediaType, ['image/png', 'image/jpeg'], true)) {
            return null;
        }
        $contents = Storage::disk($disk)->get($path);

        return is_string($contents) ? 'data:'.$mediaType.';base64,'.base64_encode($contents) : null;
    }
}
