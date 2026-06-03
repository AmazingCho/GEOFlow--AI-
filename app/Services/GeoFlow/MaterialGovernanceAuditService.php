<?php

namespace App\Services\GeoFlow;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Tag;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ControlledTagGroups;
use Illuminate\Support\Facades\DB;

class MaterialGovernanceAuditService
{
    /**
     * @return array{summary:array<string,int>,issues:list<array{key:string,label:string,count:int,severity:string,href:string}>}
     */
    public function report(): array
    {
        $issues = [
            $this->issue(
                'unassigned_knowledge',
                __('admin.materials.governance_issue_unassigned_knowledge'),
                KnowledgeBase::query()->whereNull('collection_id')->count(),
                'warning',
                route('admin.knowledge-bases.index')
            ),
            $this->issue(
                'knowledge_without_entities',
                __('admin.materials.governance_issue_knowledge_without_entities'),
                KnowledgeBase::query()
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('entity_material_links')
                            ->whereColumn('entity_material_links.linkable_id', 'knowledge_bases.id')
                            ->where('entity_material_links.linkable_type', KnowledgeBase::class);
                    })
                    ->count(),
                'info',
                route('admin.knowledge-bases.index')
            ),
            $this->issue(
                'entities_without_links',
                __('admin.materials.governance_issue_entities_without_links'),
                EntityRecord::query()
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('entity_material_links')
                            ->whereColumn('entity_material_links.entity_id', 'entities.id');
                    })
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('case_records')
                            ->whereColumn('case_records.entity_id', 'entities.id');
                    })
                    ->count(),
                'info',
                route('admin.entities.index')
            ),
            $this->issue(
                'cases_without_entity',
                __('admin.materials.governance_issue_cases_without_entity'),
                CaseRecord::query()->whereNull('entity_id')->count(),
                'warning',
                route('admin.cases.index')
            ),
            $this->issue(
                'unvectorized_chunks',
                __('admin.materials.governance_issue_unvectorized_chunks'),
                KnowledgeChunk::query()
                    ->where(function ($query): void {
                        $query->whereNull('embedding_model_id')
                            ->orWhere('embedding_dimensions', '<=', 0);
                    })
                    ->count(),
                'warning',
                route('admin.knowledge-bases.index')
            ),
            $this->issue(
                'inactive_or_archive_knowledge',
                __('admin.materials.governance_issue_inactive_archive_knowledge'),
                KnowledgeBase::query()
                    ->where(function ($query): void {
                        $query->where('status', 'inactive')
                            ->orWhere('knowledge_role', 'archive');
                    })
                    ->count(),
                'info',
                route('admin.knowledge-bases.index', ['view' => 'archive'])
            ),
            $this->issue(
                'disallowed_tag_groups',
                __('admin.materials.governance_issue_disallowed_tag_groups'),
                Tag::query()
                    ->where('type', 'material')
                    ->whereNotNull('group_name')
                    ->where('group_name', '!=', '')
                    ->whereNotIn('group_name', ControlledTagGroups::names())
                    ->count(),
                'warning',
                route('admin.material-tags.index')
            ),
            $this->issue(
                'duplicate_tags',
                __('admin.materials.governance_issue_duplicate_tags'),
                $this->duplicateMaterialTagCount(),
                'danger',
                route('admin.material-tags.index')
            ),
            $this->issue(
                'unassigned_libraries',
                __('admin.materials.governance_issue_unassigned_libraries'),
                $this->unassignedLibraryCount(),
                'info',
                route('admin.materials.index')
            ),
        ];

        $issues = array_values(array_filter($issues, static fn (array $issue): bool => $issue['count'] > 0));

        return [
            'summary' => [
                'issues' => count($issues),
                'warnings' => count(array_filter($issues, static fn (array $issue): bool => in_array($issue['severity'], ['warning', 'danger'], true))),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @return array{key:string,label:string,count:int,severity:string,href:string}
     */
    private function issue(string $key, string $label, int $count, string $severity, string $href): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'severity' => $severity,
            'href' => $href,
        ];
    }

    private function duplicateMaterialTagCount(): int
    {
        return DB::table('tags')
            ->selectRaw("LOWER(COALESCE(group_name, '')) as normalized_group, LOWER(name) as normalized_name, COUNT(*) as duplicate_count")
            ->where('type', 'material')
            ->groupByRaw("LOWER(COALESCE(group_name, '')), LOWER(name)")
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->sum(static fn (object $row): int => max(0, (int) $row->duplicate_count - 1));
    }

    private function unassignedLibraryCount(): int
    {
        return KeywordLibrary::query()->whereNull('collection_id')->count()
            + TitleLibrary::query()->whereNull('collection_id')->count()
            + ImageLibrary::query()->whereNull('collection_id')->count()
            + EntityRecord::query()->whereNull('collection_id')->count()
            + CaseRecord::query()->whereNull('collection_id')->count();
    }
}
