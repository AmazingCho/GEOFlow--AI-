<?php

namespace App\Services\GeoFlow;

use App\Models\EntityRecord;
use App\Models\EntityRelation;
use App\Models\RelationType;
use Illuminate\Support\Collection;

class EntityRelationService
{
    /**
     * @return Collection<int, RelationType>
     */
    public function relationTypes(): Collection
    {
        return RelationType::query()->orderBy('sort_order')->get();
    }

    /**
     * Get all relations for a given entity, structured by direction.
     *
     * @return array{as_source: array, as_target: array}
     */
    public function relatedEntities(int $entityId, int $minStrength = 0, int $limit = 50): array
    {
        $asSource = EntityRelation::query()
            ->with(['targetEntity', 'relationType'])
            ->where('source_entity_id', $entityId)
            ->when($minStrength > 0, fn ($q) => $q->where('strength', '>=', $minStrength))
            ->orderByDesc('strength')
            ->limit($limit)
            ->get()
            ->map(fn (EntityRelation $r): array => [
                'id' => (int) $r->id,
                'direction' => 'forward',
                'entity' => $r->targetEntity->only(['id', 'name', 'entity_type']),
                'relation_type' => $r->relationType->only(['id', 'name', 'forward_label', 'reverse_label', 'bidirectional']),
                'strength' => (int) $r->strength,
                'notes' => (string) $r->notes,
            ]);

        $asTarget = EntityRelation::query()
            ->with(['sourceEntity', 'relationType'])
            ->where('target_entity_id', $entityId)
            ->when($minStrength > 0, fn ($q) => $q->where('strength', '>=', $minStrength))
            ->orderByDesc('strength')
            ->limit($limit)
            ->get()
            ->map(fn (EntityRelation $r): array => [
                'id' => (int) $r->id,
                'direction' => 'reverse',
                'entity' => $r->sourceEntity->only(['id', 'name', 'entity_type']),
                'relation_type' => $r->relationType->only(['id', 'name', 'forward_label', 'reverse_label', 'bidirectional']),
                'strength' => (int) $r->strength,
                'notes' => (string) $r->notes,
            ]);

        return [
            'as_source' => $asSource->all(),
            'as_target' => $asTarget->all(),
        ];
    }

    public function addRelation(int $sourceId, int $typeId, int $targetId, int $strength = 80, array $extra = []): EntityRelation
    {
        return EntityRelation::query()->updateOrCreate(
            [
                'source_entity_id' => $sourceId,
                'relation_type_id' => $typeId,
                'target_entity_id' => $targetId,
            ],
            [
                'strength' => max(0, min(100, $strength)),
                'source_type' => (string) ($extra['source_type'] ?? 'manual'),
                'notes' => (string) ($extra['notes'] ?? ''),
            ],
        );
    }

    public function removeRelation(int $relationId): void
    {
        EntityRelation::query()->whereKey($relationId)->delete();
    }

    /**
     * Sync relations from form submission.
     *
     * @param  list<array{source_entity_id:int,relation_type_id:int,target_entity_id:int,strength:int}>  $relations
     */
    public function syncRelations(int $entityId, array $relations): void
    {
        $keepIds = [];

        foreach ($relations as $rel) {
            $sId = (int) ($rel['source_entity_id'] ?? 0);
            $tId = (int) ($rel['target_entity_id'] ?? 0);
            $typeId = (int) ($rel['relation_type_id'] ?? 0);

            if ($sId <= 0 || $tId <= 0 || $typeId <= 0) {
                continue;
            }

            $r = $this->addRelation($sId, $typeId, $tId, (int) ($rel['strength'] ?? 80), [
                'source_type' => (string) ($rel['source_type'] ?? 'manual'),
                'notes' => (string) ($rel['notes'] ?? ''),
            ]);

            $keepIds[] = (int) $r->id;
        }

        // Remove relations for this entity that are no longer in the submitted set
        if ($keepIds !== []) {
            EntityRelation::query()
                ->whereKeyNot($keepIds)
                ->where(function ($q) use ($entityId): void {
                    $q->where('source_entity_id', $entityId)
                        ->orWhere('target_entity_id', $entityId);
                })
                ->delete();
        }
    }
}
