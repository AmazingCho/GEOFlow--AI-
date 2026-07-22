<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use App\Models\Task;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ArticleSkillIntents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 文章生成提示词配置控制器。
 *
 * Master Prompt(type=content) 负责正文生成基准；Skill Prompt(type=skill) 负责文章结构策略增强；
 * Style Prompt(type=style) 仅负责可选写作语气与表达方式。
 */
class AiPromptController extends Controller
{
    private const ARTICLE_PROMPT_TYPES = ['content', 'skill', 'style'];

    /**
     * 文章生成提示词列表页。
     */
    public function index(): View
    {
        return view('admin.ai-prompts.index', [
            'pageTitle' => __('admin.ai_prompts.page_title'),
            'activeMenu' => 'ai_config',
            'adminSiteName' => AdminWeb::siteName(),
            'prompts' => $this->loadPrompts(),
            'skillIntents' => ArticleSkillIntents::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(self::ARTICLE_PROMPT_TYPES)],
            'intent_key' => ['nullable', 'string', Rule::in(ArticleSkillIntents::all())],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.required'),
            'type.required' => __('admin.ai_prompts.error.required'),
            'content.required' => __('admin.ai_prompts.error.required'),
        ]);
        $intentKey = (string) $payload['type'] === 'skill'
            ? ArticleSkillIntents::normalize($payload['intent_key'] ?? null)
            : null;
        $this->assertIntentAvailable($intentKey);

        Prompt::query()->create([
            'name' => trim((string) $payload['name']),
            'type' => (string) $payload['type'],
            'intent_key' => $intentKey,
            'content' => trim((string) $payload['content']),
            'variables' => '',
        ]);

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.create_success'));
    }

    public function update(Request $request, int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', self::ARTICLE_PROMPT_TYPES)
            ->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(self::ARTICLE_PROMPT_TYPES)],
            'intent_key' => ['nullable', 'string', Rule::in(ArticleSkillIntents::all())],
            'content' => ['required', 'string'],
        ], [
            'name.required' => __('admin.ai_prompts.error.invalid_fields'),
            'type.required' => __('admin.ai_prompts.error.invalid_fields'),
            'content.required' => __('admin.ai_prompts.error.invalid_fields'),
        ]);

        $nextType = (string) $payload['type'];
        if ($nextType !== (string) $prompt->type && $this->promptUsageCount($promptId) > 0) {
            return back()->withErrors(__('admin.ai_prompts.error.type_change_in_use'));
        }
        $intentKey = $nextType === 'skill'
            ? ArticleSkillIntents::normalize($payload['intent_key'] ?? null)
            : null;
        $this->assertIntentAvailable($intentKey, $promptId);

        $prompt->update([
            'name' => trim((string) $payload['name']),
            'type' => $nextType,
            'intent_key' => $intentKey,
            'content' => trim((string) $payload['content']),
        ]);

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.update_success'));
    }

    public function destroy(int $promptId): RedirectResponse
    {
        $prompt = Prompt::query()
            ->whereKey($promptId)
            ->whereIn('type', self::ARTICLE_PROMPT_TYPES)
            ->firstOrFail();

        $usageCount = $this->promptUsageCount($promptId);
        if ($usageCount > 0) {
            return back()->withErrors(__('admin.ai_prompts.error.in_use', ['count' => $usageCount]));
        }

        $prompt->delete();

        return redirect()->route('admin.ai-prompts')->with('message', __('admin.ai_prompts.message.delete_success'));
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   name:string,
     *   type:string,
     *   content:string,
     *   task_count:int,
     *   created_at:?string
     * }>
     */
    private function loadPrompts(): array
    {
        return Prompt::query()
            ->select(['id', 'name', 'type', 'intent_key', 'content', 'created_at'])
            ->whereIn('type', self::ARTICLE_PROMPT_TYPES)
            ->withCount('tasks')
            ->withCount('skillTasks')
            ->withCount('styleTasks')
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (Prompt $prompt): array {
                return [
                    'id' => (int) $prompt->id,
                    'name' => (string) $prompt->name,
                    'type' => (string) $prompt->type,
                    'intent_key' => ArticleSkillIntents::normalize($prompt->intent_key),
                    'content' => (string) $prompt->content,
                    'task_count' => (int) ($prompt->tasks_count ?? 0)
                        + (int) ($prompt->skill_tasks_count ?? 0)
                        + (int) ($prompt->style_tasks_count ?? 0),
                    'created_at' => optional($prompt->created_at)?->format('Y-m-d H:i'),
                ];
            })
            ->all();
    }

    private function promptUsageCount(int $promptId): int
    {
        $query = Task::query()->where('prompt_id', $promptId);
        if (Schema::hasColumn('tasks', 'skill_prompt_id')) {
            $query->orWhere('skill_prompt_id', $promptId);
        }
        if (Schema::hasColumn('tasks', 'style_prompt_id')) {
            $query->orWhere('style_prompt_id', $promptId);
        }

        return (int) $query->count();
    }

    private function assertIntentAvailable(?string $intentKey, ?int $ignorePromptId = null): void
    {
        if ($intentKey === null) {
            return;
        }

        $query = Prompt::query()
            ->where('type', 'skill')
            ->where('intent_key', $intentKey);
        if ($ignorePromptId !== null) {
            $query->whereKeyNot($ignorePromptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'intent_key' => __('admin.ai_prompts.error.intent_in_use'),
            ]);
        }
    }
}
