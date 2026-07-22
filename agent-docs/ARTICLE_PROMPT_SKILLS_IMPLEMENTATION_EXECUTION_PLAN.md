# Article Prompt Skills Implementation Execution Plan

> **For agentic workers:** use independent read-only investigators first. The main agent alone edits the shared workspace, integrates results, runs tests, and performs UI verification.

**Goal:** Safely optimize the Master Prompt and all seven article Skill Prompts while preserving manual selection, no-Skill behavior, Style Prompt behavior, language enforcement, historical tasks, and existing articles.

**Architecture:** Runtime owns context injection, language, output format, and prompt trace. Master owns global factual/RAG/privacy rules. Each Skill owns only article-type reasoning. Packaged presets are installation defaults and must never silently overwrite administrator-edited prompts.

**Tech stack:** Laravel, Blade, PostgreSQL/SQLite tests, Docker, PHPUnit, Artisan commands.

## Global constraints

- Do not run `PromptPresetSeeder` against the live local database before dry-run, backup, and conflict protection exist.
- Do not overwrite the existing database Skill records in Phase 1 or Phase 2.
- Do not regenerate or rewrite historical articles.
- Existing concrete `skill_prompt_id` values are treated as manual selections; never infer that a historical task used Auto.
- Keep `Master -> Skill -> Style -> runtime constraints` ordering.
- Runtime target language has final authority.
- Keep manual Skill, no Skill, and optional Style paths.
- Keep the external Codex Layout Skill optional and outside the GEOFlow generation chain.
- Do not touch the existing uncommitted article-quality files while implementing this plan.
- Real AI evaluation is manually triggered and must not run in default CI.

---

## Parallel investigation summary

Four independent read-only reviews were completed before this plan was finalized:

1. Requirement review: confirmed business boundaries, UI impact, and acceptance criteria.
2. Code review: mapped task creation, Auto resolution, Worker composition, trace, presets, and UI.
3. Test review: identified exact missing tests and Docker commands.
4. Risk review: identified silent Seeder overwrite, missing Auto-mode persistence, Case privacy, and Troubleshooting safety as release blockers.

### Corrected findings

- The original audit was directionally complete but not construction-ready.
- Auto is currently resolved to a concrete Skill ID when a task is saved. It is not a persisted mode.
- Prompt presets are installation defaults, not an unconditional database source of truth.
- Phase 0 protection must precede any preset synchronization.
- Phase 2 writes V2 prompt text to version-controlled presets only; database application happens later.
- Case and Troubleshooting must not participate in Auto until deterministic privacy and safety gates exist.
- Hashes may cover normalized Master/Skill/Style components only. Do not hash or expose RAG context, customer data, title, or the complete final request.

## User decisions confirmed for Phase 4

The following conservative defaults were accepted for Phase 4. Phase 5 remains a separate workflow decision:

1. **Custom Skill Auto eligibility**
   - Recommended: optional `intent_key`; empty means manual-only.
2. **Low-confidence Auto behavior**
   - Recommended: use Master + optional Style without guessing a Skill.
3. **Case Skill without Case evidence**
   - Recommended: Auto falls back to Application or Master-only; manual Case selection blocks that article and reports missing evidence.
4. **Historical task behavior**
   - Confirmed: all existing concrete Skill IDs remain manual. Phase 4 keeps the existing one-time task-save recommendation and does not claim that historical tasks used Auto.

---

## Phase 0 - Establish a safe baseline

### Task 0.1: Capture code and data rollback points

**Files:** no production code change.

- [x] Record `git status --short --branch` and preserve the current unrelated diff.
- [x] Export the `prompts` table with IDs and timestamps.
- [x] Export task mappings for `prompt_id`, `skill_prompt_id`, and `style_prompt_id`.
- [x] Record counts for prompts, tasks using each Skill, task runs, and articles.
- [x] Confirm no generation task is running before any future database apply operation.
- [x] Store backup commands and restore commands in the implementation log; do not commit private database contents.

**Acceptance:** rollback can restore prompt records and task foreign-key mappings without changing article content.

### Task 0.2: Lock the first delivery boundary

- [x] Phase 1 may change runtime constraints, trace hashes, tests, and trace UI.
- [x] Phase 2 may add reviewed V2 prompt text to source control.
- [x] Phase 3 may add safe preview/export/apply tooling.
- [x] No live database Prompt content changes until preview output is reviewed.
- [x] No Auto behavior changes in the first delivery.

