<?php

namespace App\Services\GeoFlow;

use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EntityMaterialLinkService
{
    public const KNOWLEDGE_RELATION_TYPES = [
        'primary_subject',
        'supporting_reference',
        'competitor_reference',
        'application_reference',
        'troubleshooting_reference',
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    public function materialClassMap(): array
    {
        return [
            'knowledge_base_ids' => KnowledgeBase::class,
            'keyword_library_ids' => KeywordLibrary::class,
            'image_library_ids' => ImageLibrary::class,
            'image_ids' => Image::class,
        ];
    }

    /**
     * @return list<array{id:int,label:string,collection_id:int}>
     */
    public function entityOptions(?int $collectionId = null): array
    {
        $query = EntityRecord::query()
            ->with('collection:id,name')
            ->select(['id', 'collection_id', 'name', 'entity_type'])
            ->orderBy('name')
            ->limit(500);

        if ($collectionId !== null) {
            $query->where(function ($builder) use ($collectionId): void {
                $builder->whereNull('collection_id')->orWhere('collection_id', $collectionId);
            });
        }

        return $query->get()->map(static function (EntityRecord $entity): array {
            $parts = [(string) $entity->name];
            if ((string) ($entity->entity_type ?? '') !== '') {
                $parts[] = (string) $entity->entity_type;
            }
            if ($entity->collection && (string) $entity->collection->name !== '') {
                $parts[] = (string) $entity->collection->name;
            }

            return [
                'id' => (int) $entity->id,
                'label' => implode(' / ', $parts),
                'collection_id' => (int) ($entity->collection_id ?? 0),
            ];
        })->all();
    }

    /**
     * @return list<int>
     */
    public function selectedEntityIdsFor(Model $model): array
    {
        return DB::table('entity_material_links')
            ->where('linkable_type', $model::class)
            ->where('linkable_id', (int) $model->getKey())
            ->pluck('entity_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function selectedKnowledgeRelationTypeFor(Model $model): string
    {
        $role = DB::table('entity_material_links')
            ->where('linkable_type', $model::class)
            ->where('linkable_id', (int) $model->getKey())
            ->whereIn('link_role', self::KNOWLEDGE_RELATION_TYPES)
            ->orderByRaw($this->relationPrioritySql())
            ->value('link_role');

        return $this->normalizeKnowledgeRelationType(is_string($role) ? $role : '');
    }

    /**
     * @param  list<int>  $entityIds
     */
    public function syncEntities(Model $model, array $entityIds, string $relationType = 'supporting_reference'): void
    {
        $entityIds = $this->existingEntityIds($entityIds);
        $linkableType = $model::class;
        $linkableId = (int) $model->getKey();
        $linkRole = $model instanceof KnowledgeBase
            ? $this->normalizeKnowledgeRelationType($relationType)
            : 'related';

        DB::transaction(function () use ($entityIds, $linkableType, $linkableId, $linkRole): void {
            DB::table('entity_material_links')
                ->where('linkable_type', $linkableType)
                ->where('linkable_id', $linkableId)
                ->delete();

            $this->insertRows($entityIds, $linkableType, $linkableId, $linkRole);
        });
    }

    /**
     * @param  array<string,list<int>>  $materialIdsByKey
     * @param  array<int,string>  $knowledgeRelationTypesById
     */
    public function syncMaterialsForEntity(
        EntityRecord $entity,
        array $materialIdsByKey,
        string $knowledgeRelationType = 'supporting_reference',
        array $knowledgeRelationTypesById = []
    ): void
    {
        $entityId = (int) $entity->id;
        $classMap = $this->materialClassMap();
        $knowledgeRelationType = $this->normalizeKnowledgeRelationType($knowledgeRelationType);

        DB::transaction(function () use ($entityId, $classMap, $materialIdsByKey, $knowledgeRelationType, $knowledgeRelationTypesById): void {
            DB::table('entity_material_links')
                ->where('entity_id', $entityId)
                ->whereIn('linkable_type', array_merge(array_values($classMap), [TitleLibrary::class]))
                ->delete();

            foreach ($classMap as $key => $className) {
                $ids = $this->existingModelIds($className, $materialIdsByKey[$key] ?? []);
                foreach ($ids as $id) {
                    $linkRole = 'related';
                    if ($className === KnowledgeBase::class) {
                        $linkRole = $this->normalizeKnowledgeRelationType((string) ($knowledgeRelationTypesById[$id] ?? $knowledgeRelationType));
                    }

                    DB::table('entity_material_links')->insertOrIgnore([
                        'entity_id' => $entityId,
                        'linkable_type' => $className,
                        'linkable_id' => $id,
                        'link_role' => $linkRole,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * @return array<string,list<int>>
     */
    public function selectedMaterialIdsForEntity(EntityRecord $entity): array
    {
        $result = [];
        foreach ($this->materialClassMap() as $key => $className) {
            $result[$key] = DB::table('entity_material_links')
                ->where('entity_id', (int) $entity->id)
                ->where('linkable_type', $className)
                ->pluck('linkable_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $result;
    }

    public function selectedKnowledgeRelationTypeForEntity(EntityRecord $entity): string
    {
        $role = DB::table('entity_material_links')
            ->where('entity_id', (int) $entity->id)
            ->where('linkable_type', KnowledgeBase::class)
            ->whereIn('link_role', self::KNOWLEDGE_RELATION_TYPES)
            ->orderByRaw($this->relationPrioritySql())
            ->value('link_role');

        return $this->normalizeKnowledgeRelationType(is_string($role) ? $role : '');
    }

    /**
     * @return array<int,string>
     */
    public function selectedKnowledgeRelationTypesForEntity(EntityRecord $entity): array
    {
        return DB::table('entity_material_links')
            ->where('entity_id', (int) $entity->id)
            ->where('linkable_type', KnowledgeBase::class)
            ->whereIn('link_role', self::KNOWLEDGE_RELATION_TYPES)
            ->pluck('link_role', 'linkable_id')
            ->mapWithKeys(fn (mixed $role, mixed $id): array => [
                (int) $id => $this->normalizeKnowledgeRelationType(is_string($role) ? $role : ''),
            ])
            ->all();
    }

    /**
     * @return array<string,list<array{id:int,label:string}>>
     */
    public function materialOptions(?int $collectionId = null): array
    {
        return [
            'knowledge_base_ids' => $this->libraryOptions(KnowledgeBase::query(), '知识库', $collectionId),
            'keyword_library_ids' => $this->libraryOptions(KeywordLibrary::query(), '关键词库', $collectionId),
            'image_library_ids' => $this->libraryOptions(ImageLibrary::query(), '图片库', $collectionId),
            'image_ids' => $this->imageOptions($collectionId),
        ];
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public function knowledgeRelationTypeOptions(): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => __('admin.entities.knowledge_relation_types.'.$value),
        ], self::KNOWLEDGE_RELATION_TYPES);
    }

    public function normalizeKnowledgeRelationType(string $relationType): string
    {
        return in_array($relationType, self::KNOWLEDGE_RELATION_TYPES, true)
            ? $relationType
            : 'supporting_reference';
    }

    /**
     * @param  list<int>  $entityIds
     * @return list<int>
     */
    private function existingEntityIds(array $entityIds): array
    {
        return EntityRecord::query()
            ->whereIn('id', $this->normalizeIds($entityIds))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  class-string<Model>  $className
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function existingModelIds(string $className, array $ids): array
    {
        return $className::query()
            ->whereIn('id', $this->normalizeIds($ids))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  iterable<int,mixed>  $ids
     * @return list<int>
     */
    private function normalizeIds(iterable $ids): array
    {
        return collect($ids)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $entityIds
     */
    private function insertRows(array $entityIds, string $linkableType, int $linkableId, string $linkRole = 'related'): void
    {
        foreach ($entityIds as $entityId) {
            DB::table('entity_material_links')->insertOrIgnore([
                'entity_id' => $entityId,
                'linkable_type' => $linkableType,
                'linkable_id' => $linkableId,
                'link_role' => $linkRole,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function relationPrioritySql(): string
    {
        return "CASE link_role
            WHEN 'primary_subject' THEN 1
            WHEN 'supporting_reference' THEN 2
            WHEN 'application_reference' THEN 3
            WHEN 'troubleshooting_reference' THEN 4
            WHEN 'competitor_reference' THEN 5
            ELSE 9
        END";
    }

    /**
     * @return list<array{id:int,label:string}>
     */
    private function libraryOptions($query, string $typeLabel, ?int $collectionId): array
    {
        $query
            ->with('collection:id,name')
            ->select(['id', 'collection_id', 'name'])
            ->orderBy('name')
            ->limit(500);

        if ($collectionId !== null) {
            $query->where(function ($builder) use ($collectionId): void {
                $builder->whereNull('collection_id')->orWhere('collection_id', $collectionId);
            });
        }

        return $query->get()->map(static function (Model $model) use ($typeLabel): array {
            $collectionName = (string) ($model->collection?->name ?? '');

            return [
                'id' => (int) $model->getKey(),
                'label' => $typeLabel.' / '.(string) $model->getAttribute('name').($collectionName !== '' ? ' / '.$collectionName : ''),
                'meta' => $collectionName !== '' ? 'Collection: '.$collectionName : '',
                'collection_id' => (int) ($model->getAttribute('collection_id') ?? 0),
            ];
        })->all();
    }

    /**
     * @return list<array{id:int,label:string}>
     */
    private function imageOptions(?int $collectionId): array
    {
        $query = Image::query()
            ->with('library.collection:id,name')
            ->select(['id', 'library_id', 'original_name', 'filename', 'file_path', 'width', 'height', 'tags'])
            ->orderByDesc('id')
            ->limit(500);

        if ($collectionId !== null) {
            $query->whereHas('library', function ($builder) use ($collectionId): void {
                $builder->whereNull('collection_id')->orWhere('collection_id', $collectionId);
            });
        }

        return $query->get()->map(static function (Image $image): array {
            $name = trim((string) ($image->original_name ?? '')) ?: trim((string) ($image->filename ?? '')) ?: trim((string) ($image->file_path ?? ''));
            $libraryName = (string) ($image->library?->name ?? '');
            $collectionName = (string) ($image->library?->collection?->name ?? '');
            $dimensions = ((int) ($image->width ?? 0) > 0 && (int) ($image->height ?? 0) > 0)
                ? (int) $image->width.'x'.(int) $image->height
                : '';
            $meta = implode(' / ', array_values(array_filter([
                $libraryName !== '' ? '图库: '.$libraryName : '',
                $collectionName !== '' ? 'Collection: '.$collectionName : '',
                $dimensions,
                trim((string) ($image->tags ?? '')),
            ])));

            return [
                'id' => (int) $image->id,
                'label' => '图片 / '.$name.($libraryName !== '' ? ' / '.$libraryName : ''),
                'meta' => $meta,
                'thumbnail' => \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) ($image->file_path ?? '')),
                'collection_id' => (int) ($image->library?->collection_id ?? 0),
            ];
        })->all();
    }
}
