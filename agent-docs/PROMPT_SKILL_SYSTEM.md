# Prompt / Skill / Style System V2.2

## Purpose

GEOFlow supports a layered article-generation model with an optional deep-quality pipeline:

1. `prompt_id` is the required Master Prompt.
2. `skill_selection_mode` stores `none`, `manual`, or `auto`.
3. `skill_prompt_id` stores only the fixed Skill used by Manual mode.
4. The Worker resolves Auto after selecting each title, then composes Master Prompt + resolved Skill Prompt before rendering `{{title}}`, `{{keyword}}`, and `{{Knowledge}}`.
5. `style_prompt_id` optionally adds expression preferences after Master and Skill; leaving it empty is the normal default.
6. `generation_mode` stores `standard` or `deep`. Historical and new tasks default to `standard`.

This is intentionally small. It includes deterministic seven-intent recommendation, not a full AI search-intent classifier, and it is not a separate material library.

## User-Facing Entry Points

- Admin menu: AI config -> Article Prompt Configuration.
- Prompt page:
  - `content` = Master Prompt.
  - `skill` = Skill Prompt.
  - `style` = optional Style Prompt.
- Task create/edit page:
  - Content Settings -> Content Prompt is required.
  - Content Settings -> Skill Prompt supports smart recommendation, no Skill Prompt, or manual selection.
  - Advanced Generation Settings -> Writing Style and Generation Strategy are optional controls.

## Default Skill Prompts

Migration `2026_06_05_010000_add_skill_prompt_id_to_tasks` creates three editable starter Skill Prompts:

- `GEO Skill - Comparison`
- `GEO Skill - Buying Guide`
- `GEO Skill - Application`

Users can edit or delete them. New tasks default to smart recommendation on the form, but users can explicitly disable Skill Prompt or manually select a specific Skill.

The source-controlled V2.2 candidate package defines seven canonical article Skill contracts:

- `GEO Skill - Comparison`
- `GEO Skill - Buying Guide`
- `GEO Skill - Application`
- `GEO Skill - Technical`
- `GEO Skill - Troubleshooting`
- `GEO Skill - Case Study`
- `GEO Skill - Definition`

These V2.2 presets remain source candidates until an administrator reviews and applies the controlled synchronization plan. They live in `database/seeders/data/prompt_presets_v2.php`, which is not consumed by `PromptPresetSeeder`. A normal seed or container restart therefore cannot apply V2.2 accidentally.

The V2.2 Master owns shared factual, source-priority, uncertainty, privacy, relationship-evidence, claim, anti-hype, and GEO clarity rules. Each Skill owns only its intent boundary, reasoning sequence, evidence shape, optional modules, and failure checks. Neither layer forces a universal heading sequence, FAQ, table, Key Takeaways, or Conclusion. Runtime continues to own title, keyword, retrieved context, target language, body-only Markdown, no-body-H1, and internal-link instructions.

The candidate package also contains four optional, industry-neutral Styles: Technical Clarity, Buyer Decision, Editorial Flow, and Conversational Expert. A Style may control tone, sentence rhythm, paragraph density, transitions, and vocabulary boundaries, but it may not weaken evidence, privacy, or safety rules or require fixed content modules.

## Stable Intent Routing

Skill Prompts may optionally declare one controlled intent: Comparison, Buying Guide, Application, Technical Explanation, Troubleshooting, Case Study, or Definition / Explainer. Empty intent means manual-only. Prompt names and Prompt bodies are never scanned for routing.

Only one Skill may own each Auto-matched intent through Prompt management. Additional variants should leave intent empty and remain available for manual selection. Clearing a governed system Skill's intent is treated as an administrator change by preset synchronization and can be preserved with `keep-local`.

The title classifier supports Chinese and English rules and uses the concrete title plus keyword. Comparison wins an explicit comparison/buying-language tie. Below the confidence threshold, no Skill is guessed. Case Study and Troubleshooting are still recognized, but Auto deliberately falls back: the current schema cannot verify case publication consent/anonymization or classify troubleshooting guidance as operator-safe versus technician-only. Administrators may still select either Skill manually.

The local database migration does not infer metadata for historical Prompts. Administrators may assign intent from AI config -> Article Prompt Configuration. Existing tasks and Prompt contents are unchanged.

## Generation Rules