**Acceptance:** every code change can ship without changing which Skill an existing task uses.

---

## Phase 1 - Runtime correctness and traceability

### Task 1.1: Add failing prompt-composition tests

**Modify:** `tests/Unit/WorkerExecutionServicePromptTest.php`

- [x] Add `test_prompt_composition_order_is_master_skill_style_runtime`.
- [x] Assert section positions are strictly ordered and each marker appears once.
- [x] Add `test_reserved_builtin_placeholders_do_not_survive_final_prompt`.
- [x] Treat `language`, `audience`, and `SkillPrompt` as reserved built-in placeholders.
- [x] Preserve the current unknown-extension-placeholder test for backward compatibility.
- [x] Add `test_final_instruction_forbids_body_h1_in_chinese_and_english`.
- [x] Add `test_runtime_target_language_instruction_has_final_authority`.
- [x] Add a regression test proving knowledge context is not dropped when a prompt contains `{{title}}` but omits `{{Knowledge}}`.

**Run:**

```bash
KEY="$(docker exec geoflow-app sh -lc 'grep "^APP_KEY=" /var/www/html/.env | cut -d= -f2-')"
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Unit/WorkerExecutionServicePromptTest.php --stop-on-failure
```

**Expected before implementation:** new tests fail for reserved placeholders, H1 instruction, or missing context behavior.

### Task 1.2: Fix runtime prompt boundaries

**Modify:** `app/Services/GeoFlow/WorkerExecutionService.php`

- [x] Keep Master/Skill/Style marker order unchanged.
- [x] Ensure required task context is injected field-by-field rather than disabling all automatic context when any known variable is present.
- [x] Remove or neutralize reserved unsupported built-in blocks without deleting arbitrary custom extension placeholders.
- [x] Add a final Chinese/English instruction that the page template renders the title and the body must not output H1.
- [x] Keep runtime target-language instruction last and authoritative.
- [x] Do not change RAG retrieval, image insertion, or model selection.

**Acceptance:** a prompt containing only `{{title}}` still receives keyword and knowledge context; a fully explicit prompt is not duplicated.

### Task 1.3: Add deterministic component hashes to generation trace

**Modify:**

- `app/Services/GeoFlow/WorkerExecutionService.php`
- `tests/Feature/WorkerGenerationPipelineTraceTest.php`

**Hash contract:**

- `master_sha256`: normalized Master content or null.
- `skill_sha256`: normalized Skill content or null.
- `style_sha256`: normalized Style content or null.
- Do not hash title, keyword, RAG context, customer data, or full final request.
- Do not store full Prompt content in trace.

- [x] First repair any obsolete test helper signature in `WorkerGenerationPipelineTraceTest.php`.
- [x] Add `test_generation_trace_contains_deterministic_prompt_hashes`.
- [x] Add `test_changing_one_prompt_changes_only_its_corresponding_hash`.
- [x] Add `test_generation_trace_does_not_store_full_prompt_or_rag_context`.

### Task 1.4: Display trace identity without exposing content

**Modify:**

- `resources/views/admin/articles/form.blade.php`
- relevant `lang/*/admin.php` translation files
- `tests/Feature/AdminArticleGenerationTraceTest.php`

- [x] Show Master, Skill, and Style names when present.
- [x] Show short component hashes in the Generation Source area.
- [x] Show a neutral legacy message when hashes are absent.
- [x] Do not display full prompt text or RAG context.
- [x] Add `test_article_edit_page_shows_prompt_hashes_without_exposing_prompt_content`.

### Task 1.5: Verify Phase 1

- [x] Lint every changed PHP/Blade/translation file in Docker.
- [x] Run focused Prompt and trace tests.
- [x] Run existing task creation tests covering manual Skill, Auto, no Skill, and Style.
- [x] Verify new-trace output through feature tests and open one legacy-trace article in the browser.
- [x] Confirm no UI overflow, raw translation key, or prompt-content leak.
- [x] Confirm existing task selection behavior is unchanged.

**Phase 1 release gate:** all focused tests pass and no database Prompt content was changed.

---

## Phase 2 - Version-controlled V2 Master and seven Skill contracts

### Task 2.1: Add contract tests before prompt text

