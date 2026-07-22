# Article Prompt Skills Phase 5 Per-Title Auto Report

Date: 2026-07-20

## Outcome

Phase 5 is complete. GEOFlow now persists `none|manual|auto` as an explicit task choice and resolves Auto independently after the Worker selects each concrete title. Existing tasks are preserved conservatively and no historical Auto state is inferred.

## Runtime behavior

- `none`: Master plus optional Style only; the Worker never classifies the title.
- `manual`: the configured `skill_prompt_id` is fixed for every title in the task.
- `auto`: the Worker classifies the selected title plus its keyword, matches only an explicit `intent_key`, and records the actual result.
- Low confidence or missing intent configuration falls back to Master plus optional Style.
- Case Study checks for selected structured evidence, but still falls back because the current schema cannot prove publication consent or anonymization review. The trace distinguishes missing evidence from missing governance approval.
- Troubleshooting checks for retrieved knowledge context and `need_review=1`, but still falls back because the current schema cannot distinguish operator-safe guidance from technician-only or hazardous procedures.
- Manual selection remains available for both restricted intents, preserving the existing administrator-controlled workflow.
- Auto routing adds a `resolve_skill` pipeline step and `skill_routing` trace with mode, intent, confidence, status, reason, and resolved Skill ID. Prompt bodies and retrieved customer content are not copied into the routing trace.

## Compatibility and data safety

- Migration `2026_07_20_030000_add_skill_selection_mode_to_tasks.php` adds the indexed task mode.
- Historical tasks with a concrete Skill become `manual`; tasks without one become `none`.
- The local database had 12 tasks before migration: 8 were backfilled to Manual and 4 to None. Historical Auto remained 0, with no Manual-without-Skill or None-with-Skill mismatches.
- New Auto tasks store `skill_prompt_id=null`, so a title-library preview is never frozen as a manual choice.
- Edit forms restore Auto correctly. Existing API clients that send a concrete `skill_prompt_id` continue to imply Manual; clients may now send `skill_selection_mode=auto` explicitly.
- Prompt preset bodies and existing Prompt records were not modified.

## UI review

- The existing Skill dropdown remains the only control; no extra mode selector was added.
- Smart Recommendation is still shown as a title-library configuration preview, but the help text now states that runtime routing evaluates each title separately.
- The advanced generation section remains collapsible and its existing three-column desktop layout is unchanged.
- Browser inspection confirmed readable wrapping, clear borders, and no overlap in the expanded Skill / Style / model controls.
- Article generation sources now include a compact routing explanation with mode, detected intent, confidence, and fallback reason.

## Verification

- Focused Worker, routing, task-page, API, Prompt composition, and Generation Source regression passed.
- Full Laravel suite: 538 tests / 4161 assertions passed. The only two failures are the same pre-existing stale copy assertions in `AdminWelcomeIntroCopyTest` and `AdminMaterialsPagesTest`; no Phase 5 functional regression was found.
- PHP lint passed for the Worker, task lifecycle, task controller, and migration.
- Local PostgreSQL migration and historical backfill audit passed.
- Browser DOM and screenshot review passed on `http://localhost:18080/admin/tasks/create` and the article edit Generation Source panel. The routing panel had no overlap, raw translation key, or console error at 1440px desktop width.

## Deferred to Phase 6

- Fixed offline output evaluation set.
- Automated content-quality and routing evaluation across at least 15 pinned-model outputs.
- PM/content scoring against Master-only controls.
- Final release regression and deployment decision.
