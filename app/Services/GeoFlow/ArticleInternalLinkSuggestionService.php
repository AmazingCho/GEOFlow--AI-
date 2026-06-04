<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleInternalLink;
use App\Models\EntityRecord;
use App\Support\GeoFlow\EntityTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArticleInternalLinkSuggestionService
{
    /**
     * @return list<array<string,mixed>>
     */
    public function suggest(Article $article, array $generationTrace = []): array
    {
        $content = (string) ($article->content ?? '');
        if (trim($content) === '') {
            return [];
        }

        return $this->candidateEntities($article, $generationTrace)
            ->map(fn (EntityRecord $entity): ?array => $this->suggestForEntity($content, $entity))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $entityIds
     * @return array{applied:int,skipped:int}
     */
    public function apply(Article $article, array $entityIds, string $appliedBy = ''): array
    {
        $entityIds = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0)));
        if ($entityIds === []) {
            return ['applied' => 0, 'skipped' => 0];
        }

        $applied = 0;
        $skipped = 0;

        DB::transaction(function () use ($article, $entityIds, $appliedBy, &$applied, &$skipped): void {
            $article->refresh();
            $content = (string) ($article->content ?? '');
            $entities = EntityRecord::query()
                ->whereIn('id', $entityIds)
                ->where('link_policy', EntityTypes::LINK_POLICY_SUGGEST)
                ->where('canonical_url', '!=', '')
                ->orderBy('name')
                ->get();

            foreach ($entities as $entity) {
                $suggestion = $this->suggestForEntity($content, $entity);
                if ($suggestion === null) {
                    $skipped++;
                    continue;
                }

                $position = (int) $suggestion['position'];
                $matched = (string) $suggestion['matched_text'];
                $url = (string) $suggestion['canonical_url'];
                $replacement = '['.$matched.']('.$url.')';
                $content = mb_substr($content, 0, $position, 'UTF-8')
                    .$replacement
                    .mb_substr($content, $position + mb_strlen($matched, 'UTF-8'), null, 'UTF-8');

                ArticleInternalLink::query()->updateOrCreate(
                    [
                        'article_id' => (int) $article->id,
                        'entity_id' => (int) $entity->id,
                        'canonical_url' => $url,
                    ],
                    [
                        'anchor_text' => (string) $suggestion['anchor_text'],
                        'matched_text' => $matched,
                        'status' => 'applied',
                        'applied_by' => $appliedBy,
                        'applied_at' => now(),
                    ]
                );
                $applied++;
            }

            $article->forceFill(['content' => $content])->save();
        });

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * @return Collection<int,EntityRecord>
     */
    private function candidateEntities(Article $article, array $generationTrace): Collection
    {
        $ids = collect($article->selected_entity_ids ?? [])
            ->merge(collect(data_get($generationTrace, 'knowledge.entities', []))->pluck('id'))
            ->merge(collect(data_get($article->context_snapshot, 'entities', []))->pluck('id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $query = EntityRecord::query()
            ->where('link_policy', EntityTypes::LINK_POLICY_SUGGEST)
            ->where('canonical_url', '!=', '')
            ->whereIn('entity_type', EntityTypes::linkableValues());

        if ($ids !== []) {
            $query->where(function ($builder) use ($ids, $article): void {
                $builder->whereIn('id', $ids);
                if ((int) ($article->selected_collection_id ?? 0) > 0) {
                    $builder->orWhere('collection_id', (int) $article->selected_collection_id);
                }
            });
        } elseif ((int) ($article->selected_collection_id ?? 0) > 0) {
            $query->where('collection_id', (int) $article->selected_collection_id);
        }

        return $query->orderBy('name')->limit(30)->get();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function suggestForEntity(string $content, EntityRecord $entity): ?array
    {
        $url = trim((string) ($entity->canonical_url ?? ''));
        $type = (string) ($entity->entity_type ?? '');
        if ($url === '' || ! EntityTypes::isLinkable($type)) {
            return null;
        }
        if (str_contains($content, $url)) {
            return null;
        }

        foreach ($this->anchorCandidates($entity) as $anchor) {
            $position = $this->findUsablePosition($content, $anchor);
            if ($position === null) {
                continue;
            }

            return [
                'entity_id' => (int) $entity->id,
                'entity_name' => (string) $entity->name,
                'entity_type' => $type,
                'anchor_text' => $anchor,
                'matched_text' => mb_substr($content, $position, mb_strlen($anchor, 'UTF-8'), 'UTF-8'),
                'canonical_url' => $url,
                'position' => $position,
                'snippet' => $this->snippet($content, $position, $anchor),
                'reason' => EntityTypes::roleDescription($type),
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function anchorCandidates(EntityRecord $entity): array
    {
        $anchors = [
            trim((string) ($entity->link_anchor_text ?? '')),
            trim((string) $entity->name),
        ];

        foreach (preg_split('/[\r\n,，;；]+/u', (string) ($entity->aliases ?? '')) ?: [] as $alias) {
            $anchors[] = trim($alias);
        }

        return collect($anchors)
            ->filter(static fn (string $anchor): bool => mb_strlen($anchor, 'UTF-8') >= 2)
            ->unique()
            ->values()
            ->all();
    }

    private function findUsablePosition(string $content, string $anchor): ?int
    {
        $offset = 0;
        while (($position = mb_strpos($content, $anchor, $offset, 'UTF-8')) !== false) {
            if (! $this->isInsideMarkdownLink($content, $position, $anchor)
                && ! $this->isInsideCodeFence($content, $position)
                && ! $this->isHeadingLine($content, $position)
            ) {
                return (int) $position;
            }
            $offset = (int) $position + mb_strlen($anchor, 'UTF-8');
        }

        return null;
    }

    private function isInsideMarkdownLink(string $content, int $position, string $anchor): bool
    {
        $before = mb_substr($content, 0, $position, 'UTF-8');
        $after = mb_substr($content, $position + mb_strlen($anchor, 'UTF-8'), 20, 'UTF-8');
        $lastOpen = mb_strrpos($before, '[', 0, 'UTF-8');
        $lastClose = mb_strrpos($before, ']', 0, 'UTF-8');

        return $lastOpen !== false
            && ($lastClose === false || $lastOpen > $lastClose)
            && str_starts_with($after, '](');
    }

    private function isInsideCodeFence(string $content, int $position): bool
    {
        $before = mb_substr($content, 0, $position, 'UTF-8');

        return substr_count($before, '```') % 2 === 1;
    }

    private function isHeadingLine(string $content, int $position): bool
    {
        $before = mb_substr($content, 0, $position, 'UTF-8');
        $lastNewline = mb_strrpos($before, "\n", 0, 'UTF-8');
        $lineStart = $lastNewline === false ? 0 : ((int) $lastNewline + 1);
        $line = ltrim(mb_substr($content, $lineStart, $position - $lineStart + 1, 'UTF-8'));

        return str_starts_with($line, '#');
    }

    private function snippet(string $content, int $position, string $anchor): string
    {
        $start = max(0, $position - 45);
        $length = mb_strlen($anchor, 'UTF-8') + 90;
        $snippet = trim(preg_replace('/\s+/u', ' ', mb_substr($content, $start, $length, 'UTF-8')) ?? '');

        return ($start > 0 ? '...' : '').$snippet.(($start + $length) < mb_strlen($content, 'UTF-8') ? '...' : '');
    }
}