**Create:** `tests/Unit/PromptSkillContractTest.php`

- [x] `test_exactly_seven_canonical_article_skills_are_packaged`
- [x] `test_all_skills_follow_the_shared_responsibility_contract`
- [x] `test_skills_do_not_contain_body_h1_templates`
- [x] `test_skills_do_not_use_reserved_unsupported_placeholders`
- [x] `test_master_and_skill_combination_stays_within_size_budget`
- [x] `test_case_skill_contains_evidence_and_privacy_boundaries`
- [x] `test_troubleshooting_skill_contains_operator_safety_and_escalation_boundaries`
- [x] `test_each_skill_has_distinct_application_and_exclusion_rules`

Tests must check stable contracts, forbidden patterns, and size ranges. Do not snapshot entire Prompt strings.

### Task 2.2: Draft the V2 Master

**Create:** `database/seeders/data/prompt_presets_v2.php`

The V2 file is a source-only candidate and is not read by `PromptPresetSeeder`. This safety adjustment prevents production `AUTO_SEED` from applying V2 before Phase 3 provides preview/diff/apply governance.

- [x] Keep global factual, RAG, privacy, uncertainty, claim, and anti-hype boundaries.
- [x] Remove hard-coded default-English behavior.
- [x] Remove unsupported `language`, `audience`, and `SkillPrompt` blocks.
- [x] Remove body H1 templates.
- [x] Avoid duplicating runtime output instructions.
- [x] Keep packaged Master industry-neutral; do not package private Master #18 content.

### Task 2.3: Draft seven V2 Skills

**Create:** `database/seeders/data/prompt_presets_v2.php`

Canonical intents:

- `comparison`
- `buying_guide`
- `application`
- `technical`
- `troubleshooting`
- `case_study`
- `definition`

For each Skill:

- [x] State when it applies.
- [x] State when it does not apply.
- [x] Keep only article-type reasoning and evidence requirements.
- [x] Use conditional section choices, not a fixed heading template.
- [x] Remove duplicate global RAG/GEO/style/output boilerplate.
- [x] Remove H1.
- [x] Include the audit document's Skill-specific safeguards.

Target sizes:

- Comparison: 350-550 words.
- Buying Guide: 400-650 words.
- Application: 400-650 words.
- Technical: 400-650 words.
- Troubleshooting: 500-750 words.
- Case Study: 500-750 words.
- Definition: 300-500 words.

### Task 2.4: Evaluate Prompt contracts without applying them

- [x] Run `PromptSkillContractTest`.
- [x] Print preset names, word counts, forbidden-placeholder findings, and combined Master+Skill budgets.
- [x] Review all seven complete Prompt texts manually.
- [x] Do not run Seeder against the live database.
- [x] Do not update live database records.

**Phase 2 release gate:** source-controlled V2 contents are approved and contract tests pass; live behavior remains unchanged.

---

## Phase 3 - Safe preset governance and synchronization tooling

### Task 3.1: Define preset identity and conflict behavior

**Create migration:** add stable preset metadata to `prompts`.

**Implemented safety addition:** `prompt_preset_installations` records completion of the one-time V1 bootstrap so later `db:seed` runs cannot recreate administrator-deleted defaults.

Recommended fields:

- `preset_key` nullable unique.
- `preset_version` nullable string/integer.
- `last_synced_hash` nullable string.
- `is_system` boolean default false.

Before apply design is approved, decide whether legacy language/ranking Masters remain selectable for new tasks, are marked as legacy, or are hidden while preserving historical task references.
- `is_enabled` boolean default true.

**Implemented decision:** legacy Masters remain selectable in Phase 3. `is_enabled` is stored for future governance but is not used to hide any Prompt yet.

**Modify:** `app/Models/Prompt.php` fillable/casts.

Rules:

- Custom prompts may have no `preset_key`.
- A packaged preset is matched by `preset_key`, with legacy-name migration used only once.
- If current content hash differs from `last_synced_hash`, classify as user-modified conflict and do not overwrite by default.
- Existing task foreign keys and Prompt IDs must be preserved.

### Task 3.2: Add a preview/export/apply service

**Create:** a focused Prompt preset synchronization service and Artisan command following existing command conventions.

Required command behavior:

