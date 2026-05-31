<?php

namespace App\Services\GeoFlow;

use App\Models\Image;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagService
{
    /**
     * @return list<array{group_name:string,name:string}>
     */
    public function parseTagText(string $tagText): array
    {
        $normalized = str_replace(["\r\n", "\r", "\n", '，', '、', '；', ';'], ',', $tagText);
        $parts = preg_split('/,+/u', $normalized) ?: [];
        $tags = [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            $groupName = '';
            $name = $part;
            if (preg_match('/^([^:=：=]{1,100})[:：=](.+)$/u', $part, $matches) === 1) {
                $groupName = trim((string) ($matches[1] ?? ''));
                $name = trim((string) ($matches[2] ?? ''));
            }

            $groupName = mb_substr($this->compactWhitespace($groupName), 0, 100, 'UTF-8');
            $name = mb_substr($this->compactWhitespace($name), 0, 100, 'UTF-8');
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($groupName."\0".$name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tags[] = [
                'group_name' => $groupName,
                'name' => $name,
            ];
        }

        return $tags;
    }

    public function normalizeTagText(string $tagText): string
    {
        return implode(', ', array_map(
            static fn (array $tag): string => $tag['group_name'] !== '' ? $tag['group_name'].':'.$tag['name'] : $tag['name'],
            $this->parseTagText($tagText)
        ));
    }

    public function firstOrCreateTag(string $groupName, string $name, string $type = 'material'): Tag
    {
        $groupName = mb_substr($this->compactWhitespace($groupName), 0, 100, 'UTF-8');
        $name = mb_substr($this->compactWhitespace($name), 0, 100, 'UTF-8');
        if ($name === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty.');
        }

        $tag = Tag::query()->firstOrCreate(
            [
                'type' => $type,
                'group_name' => $groupName,
                'name' => $name,
            ],
            [
                'slug' => $this->buildSlug($type, $groupName, $name),
                'color' => '',
            ]
        );
        $this->flushTagStatsCache();

        return $tag;
    }

    /**
     * @return list<string>
     */
    public function sync(Model $model, string $tagText, string $type = 'material'): array
    {
        $tagIds = [];
        foreach ($this->parseTagText($tagText) as $tag) {
            $tagModel = $this->firstOrCreateTag($tag['group_name'], $tag['name'], $type);
            $tagIds[] = (int) $tagModel->id;
        }

        return $this->syncExisting($model, $tagIds, $type);
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<string>
     */
    public function syncExisting(Model $model, array $tagIds, string $type = 'material'): array
    {
        $tagIds = $this->existingTagIds($tagIds, $type);
        $model->tags()->sync($tagIds);
        $model->unsetRelation('tags');
        $this->flushTagStatsCache();

        return $this->labelsFor($model);
    }

    /**
     * @return list<array{id:int,label:string}>
     */
    public function existingTagOptions(string $type = 'material'): array
    {
        return Tag::query()
            ->where('type', $type)
            ->orderBy('group_name')
            ->orderBy('name')
            ->get(['id', 'group_name', 'name'])
            ->map(static fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'label' => $tag->displayName(),
            ])
            ->filter(static fn (array $tag): bool => $tag['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<array{id:int,label:string}>
     */
    public function tagOptionsForIds(array $tagIds, string $type = 'material'): array
    {
        $tagIds = $this->existingTagIds($tagIds, $type);
        if ($tagIds === []) {
            return [];
        }

        return Tag::query()
            ->where('type', $type)
            ->whereIn('id', $tagIds)
            ->orderBy('group_name')
            ->orderBy('name')
            ->get(['id', 'group_name', 'name'])
            ->map(static fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'label' => $tag->displayName(),
            ])
            ->filter(static fn (array $tag): bool => $tag['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:int,label:string}>
     */
    public function searchTagOptions(string $query, string $type = 'material', int $limit = 20): array
    {
        $query = trim($query);
        $limit = min(50, max(1, $limit));

        return Tag::query()
            ->where('type', $type)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $nested) use ($query): void {
                    $nested
                        ->where('name', 'like', '%'.$query.'%')
                        ->orWhere('group_name', 'like', '%'.$query.'%');
                });
            })
            ->orderBy('group_name')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'group_name', 'name'])
            ->map(static fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'label' => $tag->displayName(),
            ])
            ->filter(static fn (array $tag): bool => $tag['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function selectedTagIdsFor(Model $model): array
    {
        return $model->tags()
            ->pluck('tags.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $tagIds
     */
    public function tagTextForIds(array $tagIds, string $type = 'material'): string
    {
        $tagIds = $this->existingTagIds($tagIds, $type);
        if ($tagIds === []) {
            return '';
        }

        return Tag::query()
            ->whereIn('id', $tagIds)
            ->orderBy('group_name')
            ->orderBy('name')
            ->get(['id', 'group_name', 'name'])
            ->map(static fn (Tag $tag): string => $tag->displayName())
            ->filter(static fn (string $label): bool => $label !== '')
            ->values()
            ->implode(', ');
    }

    /**
     * @return list<string>
     */
    public function labelsFor(Model $model): array
    {
        /** @var Collection<int, Tag> $tags */
        $tags = $model->relationLoaded('tags')
            ? $model->getRelation('tags')
            : $model->tags()->orderBy('group_name')->orderBy('name')->get();

        return $tags
            ->map(static fn (Tag $tag): string => $tag->displayName())
            ->filter(static fn (string $label): bool => $label !== '')
            ->values()
            ->all();
    }

    public function tagTextFor(Model $model): string
    {
        return implode(', ', $this->labelsFor($model));
    }

    /**
     * @param  list<int>  $imageIds
     */
    public function refreshLegacyImageTagText(array $imageIds): void
    {
        $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
        if ($imageIds === []) {
            return;
        }

        Image::query()
            ->whereIn('id', $imageIds)
            ->get(['id'])
            ->each(function (Image $image): void {
                $image->update(['tags' => $this->tagTextFor($image)]);
            });
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<int>
     */
    public function imageIdsForTags(array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            return [];
        }

        $image = new Image;

        return DB::table('taggables')
            ->where('taggable_type', $image->getMorphClass())
            ->whereIn('tag_id', $tagIds)
            ->pluck('taggable_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $ids
     */
    public function detachTaggables(string $modelClass, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        /** @var Model $model */
        $model = new $modelClass;
        DB::table('taggables')
            ->where('taggable_type', $model->getMorphClass())
            ->whereIn('taggable_id', $ids)
            ->delete();
        $this->flushTagStatsCache();
    }

    public function applyFilter(Builder $query, string $tagFilter): void
    {
        $tagFilter = trim($tagFilter);
        if ($tagFilter === '') {
            return;
        }

        [$groupName, $name] = $this->splitFilter($tagFilter);
        $query->whereHas('tags', function (Builder $builder) use ($groupName, $name, $tagFilter): void {
            if ($groupName !== '') {
                $builder
                    ->where('group_name', 'like', '%'.$groupName.'%')
                    ->where('name', 'like', '%'.$name.'%');

                return;
            }

            $builder->where(function (Builder $nested) use ($tagFilter): void {
                $nested
                    ->where('name', 'like', '%'.$tagFilter.'%')
                    ->orWhere('group_name', 'like', '%'.$tagFilter.'%');
            });
        });
    }

    private function splitFilter(string $tagFilter): array
    {
        if (preg_match('/^([^:=：=]+)[:：=](.+)$/u', $tagFilter, $matches) !== 1) {
            return ['', $tagFilter];
        }

        return [
            trim((string) ($matches[1] ?? '')),
            trim((string) ($matches[2] ?? '')),
        ];
    }

    private function compactWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<int>
     */
    private function existingTagIds(array $tagIds, string $type): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            return [];
        }

        return Tag::query()
            ->where('type', $type)
            ->whereIn('id', $tagIds)
            ->orderBy('group_name')
            ->orderBy('name')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function buildSlug(string $type, string $groupName, string $name): string
    {
        $base = trim($type.' '.$groupName.' '.$name);
        $slug = Str::slug($base);

        return $slug !== '' ? mb_substr($slug, 0, 160, 'UTF-8') : 'tag-'.substr(sha1($base), 0, 16);
    }

    private function flushTagStatsCache(): void
    {
        Cache::forget('admin.material_tags.stats');
        Cache::forget('tag_recommendations:material_tags');
    }
}
