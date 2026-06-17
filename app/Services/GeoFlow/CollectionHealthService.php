<?php

namespace App\Services\GeoFlow;

use App\Models\CaseRecord;
use App\Models\CollectionRecord;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\Keyword;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ControlledTagGroups;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectionHealthService
{
    /**
     * @return array<string,mixed>
     */
    public function assess(CollectionRecord|int $collection): array
    {
        $collectionRecord = $collection instanceof CollectionRecord
            ? $collection
            : CollectionRecord::query()->whereKey($collection)->firstOrFail();
        $collectionId = (int) $collectionRecord->id;
        $stats = $this->stats($collectionId);
        $checks = $this->checks($stats);
        $score = max(0, 100 - array_sum(array_map(
            static fn (array $check): int => ! empty($check['passed']) ? 0 : (int) $check['penalty'],
            $checks
        )));

        return [
            'collection' => [
                'id' => $collectionId,
                'name' => (string) $collectionRecord->name,
                'slug' => (string) $collectionRecord->slug,
                'description' => (string) ($collectionRecord->description ?? ''),
                'status' => (string) ($collectionRecord->status ?? 'active'),
            ],
            'score' => $score,
            'status' => $this->status($score),
            'stats' => $stats,
            'checks' => $checks,
        ];
    }

    /**
     * @param  iterable<int, CollectionRecord>  $collections
     * @return array<int, array{score:int,status:string,failed_count:int}>
     */
    public function summariesFor(iterable $collections): array
    {
        $summaries = [];
        foreach ($collections as $collection) {
            $health = $this->assess($collection);
            $summaries[(int) $collection->id] = [
                'score' => (int) $health['score'],
                'status' => (string) $health['status'],
                'failed_count' => collect($health['checks'])->filter(static fn (array $check): bool => empty($check['passed']))->count(),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string,int>
     */
    private function stats(int $collectionId): array
    {
        $knowledgeBaseIds = DB::table('knowledge_bases')->where('collection_id', $collectionId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $caseIds = DB::table('case_records')->where('collection_id', $collectionId)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $unvectorizedChunks = 0;
        $chunkCount = 0;
        if (Schema::hasTable('knowledge_chunks')) {
            $chunkCount = DB::table('knowledge_chunks')
                ->join('knowledge_bases', 'knowledge_bases.id', '=', 'knowledge_chunks.knowledge_base_id')
                ->where('knowledge_bases.collection_id', $collectionId)
                ->count();

            $unvectorizedChunks = DB::table('knowledge_chunks')
                ->join('knowledge_bases', 'knowledge_bases.id', '=', 'knowledge_chunks.knowledge_base_id')
                ->where('knowledge_bases.collection_id', $collectionId)
                ->whereNull('knowledge_chunks.embedding_json')
                ->whereNull('knowledge_chunks.embedding_vector')
                ->count();
        }

        $knowledgeWithoutEntity = 0;
        if (Schema::hasTable('entity_material_links')) {
            $knowledgeWithoutEntity = DB::table('knowledge_bases')
                ->leftJoin('entity_material_links', function ($join): void {
                    $join->on('entity_material_links.linkable_id', '=', 'knowledge_bases.id')
                        ->where('entity_material_links.linkable_type', KnowledgeBase::class);
                })
                ->where('knowledge_bases.collection_id', $collectionId)
                ->whereNull('entity_material_links.id')
                ->count();
        }

        $caseWithoutEntity = 0;
        if (Schema::hasTable('case_record_entity')) {
            $caseWithoutEntity = DB::table('case_records')
                ->leftJoin('case_record_entity', 'case_record_entity.case_record_id', '=', 'case_records.id')
                ->where('case_records.collection_id', $collectionId)
                ->whereNull('case_records.entity_id')
                ->whereNull('case_record_entity.entity_id')
                ->distinct()
                ->count('case_records.id');
        } else {
            $caseWithoutEntity = DB::table('case_records')
                ->where('collection_id', $collectionId)
                ->whereNull('entity_id')
                ->count();
        }

        $tagIds = $this->tagIdsForCollection($collectionId);

        return [
            'entity_count' => DB::table('entities')->where('collection_id', $collectionId)->count(),
            'knowledge_base_count' => count($knowledgeBaseIds),
            'title_library_count' => DB::table('title_libraries')->where('collection_id', $collectionId)->count(),
            'image_library_count' => DB::table('image_libraries')->where('collection_id', $collectionId)->count(),
            'case_count' => count($caseIds),
            'keyword_library_count' => DB::table('keyword_libraries')->where('collection_id', $collectionId)->count(),
            'knowledge_chunk_count' => $chunkCount,
            'unvectorized_chunk_count' => $unvectorizedChunks,
            'knowledge_without_entity_count' => $knowledgeWithoutEntity,
            'case_without_entity_count' => $caseWithoutEntity,
            'disallowed_tag_group_count' => $this->disallowedTagGroupCount($tagIds),
            'duplicate_tag_count' => $this->duplicateTagCount($tagIds),
        ];
    }

    /**
     * @param  array<string,int>  $stats
     * @return list<array{key:string,passed:bool,count:int,penalty:int,label_key:string,description_key:string}>
     */
    private function checks(array $stats): array
    {
        return [
            $this->check('has_entity', $stats['entity_count'] > 0, $stats['entity_count'], 20),
            $this->check('has_knowledge_base', $stats['knowledge_base_count'] > 0, $stats['knowledge_base_count'], 20),
            $this->check('has_title_library', $stats['title_library_count'] > 0, $stats['title_library_count'], 15),
            $this->check('has_image_library', $stats['image_library_count'] > 0, $stats['image_library_count'], 10),
            $this->check('has_case', $stats['case_count'] > 0, $stats['case_count'], 10),
            $this->check('knowledge_linked_entity', $stats['knowledge_without_entity_count'] <= 0, $stats['knowledge_without_entity_count'], 8),
            $this->check('case_linked_entity', $stats['case_without_entity_count'] <= 0, $stats['case_without_entity_count'], 8),
            $this->check('chunks_vectorized', $stats['unvectorized_chunk_count'] <= 0, $stats['unvectorized_chunk_count'], 15),
            $this->check('controlled_tag_groups', $stats['disallowed_tag_group_count'] <= 0, $stats['disallowed_tag_group_count'], 8),
            $this->check('no_duplicate_tags', $stats['duplicate_tag_count'] <= 0, $stats['duplicate_tag_count'], 5),
        ];
    }

    /**
     * @return array{key:string,passed:bool,count:int,penalty:int,label_key:string,description_key:string}
     */
    private function check(string $key, bool $passed, int $count, int $penalty): array
    {
        return [
            'key' => $key,
            'passed' => $passed,
            'count' => $count,
            'penalty' => $penalty,
            'label_key' => 'admin.collections.health.checks.'.$key.'.label',
            'description_key' => 'admin.collections.health.checks.'.$key.'.description',
        ];
    }

    private function status(int $score): string
    {
        if ($score >= 80) {
            return 'good';
        }

        if ($score >= 60) {
            return 'warning';
        }

        return 'critical';
    }

    /**
     * @return list<int>
     */
    private function tagIdsForCollection(int $collectionId): array
    {
        if (! Schema::hasTable('taggables')) {
            return [];
        }

        $tagIds = collect();
        $tagIds = $tagIds->merge($this->directTagIds(EntityRecord::class, 'entities', $collectionId));
        $tagIds = $tagIds->merge($this->directTagIds(KnowledgeBase::class, 'knowledge_bases', $collectionId));
        $tagIds = $tagIds->merge($this->directTagIds(CaseRecord::class, 'case_records', $collectionId));
        $tagIds = $tagIds->merge($this->directTagIds(TitleLibrary::class, 'title_libraries', $collectionId));

        $tagIds = $tagIds->merge(
            DB::table('taggables')
                ->join('keywords', 'keywords.id', '=', 'taggables.taggable_id')
                ->join('keyword_libraries', 'keyword_libraries.id', '=', 'keywords.library_id')
                ->where('taggables.taggable_type', Keyword::class)
                ->where('keyword_libraries.collection_id', $collectionId)
                ->pluck('taggables.tag_id')
        );

        $tagIds = $tagIds->merge(
            DB::table('taggables')
                ->join('images', 'images.id', '=', 'taggables.taggable_id')
                ->join('image_libraries', 'image_libraries.id', '=', 'images.library_id')
                ->where('taggables.taggable_type', Image::class)
                ->where('image_libraries.collection_id', $collectionId)
                ->pluck('taggables.tag_id')
        );

        return $tagIds
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function directTagIds(string $taggableType, string $table, int $collectionId): array
    {
        return DB::table('taggables')
            ->join($table, $table.'.id', '=', 'taggables.taggable_id')
            ->where('taggables.taggable_type', $taggableType)
            ->where($table.'.collection_id', $collectionId)
            ->pluck('taggables.tag_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $tagIds
     */
    private function disallowedTagGroupCount(array $tagIds): int
    {
        if ($tagIds === []) {
            return 0;
        }

        return DB::table('tags')
            ->whereIn('id', $tagIds)
            ->whereNotNull('group_name')
            ->where('group_name', '!=', '')
            ->whereNotIn('group_name', ControlledTagGroups::names())
            ->count();
    }

    /**
     * @param  list<int>  $tagIds
     */
    private function duplicateTagCount(array $tagIds): int
    {
        if ($tagIds === []) {
            return 0;
        }

        return DB::table('tags')
            ->whereIn('id', $tagIds)
            ->selectRaw("LOWER(COALESCE(group_name, '')) as normalized_group, LOWER(name) as normalized_name, COUNT(*) as duplicate_count")
            ->groupByRaw("LOWER(COALESCE(group_name, '')), LOWER(name)")
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->sum(static fn ($row): int => (int) ($row->duplicate_count ?? 0));
    }
}