- Default mode is dry-run.
- Report `create`, `rename`, `update`, `unchanged`, `conflict`, and `skip`.
- Export Prompt and task-mapping backup before apply.
- Apply within a database transaction.
- Require the reviewed plan fingerprint, support repeated `--preset` scope limits, and serialize apply with a shared lock.
- Abort apply when unresolved conflicts exist unless an explicit per-record resolution is supplied.
- Never write private backup data into the Git repository.

### Task 3.3: Replace unsafe unconditional Seeder updates

**Modify:** `database/seeders/PromptPresetSeeder.php`

- [x] Fresh install still receives packaged defaults.
- [x] Existing administrator-edited prompts are not silently overwritten.
- [x] Production `AUTO_SEED=true` performs zero Prompt writes on an existing business database and cannot downgrade V2.
- [x] Repeated execution is idempotent.

### Task 3.4: Add integration tests

**Modify:** `tests/Feature/PromptPresetSeederTest.php`

**Create:** `tests/Feature/PromptPresetSyncCommandTest.php`

- [x] All seven canonical Skills are packaged.
- [x] Seeder is idempotent.
- [x] Existing Prompt IDs and task/title-library foreign keys survive legacy rename.
- [x] Dry-run does not mutate database.
- [x] Apply requires the reviewed `plan_fingerprint` and creates backup before changes.
- [x] User-modified preset becomes conflict and is not overwritten.
- [x] Second preview reports unchanged.

### Task 3.5: Verify fresh-install and upgrade paths separately

- [x] Fresh SQLite test database receives all active presets.
- [x] Fresh isolated PostgreSQL database migrates and synchronizes successfully.
- [x] Existing-database fixture preserves edited Prompt content.
- [x] Legacy names migrate without duplicate records.
- [x] Docker production-init path cannot force overwrite a modified governed Prompt.
- [x] Local database received governance columns and a dry-run only; no V2 candidate was applied.

**Local dry-run result (2026-07-20):** 18 Prompt records remained unchanged and ungoverned. The V2 plan found 10 conflicts and 2 safe updates, so apply is blocked until each conflict is reviewed. No backup was created because dry-run is read-only.

**Phase 3 release gate:** safe synchronization exists; live apply still requires explicit approval after viewing the diff.

---

## Phase 4 - Stable seven-intent routing

The four Phase 4 decisions above are confirmed. Persisted per-title Auto remains deferred to Phase 5.

### Task 4.1: Add intent metadata

**Modify:** Prompt migration/model/controller/prompt UI/catalog API.

- [x] Only Skill prompts expose optional intent selection.
- [x] Empty intent means manual-only.
- [x] System presets use the seven canonical keys.
- [x] Do not infer intent by scanning Prompt name or prose.

### Task 4.2: Expand deterministic rules

**Modify:** `app/Services/GeoFlow/SkillPromptRecommendationService.php`

**Create:** `tests/Unit/SkillPromptRecommendationServiceTest.php`

- [x] Add Chinese/English tests for seven intents.
- [x] Add explicit tie-break rules.
- [x] Return no recommendation below the confidence threshold.
- [x] Keep manual override and no-Skill paths.
- [x] Keep Case/Troubleshooting excluded from automatic use until their gates are implemented.

### Task 4.3: Update UI explanation

- [x] Prompt management shows intent without exposing raw internal keys as primary UI text.
- [x] Task UI explains Auto honestly.
- [x] Recommendation panel shows intent, confidence, and sample evidence.
- [x] No unrelated task-creation modules move.

**Post-review hardening:** one intent can own only one Auto-matched Skill through the admin form; task help states that Phase 4 resolves once per title library at save time; Case/Troubleshooting manual-only behavior is stated in both configuration and task UI; preset sync treats local intent changes as conflicts and supports `keep-local`.

---

## Phase 5 - Persisted per-title Auto mode (separate workflow change)

### Task 5.1: Persist selection mode

Add `skill_selection_mode = none|manual|auto` to tasks.

- [x] Backfill all historical tasks with concrete Skill IDs as `manual`.
- [x] Backfill tasks without Skill IDs as `none`.
- [x] Never infer historical Auto.
- [x] New Auto tasks retain Auto on edit.

### Task 5.2: Resolve Auto per selected title

**Modify:** Task lifecycle, Worker, recommendation service, UI, trace.

