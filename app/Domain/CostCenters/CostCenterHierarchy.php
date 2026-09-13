<?php

namespace App\Domain\CostCenters;

use App\Models\CostCenter;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CostCenterHierarchy
{
    /** @var array<int, CostCenter> */
    private array $centers;

    /** @var array<int, list<int>> */
    private array $children = [];

    /** @param Collection<int, CostCenter> $centers */
    public function __construct(Collection $centers)
    {
        $this->centers = $centers->keyBy(fn (CostCenter $center): int => (int) $center->id)->all();

        foreach ($this->centers as $center) {
            if ($center->parent_id !== null) {
                $this->children[(int) $center->parent_id][] = (int) $center->id;
            }
        }

        foreach ($this->children as &$children) {
            sort($children, SORT_NUMERIC);
        }
    }

    public static function forCompany(int $companyId, bool $lockForUpdate = false): self
    {
        $query = CostCenter::query()
            ->where('company_id', $companyId)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return new self($query->get());
    }

    /** @return list<int> */
    public function descendantIds(int $costCenterId, bool $includeSelf = true): array
    {
        $this->center($costCenterId);
        $result = $includeSelf ? [$costCenterId] : [];
        $pending = $this->children[$costCenterId] ?? [];

        while ($pending !== []) {
            $id = array_shift($pending);
            if (in_array($id, $result, true)) {
                throw new \UnexpectedValueException('La gerarchia dei Centri di Costo contiene un ciclo.');
            }
            $result[] = $id;
            array_push($pending, ...($this->children[$id] ?? []));
        }

        return $result;
    }

    /** @return list<int> */
    public function ancestorIds(int $costCenterId, bool $includeSelf = true): array
    {
        return array_column($this->lineage($costCenterId, $includeSelf), 'cost_center_id');
    }

    /** @return list<array{cost_center_id: int, cost_center_label: string}> */
    public function lineage(int $costCenterId, bool $includeSelf = true): array
    {
        $lineage = [];
        $seen = [];
        $current = $this->center($costCenterId);

        while (true) {
            if (isset($seen[$current->id])) {
                throw new \UnexpectedValueException('La gerarchia dei Centri di Costo contiene un ciclo.');
            }
            $seen[$current->id] = true;
            $lineage[] = [
                'cost_center_id' => (int) $current->id,
                'cost_center_label' => $current->name,
            ];

            if ($current->parent_id === null) {
                break;
            }
            $current = $this->center((int) $current->parent_id);
        }

        $lineage = array_reverse($lineage);

        if (! $includeSelf) {
            array_pop($lineage);
        }

        return $lineage;
    }

    public function path(int $costCenterId): string
    {
        return implode(' / ', array_column($this->lineage($costCenterId), 'cost_center_label'));
    }

    public function belongsToSubtree(int $candidateId, int $rootId): bool
    {
        return in_array($candidateId, $this->descendantIds($rootId), true);
    }

    /** @return array<int, string> */
    public function options(bool $activeOnly = true, ?int $excludeSubtreeRoot = null): array
    {
        $excluded = $excludeSubtreeRoot === null ? [] : $this->descendantIds($excludeSubtreeRoot);
        $options = [];

        foreach ($this->centers as $id => $center) {
            if (($activeOnly && $center->isArchived()) || in_array($id, $excluded, true)) {
                continue;
            }
            $options[$id] = $this->path($id).($center->isArchived() ? ' · Archiviato' : '');
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function assertCanAssignParent(?int $costCenterId, int $companyId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $this->centers[$parentId] ?? null;
        if (! $parent instanceof CostCenter || (int) $parent->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'parent_id' => 'Il Centro padre deve appartenere alla stessa Azienda.',
            ]);
        }

        if ($costCenterId === null) {
            return;
        }

        if ($parentId === $costCenterId) {
            throw ValidationException::withMessages([
                'parent_id' => 'Un Centro di Costo non può essere padre di se stesso.',
            ]);
        }

        if (in_array($parentId, $this->descendantIds($costCenterId, false), true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Lo spostamento creerebbe un ciclo nella gerarchia dei Centri di Costo.',
            ]);
        }
    }

    private function center(int $id): CostCenter
    {
        return $this->centers[$id]
            ?? throw new \UnexpectedValueException("Centro di Costo [$id] assente dalla gerarchia aziendale.");
    }
}
