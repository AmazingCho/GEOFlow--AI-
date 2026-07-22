# Article Prompt Skills Phase 4 Routing Report

Date: 2026-07-20

## Outcome

Phase 4 introduces stable, explicit intent metadata for Skill Prompts and expands deterministic title classification to seven controlled intents. It deliberately does not introduce persisted Auto mode or per-title Worker routing.

## Product decisions applied

- A Skill Prompt may have one optional controlled `intent_key`; an empty value means manual-only.
- One intent may be assigned to only one Auto-matched Skill from the admin UI. Alternative Skills remain manual-only.
- Auto never scans a Prompt name or body to guess its intent.
- Low-confidence titles use Master Prompt plus optional Style Prompt without a guessed Skill.
- A recognized intent without a configured Skill also uses Master plus optional Style and reports the missing configuration.
- Case Study and Troubleshooting may be recognized, but Auto does not select them until their evidence/privacy and safety gates exist.
- Existing tasks with concrete Skill IDs remain unchanged and are treated as manual selections.

## Canonical intents

- Comparison
- Buying Guide
- Application
- Technical Explanation
- Troubleshooting
- Case Study
- Definition / Explainer

## User-facing behavior

- Prompt management shows the controlled intent selector only for `type=skill`.
- Task creation keeps Smart Recommendation, No Skill, and Manual Selection.
- Current Smart Recommendation is a title-library-level, one-time decision at task save. The resolved Skill is stored as a fixed selection; it is not per-title routing.
- The recommendation panel explains the detected intent, confidence, and title evidence.
- Manual-only and unconfigured outcomes are shown honestly instead of pretending that a Skill will be applied.
- Task help links directly to Prompt management for intent configuration.

## Data safety

- Migration `2026_07_20_020000_add_intent_key_to_prompts.php` adds a nullable indexed field.
- No historical intent was inferred.
- The local database had 18 Prompts and 0 assigned intents immediately after migration.
- V2 Prompt bodies were not applied, and task foreign keys were not rewritten.
- Preset preview treats a local intent change as a conflict. `keep-local` preserves a cleared manual-only intent, and Seeder will not silently restore intent metadata on a governed Prompt.

## Verification

- Phase 4 focused regression: 112 tests / 1143 assertions passed before final risk review.
- Post-review governance and UI regression: 81 tests / 670 assertions passed.
- Final intent-conflict, unique-owner, edit-path, API normalization, and Chinese boundary checks: 40 tests / 237 assertions passed.
- Full Laravel suite: 525 passed / 4089 assertions; two previously recorded unrelated copy assertions remain (`AdminWelcomeIntroCopyTest`, `AdminMaterialsPagesTest`).
- Browser review passed for Prompt management, Skill intent modal, task recommendation explanation, and mobile horizontal overflow. No Phase 4 console errors were found; only the existing Tailwind CDN warning remains.

## Deferred to Phase 5

- Persisting `none|manual|auto` as a task selection mode.
- Resolving a Skill independently for every generated title.
- Recording per-title routing evidence in generation trace.
- Enabling Case Study or Troubleshooting Auto after deterministic gates are available.