- [x] Manual mode uses one fixed Skill for all titles.
- [x] None mode never recommends.
- [x] Auto mode resolves each chosen title separately.
- [x] Low confidence follows the approved fallback.
- [x] Trace records actual resolved Skill, intent, confidence, and reason.
- [x] Case evidence and Troubleshooting safety gates run before automatic use.

### Task 5.3: Add workflow tests

**Create:** `tests/Feature/WorkerAutoSkillResolutionTest.php`

- [x] Mixed libraries can resolve different Skills.
- [x] Manual and none remain unchanged.
- [x] Low-confidence fallback works.
- [x] Case without evidence follows the approved behavior.
- [x] Edit form preserves mode.

---

## Phase 6 - Evaluation and release validation

### Task 6.1: Build a fixed offline evaluation set

- [x] Two titles per Skill: one clear and one boundary case.
- [x] Add at least one Master-only control.
- [x] Minimum 15 offline fixture outputs.
- [x] Pin fixture model and model configuration in the evaluation report.
- [x] Never put paid-model evaluation in default CI.

### Task 6.2: Automatic checks

- [x] Correct routing.
- [x] Prompt size.
- [x] Language consistency.
- [x] No body H1.
- [x] Heading density and single-sentence sections.
- [x] Duplicate Quick Answer/Key Takeaways/Introduction.
- [x] Case evidence state.
- [x] Troubleshooting safety escalation.

### Task 6.3: PM/content review

Score each output for:

- factual support;
- clarity;
- buyer decision value;
- structure naturalness;
- uncertainty and negative-fit handling;
- privacy and safety;
- improvement over Master-only.

**Status:** the structured 0-5 review template and thresholds are implemented. Real-model outputs and independent PM/content scores remain pending explicit model/budget approval. Offline fixture results cannot satisfy this gate.

### Task 6.4: Final regression

```bash
KEY="$(docker exec geoflow-app sh -lc 'grep "^APP_KEY=" /var/www/html/.env | cut -d= -f2-')"
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test
```

- [x] Run focused tests first with `--stop-on-failure`.
- [x] Run full Laravel suite.
- [x] Check task create/edit UI in desktop and mobile widths.
- [x] Check Prompt management and article Generation Source UI.
- [x] Check browser console and raw translation keys.
- [x] Confirm the Phase 6 report/API/UI exposes no Prompt body, RAG source text, full generated output, provider secret, or free-form PM note.

---

## Release checkpoints

| Checkpoint | State |
|---|---|
| R0 | Current code/database baseline |
| R1 | Runtime correctness and trace tests complete |
| R2 | V2 candidate Prompt text complete, not applied to database |
| R3 | Safe sync tooling complete, preview reviewed |
| R4 | Selected V2 prompts applied to trial tasks only |
| R5 | Stable intent metadata complete; existing task-save Auto contract retained |
| R6 | Per-title Auto enabled after explicit approval |

## Current Go/No-Go

- Phase 0 baseline and Phase 1 implementation: **Completed on 2026-07-20**.
- Phase 2 V2 Prompt drafting without database apply: **Completed on 2026-07-20**.
- Phase 3 live database apply: **Completed for the explicitly approved eight-preset evaluation scope after private backup and fingerprint-locked apply**.
- Phase 4 stable intent routing: **Completed on 2026-07-20; focused and UI verification recorded in the Phase 4 report**.
- Phase 5 persisted per-title Auto: **Completed on 2026-07-20 after explicit approval; local migration and UI verification passed**.
- Phase 6 real-model evaluation and release gate: **First 15-output / 10-pair run and independent PM review completed on 2026-07-20; release remains No-Go because automatic and PM thresholds failed**.
- Phase 6.1 targeted correction: **Source implementation and isolated regression completed on 2026-07-21; seven-preset business-database preview is safe, but apply and paid affected-case rerun remain gated**.
- Case/Troubleshooting Auto: **No-Go until the data model can verify publication consent/anonymization and operational safety classification; Manual remains available**.

## Execution record - 2026-07-20

