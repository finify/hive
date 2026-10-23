<?php

namespace App\Livewire\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;

trait PaginatesCollections
{
    use WithPagination;

    public string $sort = '';

    public string $direction = 'asc';

    public function sortBy(string $field): void
    {
        $this->direction = $this->sort === $field && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $field;
        $this->resetPage();
    }

    public function updating(string $property): void
    {
        if (! in_array($property, ['sort', 'direction'], true) && ! str_starts_with($property, 'paginators')) {
            $this->resetPage();
        }
    }

    /**
     * @template TValue
     *
     * @param  Collection<array-key, TValue>  $items
     * @return LengthAwarePaginator<array-key, TValue>
     */
    protected function paginateCollection(Collection $items, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->getPage();

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url()],
        );
    }

    /**
     * @template TValue
     *
     * @param  Collection<array-key, TValue>  $items
     * @param  array<string, callable(TValue): mixed>  $sorters
     * @return Collection<array-key, TValue>
     */
    protected function applySort(Collection $items, array $sorters): Collection
    {
        if (! isset($sorters[$this->sort])) {
            return $items;
        }

        $sorted = $items->sortBy($sorters[$this->sort], SORT_NATURAL | SORT_FLAG_CASE);

        return $this->direction === 'desc' ? $sorted->reverse() : $sorted;
    }
}