- Smart recommendation selected: the task form uses a bounded title-library sample only as a configuration preview. The task stores Auto mode without a fixed Skill ID.
- At runtime, the Worker evaluates the concrete selected title and matches only a Skill Prompt with the same explicit intent metadata. Mixed title libraries can therefore use different Skills across generated articles.
- If confidence is low or the eligible intent has no configured Skill, generation uses Master Prompt plus optional Style Prompt.
- Case Study and Troubleshooting use deterministic runtime gates. The gates distinguish missing evidence from missing governance metadata, then fall back to Master plus optional Style and record the reason. They remain manual-only until those governance fields exist.
- No Skill Prompt selected: generation behaves like the previous Master Prompt-only flow.
- Manual Skill Prompt selected: Worker composes:

```text
=== Master Prompt ===
...

=== Skill Prompt ===
...

=== Writing Style ===
... (only when selected)
```

- Final language instruction is still appended after template rendering.
- Runtime context injection is field-aware: if a custom template renders only some of `title`, `keyword`, and `Knowledge`, the missing fields are appended without duplicating the fields already rendered.
- Reserved legacy placeholders `language`, `audience`, and `SkillPrompt` are removed before generation because runtime does not provide those template variables. Arbitrary custom extension placeholders remain untouched.
- The final runtime instruction tells the model not to output a body H1 because the article page renders the title separately.
- RAG retrieval, image matching, quality scoring, and distribution remain shared by both generation modes.
- Generation trace records:
  - configured mode and fixed Skill ID in `select_sources`.
  - a `resolve_skill` pipeline step with actual mode, intent, confidence, status, reason, and resolved Skill ID.
  - `has_skill_prompt` in the `compose_prompt` pipeline step.
  - `skill_prompt` in the article generation trace.
  - `skill_routing` in the article generation trace.
  - SHA-256 fingerprints for the normalized Master, Skill, and Style Prompt contents. The trace does not store Prompt bodies or hash RAG/customer context.

The article edit page shows Master, Skill, Style, their short fingerprints, and the per-title routing explanation when available. Historical records without fingerprints or routing metadata remain readable and show compatibility fallbacks.

## Standard and Deep Generation

`standard` keeps the existing single-draft generation path and is the compatibility default. Use it for routine drafts, quick iteration, or when model cost and latency matter most.

`deep` is a quality-first option:

```text
freeze one RAG evidence package
  -> structured plan
  -> complete draft
  -> structured review
  -> at most one targeted revision when needed
  -> final review
```

- A passing article normally uses three model calls. A revision path is capped at five calls.
- Every stage uses the same evidence hash. The plan is not treated as a source of truth.
- Trace data stores stage status, model, attempts, duration, token usage, hashes, scores, and issue codes. Prompt bodies and RAG source text are not stored in the trace.
- Invalid plans, truncated content, unsafe blocking issues, or unparsable reviewer output stop before article persistence.
- A second non-blocking review failure preserves the useful draft as `pending` and prevents automatic publication.
- Case Study and Troubleshooting remain `pending` even after a passing model review because model scoring cannot replace publication/privacy or operational-safety governance.

The article quality panel shows deep-review findings as an auxiliary zero-weight item. Its real 0-100 reviewer score is visible, but it does not inflate the deterministic base score.

## Safe Preset Governance

Phase 3 gives packaged presets a stable `preset_key`, `preset_version`, `last_synced_hash`, `is_system`, and reserved `is_enabled` flag. Display names are no longer treated as the durable identity. Hashes cover Prompt content plus variables, so a harmless rename does not look like an administrator content edit.

`PromptPresetSeeder` still installs the active V1 package once on a provably pristine installation, including PostgreSQL legacy migration fingerprints, then records `active-v1` in `prompt_preset_installations`. This is an intentional safety baseline: a new installation must still preview and explicitly apply V2. The persistent marker prevents later deployment seeds from recreating an administrator-deleted default, even when no task currently references it. If tasks, title-library Prompt references, unknown Prompts, or untrusted Prompt bodies already exist, Seeder performs zero Prompt writes and the upgrade migration marks the installation as already initialized. A newer governed version is never downgraded. Production `AUTO_SEED=true` therefore cannot act as an upgrade path. V2 remains excluded from Seeder.

Use the synchronization command in two steps:

```bash
# Read-only preview. Save the plan_fingerprint and review every conflict.
docker compose exec -T app php artisan geoflow:prompt-presets:sync --json

# Apply only the same reviewed plan. Every conflict needs an explicit decision.
docker compose exec -T app php artisan geoflow:prompt-presets:sync \
  --apply \
  --expect-plan=<reviewed-plan-fingerprint> \
  --preset=article.skill.comparison \
  --resolve=article.skill.comparison:keep-local
```