- Private rollback package: `/Users/leo/Desktop/GEOFlow_prompt_phase0_20260720-162310` (not committed).
- Baseline: 18 prompts, 12 tasks, 174 task runs, 52 articles, and no running/pending generation tasks.
- Phase 1 focused Prompt/trace tests passed; task creation and Prompt management regression tests passed.
- Browser check passed on the legacy Generation Source view with no overflow, raw translation key, console error, or Prompt content leak.
- No Prompt database record, Seeder preset, Auto routing rule, RAG retrieval rule, image insertion rule, or model selection behavior was changed.
- Full suite observation: excluding the existing `AdminWelcomeIntroCopyTest` copy assertion, 467 tests passed and one existing materials-hub copy assertion failed because the page no longer renders `admin.materials.author_manage_title`. Both failures are outside the Phase 1 file scope and were not changed to manufacture a green result.
- Phase 2 added `prompt_presets_v2.php` as a source-only candidate containing the neutral V2 Master and seven canonical Skills. At that release gate the active `prompt_presets.php` was restored unchanged after review identified production `AUTO_SEED` as an accidental-apply path. Phase 3 later added governance metadata and trusted legacy hashes without changing its V1 Prompt bodies.
- Phase 2 contract, industry-neutrality, Seeder compatibility, runtime composition, Prompt UI, and task manual/Auto/no-Skill/Style regressions passed. The live database remained at 18 Prompts and 7 Skills with no updated timestamp change.
- Phase 3 remains required before any V2 apply. It must address preview/diff/apply safety, legacy Master visibility, stable preset identity, and runtime evidence gates for Case/Troubleshooting Auto.
- Phase 4 added nullable controlled `intent_key` metadata and deterministic seven-intent classification. Existing Prompt names and bodies are never scanned to infer routing. Low-confidence and unconfigured eligible intents fall back to Master + optional Style; Case Study and Troubleshooting are recognized but remain manual-only. The local database migration added the nullable field only: all 18 existing Prompts still have no intent assignment, and no V2 Prompt was applied.
- Phase 4 focused regression passed with 112 tests / 1143 assertions. Post-review governance and UI fixes passed 81 tests / 670 assertions. The full suite recorded 525 passes / 4089 assertions and only the same two unrelated historical copy failures (`AdminWelcomeIntroCopyTest` and `AdminMaterialsPagesTest`).
- Phase 5 added explicit `none|manual|auto` task persistence and moved Auto resolution into the Worker after each concrete title is selected. Classification uses title plus keyword, and the generation trace records the resolved mode, intent, confidence, status, reason, and Skill ID.
- The local task migration backfilled 8 fixed-Skill tasks to Manual and 4 no-Skill tasks to None, with no invalid mode/Skill combinations and no inferred historical Auto state.
- Independent risk review confirmed that existing Case and Knowledge schemas cannot prove publication consent/anonymization or operator-safe troubleshooting guidance. Auto therefore records a specific governance-block reason and falls back to Master plus optional Style; Manual behavior is unchanged.
- Phase 6 added a fixed 15-case offline catalog, deterministic fixture, automated routing/layout/language/privacy/safety checks, a private hash-only report, and a structured PM scoring contract. The final offline run passed all 15 routing cases with zero automatic failures and correctly remained `no_go`.
- Phase 6 focused tests passed after two independent risk-review passes: 19 tests / 115 assertions. The broader Prompt, Worker, task, and article trace regression set also passed.
- The full Laravel suite completed with 549 passes / 4247 assertions and only the two pre-existing copy failures: `AdminWelcomeIntroCopyTest` expects the old welcome title, and `AdminMaterialsPagesTest` expects the removed author-management entry on the materials page.
- Browser verification covered task create/edit, Prompt management, and article Generation Source/quality surfaces at 1440x1000 and 390x844. No page-level horizontal overflow, raw `admin.*` key, browser console error, or component overlap was found.
- The offline report and approved DeepSeek V4 Pro reviewed report are recorded in the Phase 6 evaluation report with mode `0600`. The successful real run made 25 requests, used 124,073 tokens, and retained article IDs 61-80 as draft/pending comparison material with no publishing or distribution.
- Independent review then hardened external-input validation, exact case-set matching, paired-control integrity, troubleshooting unsafe-instruction detection, reviewer/model metadata redaction, and the applicability boundary for the Master-only improvement score. Imported real-model JSON always retains an `external_input_provenance_unverified` blocker.
- Independent PM review completed for all 15 cases. Two automatic one-sentence-section checks and five PM threshold cases failed; paired improvement averaged 3.2/5, so no broad quality-improvement claim is allowed.
