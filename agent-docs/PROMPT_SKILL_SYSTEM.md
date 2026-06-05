# Prompt Skill System v1

## Purpose

GEOFlow now supports a lightweight article prompt layering model:

1. `prompt_id` is the required Master Prompt.
2. `skill_prompt_id` is an optional Skill Prompt.
3. The Worker composes Master Prompt + Skill Prompt before rendering `{{title}}`, `{{keyword}}`, and `{{Knowledge}}`.

This is intentionally small. It is not yet automatic search-intent classification and it is not a separate material library.

## User-Facing Entry Points

- Admin menu: AI config -> Article Prompt Configuration.
- Prompt page:
  - `content` = Master Prompt.
  - `skill` = Skill Prompt.
- Task create/edit page:
  - Content Settings -> Content Prompt is required.
  - Content Settings -> Skill Prompt is optional.

## Default Skill Prompts

Migration `2026_06_05_010000_add_skill_prompt_id_to_tasks` creates three editable starter Skill Prompts:

- `GEO Skill - Comparison`
- `GEO Skill - Buying Guide`
- `GEO Skill - Application`

Users can edit or delete them. Tasks do not use them unless explicitly selected.

## Generation Rules

- No Skill Prompt selected: generation behaves like the previous Master Prompt-only flow.
- Skill Prompt selected: Worker composes:

```text
=== Master Prompt ===
...

=== Skill Prompt ===
...
```

- Final language instruction is still appended after template rendering.
- RAG retrieval, image matching, quality scoring, and distribution are unchanged.
- Generation trace records:
  - `skill_prompt_id` in the `select_sources` pipeline step.
  - `has_skill_prompt` in the `compose_prompt` pipeline step.
  - `skill_prompt` in the article generation trace.

## Guardrails

- Do not repurpose Skill Prompt as Collection, Entity, Tag, or Knowledge Base metadata.
- Do not auto-apply Skill Prompt unless a future stage adds an explicit intent-classification design.
- Do not let used prompts switch type directly; create a new prompt and update task references.
- If adding automatic intent matching later, keep manual override visible on the task page.

## Verification Commands

```bash
docker exec -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= geoflow-app php artisan test tests/Unit/WorkerExecutionServicePromptTest.php --stop-on-failure
docker exec -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= geoflow-app php artisan test tests/Feature/AdminAiPromptsPageTest.php tests/Feature/AdminTasksPageTest.php --filter='skill_prompt|create_page|default_content_prompts' --stop-on-failure
```