`--preset` may be repeated to limit both preview and apply to an approved subset. Available per-preset decisions are `keep-local` and `use-preset`; unknown or duplicate resolutions are rejected. Any unresolved conflict blocks the whole transaction. If data changes after preview, the fingerprint changes and apply is refused. A shared synchronization lock prevents concurrent apply processes from creating the same missing key. Before a successful apply, GEOFlow exports all Prompts plus task and title-library Prompt mappings to the private local storage disk under `storage/app/private/prompt-preset-backups/`; these files are ignored by Git.

The V2.2 implementation run used an isolated PostgreSQL database for a read-only preview. That preview applied nothing and found one local Style conflict (`article.style.technical_clarity`). Its plan fingerprint belongs only to the disposable validation database and must never be reused against the business database. Re-run preview on the business database and review every conflict before any `--apply`.

## Guardrails

- Do not repurpose Skill Prompt as Collection, Entity, Tag, or Knowledge Base metadata.
- Do not remove the manual override or "no Skill Prompt" path.
- Do not make Style Prompt or deep generation mandatory.
- Do not let Style override source, privacy, language, safety, or publication-governance rules.
- Treat current smart recommendation as a rule-based fallback, not an authoritative AI intent classifier.
- Do not let used prompts switch type directly; create a new prompt and update task references.
- If adding AI intent matching later, keep manual override visible on the task page.

## Verification Commands

```bash
KEY="$(docker exec geoflow-app sh -lc 'grep "^APP_KEY=" /var/www/html/.env | cut -d= -f2-')"
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Unit/WorkerExecutionServicePromptTest.php --stop-on-failure
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/WorkerAutoSkillResolutionTest.php --stop-on-failure
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Unit/PromptSkillContractTest.php tests/Feature/PromptPresetSeederTest.php --stop-on-failure
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/WorkerDeepGenerationPipelineTest.php tests/Unit/ArticleDeepOutputValidatorTest.php --stop-on-failure
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/AdminAiPromptsPageTest.php tests/Feature/AdminTasksPageTest.php --filter='skill_prompt|auto_skill|style_prompt|create_page|default_content_prompts' --stop-on-failure
```

## Offline Release Evaluation

Run the zero-network Phase 6 fixture evaluation with:

```bash
docker exec geoflow-app php artisan geoflow:article-skills:evaluate --json
```

The fixed catalog contains 15 outputs: two per controlled intent plus one Master-only control. It checks routing, Prompt size, language, body H1, heading density, one-sentence sections, repeated modules, Case privacy/governance, and Troubleshooting safety escalation.

Reports are private and contain hashes rather than Prompt, RAG, customer, or full output content. Offline reports always return `no_go`; they validate the harness, not real-model quality. A real comparison additionally requires ten actual Master-only paired controls, complete PM/content scores, a pinned model/version/configuration, and manual release approval. See `agent-docs/ARTICLE_PROMPT_SKILLS_PHASE6_EVALUATION_REPORT.md`.

The first approved real comparison completed on 2026-07-20 with DeepSeek V4 Pro: 15 routed outputs plus ten paired Master-only controls. Twenty comparison articles remain as draft/pending records under the `Prompt Skill Phase 6 Evaluation` category. The reviewed result is still `no_go` because automatic layout checks and independent PM thresholds failed; these drafts are review material, not publish-ready content.

The 2026-07-21 V2.1 corrective candidate adds closed-world claim control and intent-specific unsupported-detail boundaries. Runtime also rejects strongly truncated or dangling Markdown before article persistence while still counting the provider call. Its exact contents were later recovered and hash-verified as the frozen baseline for the V2.2 paired review; V2.1 now remains a comparison and rollback reference rather than a separately pending default release.

V2.2 local implementation is complete, including Prompt de-templating, optional Styles, standard/deep generation, deterministic deep validation, anti-template evaluation, quality integration, and UI trace inspection. The controlled 2026-07-21 run also completed 24 DeepSeek V4 Pro outputs and a blinded PM review. Style differentiation passed, but factual support, natural-structure, no-Style baseline, and Case/Troubleshooting privacy-safety gates did not all pass. The release decision therefore remains `No-Go`; do not apply the candidate presets or switch the active Docker source until a corrected candidate passes the same fixed matrix. See `agent-docs/ARTICLE_DETEMPLATED_DEEP_GENERATION_IMPLEMENTATION_REPORT.md`.
