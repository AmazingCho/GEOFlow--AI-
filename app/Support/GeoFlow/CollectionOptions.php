<?php

namespace App\Support\GeoFlow;

use App\Models\CollectionRecord;

class CollectionOptions
{
    /**
     * @return list<array{id:int,name:string,status:string}>
     */
    public static function all(bool $activeOnly = false): array
    {
        return CollectionRecord::query()
            ->when($activeOnly, static fn ($query) => $query->where('status', 'active'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'status'])
            ->map(static fn (CollectionRecord $collection): array => [
                'id' => (int) $collection->id,
                'name' => (string) $collection->name,
                'status' => (string) $collection->status,
            ])
            ->all();
    }
}
