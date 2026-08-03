# GEOFlow Changelog

This document tracks user-facing updates in the public repository. For future GitHub pushes, update this file together with the Chinese version in `CHANGELOG.md`.

## 2026-08-03

### Actual Package Plans for Packing Lists

- Added a collapsible package-plan workbench to CRM document editing. Multiple line items may share one wooden case, carton, or pallet, and one item quantity may be split across multiple packages.
- Package plans store package number, type, dimensions, net and gross weight, CBM, notes, and allocated item quantities. Quantity conservation is enforced, while cross-document items, duplicate items in one package, and packing-exempt items are rejected.
- Added draft, applied, and invalid lifecycle states. Item quantity, unit, or packing-status changes invalidate an applied plan; deleting an item or marking it packing-exempt removes related allocations and empty packages.
- Packing List preview, PDF, and Excel use only applied package plans. Draft or invalid plans render a blocking page instead of falling back to potentially misleading legacy item-level packing data. The existing item-level mode remains backward compatible and can be restored explicitly.
- Added package labels for all four document languages and included package notes, item names, models, quantities, and units in pagination estimates.
- Increased CRM document item image uploads to 2 MB. Bank details now support `Payment Method` and `Country / Region`, with empty values omitted from printed output.
- Packing-plan and CRM regression coverage passed with 61 tests / 611 assertions. The final boundary patch also passed 18 focused tests / 100 assertions plus desktop, 390px mobile, applied-print, and blocked-draft visual checks.

## 2026-07-22

### Deep Protocol V2.4 structured contract

- Split planning, review, and Markdown writing into dedicated agent responsibilities.
- Replaced duplicated Plan fields with typed answer mode, supported sections, exact evidence mapping, and categorized verification items.
- Aggregate all safe contract violations, allow one bounded repair, and terminate repair exhaustion as non-retryable `protocol_failure` while preserving safe usage audit data.
- Added typed provider and content-blocking failures: provider outages follow the existing queue budget with safe attempt metadata, while deterministic content/evidence/privacy blockers terminate without another paid retry.
- The task list now exposes six controlled outcomes: draft ready, manual review required, insufficient evidence, protocol failure, content blocked, and provider failure. Raw Plan, Prompt, evidence, and provider secrets remain hidden.
- Consolidated schema, evidence-reference, state-consistency, and specific-claim validation into one aggregated Plan rule source.
- Phase 0-5 + P0 qualification keeps the 30/30 offline matrix. The P0 focused suite passed 141 tests / 757 assertions; the full suite passed 873 tests / 5823 assertions with two pre-existing copy-baseline failures. The candidate is frozen as `deep-protocol-v2.4-p0-candidate-20260722` with a reproducibility manifest; no paid model call was made and Phase 6 still requires separate approval.

### V2.3 Grounding Gate and Deep-Protocol Validation

- Added the V2.3.1 sparse-evidence policy: `sufficient` follows the normal Deep path, `limited` may produce a shorter bounded article and always requires human review, while `insufficient` stops after planning and reports missing information instead of padding the article to meet length or section targets.
- The Application candidate now forbids inventing process stages, components, consequences, maintenance actions, or integration details to complete a conventional outline. Deep review no longer treats a complete evidence-limited short article as structurally thin by default.
- Insufficient evidence is a non-retryable business outcome rather than a transient provider failure. The admin task error receives only allowlisted missing-information categories; raw model questions and source content are not persisted.
- The six-call Deep ceiling now counts real provider attempts, including failed failover requests. Limited evidence also has an executable over-expansion gate rather than relying on Prompt compliance alone.
- Human approval is bound to the current title/body revision. Admin/API edits, internal-link application, and distribution-side content edits return changed content to a pending draft; distribution edits do not overwrite the remote article before re-review, and `insufficient` cannot be bypassed by changing review status.
- Prompt synchronization fingerprints now bind source/target versions and reject downgrades. Generation traces retain the Deep protocol and Prompt preset key/version, while the final grounding hash is computed after image insertion.
- Local Docker now mounts the optimized source for runtime verification. Prompt synchronization remained read-only with `applied=false`; no additional paid model call or release-status change occurred. Final related regression: 331 tests / 2357 assertions, with no remaining P0/P1 in independent re-review.
- Removed 54 explicitly tagged evaluation articles and three empty evaluation categories from the business database. Normal content was preserved; verification returned 52 normal articles and zero evaluation articles.
- Invalid Deep plans receive at most one structured repair. Evidence-marker correction no longer sends article prose to an AI model and now uses deterministic local normalization, preventing marker repair from silently rewriting content or adding a paid call.
- Local normalization accepts only fully consumed references whose exact IDs all match the frozen allowlist. Unknown, truncated, case-altered, zero-width, or garbage-suffixed references still fail closed.
- Added safe compatibility for one standard Markdown blank line between a claim and its marker; two or more blank lines still break local association. A non-sensitive normalization count is retained in generation tracing.
- Tightened the candidate Application numeric-evidence boundary, but the bounded DeepSeek V4 Pro recheck remained `No-Go`: the candidate single-turn output was blocked for an unsupported numeric claim, and independent blind review preferred the V2.2 control while rejecting both drafts for publication-level factual support.
- The candidate was not applied to business Prompts, deployed, or published. Recheck outputs stayed in private evaluation files and were not inserted into the article list. Final related regression: 233 tests / 1487 assertions.
- The authorized final Application gate compared one V2.2 control and one V2.3.1 candidate through the Deep workflow. Its runner added a process-wide lock, fail-closed handling for interrupted `in_progress` variants, and frozen Prompt/model/evidence/Deep-protocol hashes. The ceiling was 12 provider attempts; four successful responses were recorded and the model counter moved from 183 to 187.
- Both variants still failed deterministic plan validation after their single repair: the control carried an unmapped fact in `article_angle`, while the candidate's open-question contribution violated the structured protocol. Neither produced an article body, so independent blind PM review returned `No-Go`; no business Prompt, article, task, publication, or distribution record was written.

## 2026-07-21

### V2.2 Real-Model Blind Review and Release Gate

- DeepSeek V4 Pro completed 24 fixed comparisons: six V2.1/V2.2 no-Style pairs plus three titles tested with no Style and three representative Styles. Prompt versions and Style names were hidden during PM review.
- All 24 outputs remain private draft/pending articles (IDs 81-104) with no task, publication time, or distribution. Two of 26 requests were rejected for length truncation and retried; known usage is 115,134 tokens.
- The aggregate Style-recognition gate passed, confirming that optional Styles create perceptible expression differences. The 24 outputs also produced no matching heading skeletons or repeated openings.
- The release decision remains `No-Go`: V2.2 factual support regressed against V2.1 for Case Study and Comparison, some Application/no-Style outputs lacked natural structure, and the Case Study/Troubleshooting privacy-safety gates did not fully pass.
- This evaluation did not apply V2.2 Prompts to the business database, switch the active Docker source, publish, or distribute evaluation articles. The next candidate must harden closed-world factual and high-risk output validation, then rerun the same matrix.

### Article De-Templating V2.2 and Deep Generation

- Upgraded the candidate Master and seven Skills to V2.2. Closed-world evidence, privacy, safety, and wrong-product isolation remain, while fixed heading sequences and mandatory FAQ, tables, Key Takeaways, and Conclusion were removed.
- Added four optional Style candidates: Technical Clarity, Buyer Decision, Editorial Flow, and Conversational Expert. Styles control tone, sentence rhythm, and paragraph density only; they cannot override factual or safety boundaries.
- Added Standard and Deep generation strategies. Existing and new tasks default to Standard. Deep mode freezes one evidence package and runs planning, drafting, and review, with at most one targeted revision and five model calls per article.
- Extracted a shared model-call service so Standard and Deep modes reuse candidate selection, failover, usage accounting, truncation handling, and non-sensitive stage tracing.
- Deep mode does not persist articles after invalid planning, truncation, unsafe output, or blocking review issues. A useful draft with unresolved non-blocking issues is retained as pending. Case Study and Troubleshooting always keep the human-governance gate regardless of model score.
- Extended anti-template evaluation with heading-skeleton, opening-pattern, generic-module density, paragraph-fragmentation, section-information-gain, Style-fitness, and Style-boundary checks. FAQ and Conclusion are no longer universal article requirements.
- Article editing now displays generation mode, Style, deep stages, call counts, duration, token use, review score, and issue codes. Desktop and 390px mobile visual checks passed.
- The implementation phase performed only a read-only Prompt preview in an isolated database. A later run completed the 24 real-model comparisons and PM blind review but did not pass the release gate. V2.2 remains unapplied to the business database, and the active `18080` Docker mount was not switched.
- The full Laravel regression produced 586 passes / 4509 assertions. Two unrelated historical copy-baseline failures remain documented without reverting current product copy.

### Article Skill V2.1 Targeted Quality Corrections

- Added a closed-world evidence rule and claim inventory to the Master Prompt. Product-, application-, and project-specific claims now require direct support instead of plausible industry-detail completion.
- Added first-run failure boundaries to Comparison, Buying Guide, Application, Troubleshooting, Case Study, and Definition. Technical remains `2.0.0` and inherits the stronger Master rule.
- Added a runtime completeness gate. Token-limited responses, unclosed code fences, unfinished sentences, dangling headings, and empty list items are rejected before article persistence, while a complete punctuated final list item remains valid.
- A completed provider call rejected as truncated is still counted. Smart failover may try the next model, while fixed-model tasks return an actionable error instead of saving a broken draft.
- The active Docker database received only a read-only preview for the seven V2.1 presets; every entry was a safe update. No apply or paid-model request was performed before real-model re-evaluation.
- Isolated Docker verification: 39 tests / 487 assertions for the correction, including truncated-primary to complete-fallback switching, and 73 tests / 564 assertions for sync, Seeder, Auto routing, generation trace, and evaluator regression.

## 2026-07-20

### Skill Phase 6 Real-Model Paired Evaluation and Release Gate

- Added a fixed 15-case Skill evaluation catalog with clear and boundary cases for all seven intents plus one Master-only control.
- Added the read-only `geoflow:article-skills:evaluate` command. Its default deterministic fixture has no model-selection or generation option and makes no network or paid-AI request.
- Automatic checks cover routing, Prompt size, language, body H1, heading density, one-sentence sections, repeated modules, Case evidence/privacy, and Troubleshooting safety escalation.
- Evaluation reports are stored privately with `0600` permissions and contain only hashes, counts, and non-sensitive check metadata, not Prompt bodies, RAG context, full outputs, restricted customer terms, provider secrets, or free-form review notes.
- After explicit approval, DeepSeek V4 Pro generated the fixed 15 outputs and ten Master-only pairs in 25 successful requests using 124,073 tokens. Twenty comparison articles remain as draft/pending records with no task, Collection, publication timestamp, or distribution.
- The approved eight-preset V2 Master/Skill scope was synchronized in place to the local Docker business database after a private backup. Prompt IDs and existing task references were preserved; unapproved presets were not changed.
- The real evaluation remains No-Go: all 15 routes passed, two one-sentence-section checks failed, and five cases missed independent PM thresholds. The ten paired improvement scores averaged 3.2/5, which does not support a universal improvement claim.
- Independent risk review further hardened the gate: external outputs must exactly match the fixed catalog; real-model flags, model metadata, and file permissions use strict validation; paired Skill output must match the evaluated output and include shared-context/model-configuration hashes; imported files always retain an unverified-provenance blocker.
- Troubleshooting checks now detect energized or pressurized disassembly and disabled safeguards while allowing explicit prohibitions such as “never bypass the guard.” The relative-to-Master PM score applies only to cases with a validated paired control.

### Per-Title Skill Auto Routing

- Tasks now persist explicit `none / manual / auto` modes. Historical fixed-Skill tasks are backfilled as Manual, tasks without a Skill as None, and historical Auto is never inferred.
- Auto no longer freezes one title-library recommendation at task save. The Worker evaluates each selected title independently, so a mixed title library can resolve different Skills for different articles.
- Low-confidence and unconfigured outcomes still fall back safely to Master plus optional Style. Case Study checks structured evidence but continues to fall back because publication consent and anonymization review cannot be verified; Troubleshooting checks knowledge evidence and human review but continues to fall back because operational safety classification is not available. Administrators may still select either Skill manually.
- Article Generation Sources now include a Skill routing explanation with mode, detected intent, confidence, and apply/fallback reason without storing Prompt bodies or customer knowledge content.
- Generation trace now records the actual routing mode, intent, confidence, status, reason, and resolved Skill ID without storing Prompt bodies or customer/RAG source text.
- Task create/edit keeps the existing Skill dropdown with no extra form burden. The title-library result is presented only as a configuration preview, with runtime behavior explained in the UI.

### Stable Seven-Intent Skill Routing

- Added optional controlled intent metadata for Skill Prompts: Comparison, Buying Guide, Application, Technical, Troubleshooting, Case Study, and Definition. Empty means manual-only, and non-Skill Prompts cannot retain an intent.
- Smart recommendation no longer scans Prompt names or bodies. It matches only explicit intent metadata, with independently tested Chinese/English title rules, a confidence threshold, and deterministic tie-breaking.
- Low-confidence or unconfigured eligible intents safely fall back to Master plus optional Style instead of guessing a Skill. Case Study and Troubleshooting can be detected and explained, but remain manual-only until evidence/privacy and safety gates exist.
- Prompt management and task creation show localized intent, confidence, and title evidence while preserving manual selection and no-Skill paths. No unrelated task sections were moved.
- Each intent can own only one Auto-matched Skill; alternatives remain manual-only. Task help now explains that Phase 4 makes one title-library-level choice at save time and stores it as fixed, and links directly to intent configuration.
- Preset synchronization treats an administrator-edited or cleared `intent_key` as a conflict, supports preserving it with `keep-local`, and Seeder will not silently restore intent metadata on a governed Prompt.
- At that stage the local database received only the nullable field and no historical inference. A later explicitly approved sync applied only the eight V2 evaluation presets while preserving historical task relationships.

### Safe Prompt Preset Governance and Synchronization Preview

- Added stable preset keys, versions, last-synchronized hashes, and system flags. Editable display names are no longer used as preset identity, and custom Prompts may remain ungoverned.
- Removed the risk of production `AUTO_SEED=true` changing or resurrecting administrator Prompts. Seeder performs a one-time V1 initialization only on a provably pristine install and persists completion in `prompt_preset_installations`; a deleted default is not recreated later even without task references. Existing business databases, unknown Prompts, and untrusted bodies receive zero Prompt writes, while newer versions and disabled state are not downgraded or reset.
- Added `geoflow:prompt-presets:sync`. Its default mode is a read-only preview of `create / rename / update / unchanged / conflict / skip`; preview neither mutates the database nor creates backups.
- Apply requires the reviewed `plan_fingerprint`. A changed plan or any unresolved conflict blocks the entire transaction. Repeated `--preset` options can limit scope, conflicts require an explicit `keep-local` or `use-preset` decision, and a shared lock serializes concurrent apply processes.
- Apply updates Prompt records in place, preserving task and title-library foreign keys. Before mutation it exports Prompts and both mapping sets to Git-ignored private storage with restricted file permissions.
- Focused SQLite coverage and an isolated Docker PostgreSQL migrate/sync/second-preview run passed. The initial dry-run changed no Prompt records; a later approved operation backed up, fingerprint-locked, applied, and re-verified only the eight evaluation presets.

### V2 Master Prompt and Seven Article Skill Source Contracts

- Refocused the shared Master Prompt on source priority, evidence states, privacy, relationship evidence, claim discipline, anti-hype rules, and GEO extractability. It no longer hard-codes English, a body H1, unsupported runtime placeholders, or a fixed article template.
- Added the independent `prompt_presets_v2.php` candidate package for Comparison, Buying Guide, Application, Technical, Troubleshooting, Case Study, and Definition. Every Skill declares applies/does-not-apply boundaries, unique reasoning, evidence requirements, optional modules, and failure checks.
- Added Case Study evidence-state, publication-permission, default-anonymization, and sales-assessment safeguards. Troubleshooting now separates safe operator checks from hazardous or technician-only work and defines stop-and-escalate boundaries.
- Added source contract tests for canonical uniqueness, responsibility structure, word budgets, combined Master + Skill budgets, body H1, reserved placeholders, and industry-neutral packaging.
- This phase adds a version-controlled candidate file only. Seeder continues to consume V1 content, so production auto-seeding cannot apply V2. Phase 3 later added stable identity and trusted historical hashes to V1 plus preview/diff/apply governance; existing task Skills and Auto recommendation remain unchanged.

### Article Prompt Runtime and Version Trace Improvements

- Fixed partial custom templates that could omit keyword or RAG reference context. Runtime now appends only missing fields without duplicating explicitly rendered values.
- Removed unsupported reserved legacy placeholders (`language`, `audience`, and `SkillPrompt`) while preserving unknown custom extension placeholders.
- The final language instruction now explicitly prevents a duplicate body H1 because the page template renders the article title.
- New generation traces store SHA-256 content fingerprints for the Master, Skill, and Style Prompts without storing prompt bodies or fingerprinting title, customer, or RAG context.
- The article Generation Sources panel now shows the actual Master, Skill, Style, and short fingerprints. Historical records remain compatible and show a missing-fingerprint note.
- Existing Auto recommendation, manual selection, no-Skill, Style Prompt, and language behavior remain unchanged. This phase does not synchronize or overwrite Prompt content stored in the database.

## 2026-07-14

### Public Prompt Preset Sanitization

- Removed the private Master Prompt containing product- and sector-specific business methodology from the release package without deleting prompts already stored in a local database.
- Reduced packaged defaults to four general content prompts, three general Skill Prompts, and one general keyword prompt. Automatic matching for comparison, buying-guide, and use-case structures remains available.
- Public installs no longer bundle sector-oriented Style Prompts. The Style Prompt feature and admin customization workflow remain available for private local presets.
- Added an industry-term regression scan for packaged prompts to prevent specific brands, models, products, or business context from returning to public defaults.

## 2026-07-13

### Russian and Spanish CRM Document Output

- CRM quotations, proforma invoices, commercial invoices, packing lists, and contracts now support English, Simplified Chinese, Russian, and Spanish output.
- The quote form stores a default output language, while print preview can switch language temporarily without updating the saved default or creating a duplicate document.
- PDF follows the temporary output language. The retained backend Excel export also accepts an explicit language parameter, but no primary frontend Excel workflow is added. Document titles, fixed labels, page numbers, and dates are localized; customer, product, term, amount, unit, seller, and bank values remain exactly as entered.
- All four locale catalogs use the same key contract, including the previously scattered Chinese fixed labels, and reuse the same print templates instead of duplicating language-specific pages.
- PDF rendering now waits for fonts and images before A4 pagination. Longer Russian and Spanish labels reserve layout capacity and final summary content is merged only after real-height measurement, preventing footer overlap and avoidable blank pages.
- HTML preview and PDF now use the same A4 vertical density, and preview controls sit outside the paper content flow. Russian and Spanish summaries and terms are merged back into the previous page whenever the measured content fits safely.
- Continuation pages no longer repeat the large document title; they retain only their section purpose and page count.
- When shipping is blank or zero, print preview and PDF summaries now omit the Shipping Fee / Freight / Shipping row. Positive shipping fees remain visible and continue to be included in the total.
- Automated coverage passes with `49 tests / 515 assertions`. All five document types were audited in Russian and Spanish for browser print boundaries. Quote `#30` was also verified as a one-page A4 preview and PDF in both Spanish and Russian, with summaries, terms, signatures, page labels, footers, and Cyrillic glyphs rendering correctly.

## 2026-07-11

### Admin Login Entry Redirect Fix

- Authenticated admins who revisit the admin login page or admin entry now return to the admin Dashboard instead of being redirected to the public content site.
- The unauthenticated login page and admin-entry redirect behavior remain unchanged.
- Added focused admin guest-redirect coverage and regressed the existing first-install hints and login page flow.

### Unified Frontend SEO Metadata

- The default frontend, all three bundled themes, and newly generated theme-factory drafts now share one title, description, canonical, and Open Graph contract.
- Home, category, and archive pages emit `og:type=website`; article pages emit `og:type=article`. Empty keywords and social images are omitted.
- GEOFlow Agent target-site packages now include canonical and Open Graph metadata on the initial static homepage and dynamic article pages, including article-specific descriptions and optional images.
- Existing JSON-LD, article content, and theme visuals remain unchanged, and the disabled remote site-settings sync was not restored.
- The Docker asset-build gate found no runtime Vite consumer, so no npm install/build step was added to container startup.

## 2026-07-10

### Safer Task Defaults and Progressive Configuration

- New tasks now require manual review and default to GEOFlow-only publishing. The backend uses the same safe defaults when those fields are omitted.
- The task form now includes a Business Context / Generation / Review / Publish Scope summary with live required-field and publishing-risk feedback.
- Skill Prompt, Style Prompt, and model failover settings now live under Advanced Generation Settings. The section is collapsed for new tasks and opens automatically when editing existing advanced values.
- Selecting a Collection now reports the available Entity and Case counts, including clear pending, empty, and cross-Collection states.
- The submit area no longer floats over form content on desktop or mobile.
- Added safe-default and page-structure coverage. `AdminTasksPageTest` passes 21 tests / 160 assertions, with desktop and 390px mobile visual verification.

## 2026-07-01

### Lightweight Writing Style Prompt

- Added optional `Style Prompt` support for article tasks: `Master Prompt` remains responsible for base generation rules, factual discipline, language, and RAG behavior; `Skill Prompt` remains responsible for article structure such as comparison, buying guide, and application formats; `Style Prompt` only controls optional tone, sentence shape, and expression preference.
- The prompt management page now supports a third prompt type, `type=style`, and counts tasks using each style prompt to prevent accidental deletion or type changes.
- The task create/edit form now includes a `Style Prompt` dropdown in content settings. It defaults to "Do not use a Style Prompt".
- Task persistence, edit-state hydration, lifecycle validation, monitoring details, and generation trace now support `style_prompt_id`.
- The worker composes prompts in `Master Prompt -> Skill Prompt -> Writing Style Prompt` order. Language detection uses only the title, keyword, and Master/Skill prompts, so English style templates do not override the target article language.
- Style Prompt remains available as a custom extension point; public installs do not bundle business- or sector-oriented style content.
- Boundary: this phase does not add URL crawling, automatic style recognition, author imitation, or retroactive rewriting of existing articles.

## 2026-06-21

### Selective Upstream Sync and Deployment Stability

- Selectively absorbed upstream compatibility fixes: category deletion checks now include trashed articles, preventing categories from being removed while old article source links still depend on them.
- Admin AJAX / Echo / upload URLs now use safer same-origin relative paths that preserve an `APP_URL` subdirectory, reverse proxy, or changed port.
- Docker Nginx now forwards `X-Forwarded-Proto / Host / Port`, allowing Laravel to resolve the external HTTPS URL correctly behind a reverse proxy.
- Docker entrypoints now support `AUTO_FIX_STORAGE_PERMISSIONS`, enabled by default, to repair `storage` and `bootstrap/cache` permissions and create upload/temp directories; custom mounted environments can disable it.
- `.env.prod.example` now defaults production sessions to `redis` instead of `file`, reducing login/session issues in multi-container or permission-sensitive deployments.
- Task distribution strategy support was integrated only at the backend foundation level: `broadcast / round_robin / random_balanced` fields and selector logic were added, while the task-create UI remains unchanged and defaults to the existing "broadcast to all selected channels" behavior.
- Intentionally skipped upstream changes that would disrupt the customized local workflow: no task-create distribution-strategy UI rewrite, no removal of the China Composer mirror setting, and no removal of local Reverb/Vite habits.
- Verification: category, article, welcome modal, task, distribution page, and distribution-strategy tests passed, covering 101 tests / 834 assertions; the local Docker database has run `2026_06_20_000000_add_distribution_strategy_to_tasks`.

### Packaged Admin Prompt Presets

- Added public-release-reviewed general prompt presets covering content prompts, skill prompts, and the keyword-generation prompt.
- Added `PromptPresetSeeder`; running `db:seed` now syncs packaged prompt presets by `type + name` without deleting user-created prompts.
- Added compatibility aliases for legacy default prompt names so fresh installs can migrate old defaults to the current admin names instead of creating duplicate similar prompts.
- `DatabaseSeeder` now initializes both the default admin account and packaged prompt presets, covering Docker init, manual installs, and future release packages.
- Packaged presets remain independent from user-created prompts. The seeder syncs only public defaults and does not delete other prompts already stored in the admin.

## 2026-06-19

### Codex Business Intake API Phase 2-6

- Added the AI intake draft data layer: `ai_intake_drafts` and `ai_intake_actions` now store reviewable business intake suggestions generated by Codex / AI.
- Added the `assistant:write` API scope plus `POST /api/v1/assistant/intake-drafts/validate`, `POST /api/v1/assistant/intake-drafts`, and `GET /api/v1/assistant/intake-drafts/{id}`.
- Draft creation reuses the existing response envelope, Request-Id propagation, and `X-Idempotency-Key` idempotency handling, so retried requests do not create duplicate drafts.
- Added the admin "AI Intake Drafts" review inbox for super admins, showing original input, AI summary, actions, risk, confidence, governance warnings, apply, and reject actions.
- Applying a draft can create low-risk CRM records: customers, inquiries, activities, tasks, and after-sales ticket drafts.
- Knowledge-base and Case actions create `crm_content_proposals` only; they do not directly overwrite knowledge-base content or directly create Case records.
- Added basic governance warnings for missing Collection, possible duplicate customers, low-confidence drafts, and low-confidence actions; the draft inbox can filter by status, risk, and source.
- Added `scripts/codex-intake.mjs`, a local helper that reads the API token from environment variables and wraps context search, draft creation, and draft show operations.
- Added `功能说明文档/12-Codex业务录入助手API使用说明.md` and updated the agent docs, whitepaper, and implementation status.
- Verification: `AssistantIntakeDraftApiTest` passed with 7 tests / 73 assertions; new PHP and Blade files passed syntax checks; `node --check scripts/codex-intake.mjs` passed inside the app container.

### Codex Business Intake API Phase 0-1

- Completed the API capability audit for the Codex Business Intake Assistant. The existing API already provides Bearer tokens, scopes, a unified response envelope, Request-Id propagation, and idempotent write foundations.
- Added the `assistant:read` API scope for future least-privilege read-only Codex / AI assistant access.
- Added the read-only `GET /api/v1/assistant/context/search` endpoint to search candidate customers, contacts, inquiries, opportunities, documents, orders, after-sales tickets, Entities, knowledge bases, and Cases by keyword plus optional Collection.
- The endpoint only returns context candidates. It does not create, update, or delete any CRM, knowledge-base, or Case data, making it a safe prerequisite for the future AI intake draft inbox.
- Customer search can now match through related inquiries, opportunities, documents, orders, and tickets, covering real inputs such as product models or problem descriptions instead of only customer names.
- Updated `agent-docs/CODEX_BUSINESS_INTAKE_API_WHITEPAPER.md` with the Phase 0 / Phase 1 implementation state and clarified that Phase 2 is where draft creation should begin.
- Verification: new `AssistantContextApiTest` passed with 4 tests / 24 assertions; `ApiV1ContractTest` passed with 13 tests / 84 assertions. The task-delete API contract assertion was updated to soft-delete semantics to match the existing task trash behavior.

## 2026-06-17

### Mainline Phase 1: Task Trash

- Changed task deletion from "delete task and clear article source links" to "move task to trash".
- Generated articles are preserved, `articles.task_id` remains intact, and article list/edit pages now show a "Deleted Task" source state.
- Added a task trash page where deleted tasks can be reviewed and restored; restored tasks stay paused to avoid accidental generation.
- Deleting a task cancels pending runs and clears temporary scheduling data without destroying article assets or generation tracing.
- Verification: `AdminTasksPageTest` passed with 18 tests / 145 assertions; `AdminArticlesPageTest` passed with 15 tests / 88 assertions; the task soft-delete migration was applied in local Docker.

### Mainline Phase 2: Performance Surface Hardening

- Confirmed the existing tag selector already uses remote search, so the task create page does not render every material tag on initial load.
- Confirmed the material tag reference modal lazy-loads reference details on demand; the list page only loads reference counts.
- Added `pagination.has_more` to the material tag remote search API so the frontend can later show a "more results available" affordance.
- Standardized the material tag stats cache key as `admin:material-tags:stats:v1` and invalidates it when tags are created, deleted, renamed, bulk-moved, or synced to materials.
- Confirmed the knowledge-base vectorization queue already tracks queued / running / completed / failed states with failure writeback and duplicate dispatch protection; this phase did not rewrite the queue flow.
- Added `AdminPerformanceSurfaceTest` to cover task-create tag rendering limits and capped remote search results with `has_more`.
- Verification: `AdminPerformanceSurfaceTest` passed with 2 tests / 8 assertions; material-tag focused regression passed with 4 tests / 21 assertions; `AdminTasksPageTest` passed with 18 tests / 145 assertions.

### Mainline Phase 3: Knowledge Governance Proposal Workflow

- Added "Create Proposal" / "Create Review Proposal" actions to the knowledge governance check page, turning duplicate groups and conflict pairs into traceable proposals.
- Added the `knowledge_governance_proposals` table for proposal type, status, related knowledge bases, detection snapshot, before snapshot, admin notes, and reviewer metadata.
- Added a governance proposal detail page showing issue type, related sources, detection snapshot, proposed action, review actions, and rollback entry.
- Duplicate handling defaults to "archive duplicates" only: after a typed confirmation, related duplicate knowledge bases are changed to `inactive`. Sources, chunks, and content are not deleted or rewritten.
- Conflict review proposals only record manual review results and never overwrite knowledge content.
- Applying archive proposals writes admin activity logs; applied archive proposals can be rolled back to restore previous source status.
- Automatic knowledge-content merge remains disabled to protect source material and embeddings.
- Verification: new `AdminKnowledgeGovernanceTest` passed with 4 tests / 34 assertions; existing knowledge-governance focused regression passed with 4 tests / 35 assertions; the proposal table migration was applied in local Docker.

### Mainline Phase 4: Collection Health Dashboard

- Added a health-score badge and "Health" entry to the Collection list.
- Added a Collection health detail page with read-only checks for Entity, Knowledge Base, Title Library, Image Library, Case, Knowledge-to-Entity links, Case-to-Entity links, chunk vectorization, controlled tag groups, and duplicate tags.
- The health score is a pre-generation readiness audit only. It does not automatically edit, merge, delete, or rebuild any materials.
- The detail page shows pass/fail status, counts, and score penalties for each check so admins can decide whether to add materials or perform governance cleanup.
- Verification: `AdminCollectionsPageTest` passed with 6 tests / 90 assertions.

### Agent Documentation Cleanup and Old Plan Pruning

- Removed historical plan files whose implemented work is already covered by user docs, implementation status, known issues, and changelogs, reducing the chance that future agents follow obsolete plans.
- CRM handoff now points to `功能说明文档/10-轻量CRM与报价使用说明.md`, `IMPLEMENTATION_STATUS.md`, and `KNOWN_ISSUES.md` instead of old whitepapers or prompt drafts.
- Updated the Entity and Case usage guide with Entity-to-Entity relationship boundaries, clarifying that entity relations support indexing and recommendations but do not replace knowledge-base source content or Case evidence.
- Kept the active `MAINLINE_REMAINING_OPTIMIZATION_PLAN.md` and the CRM PDF real-sample visual regression record.

### CRM After-Sales Knowledge Reuse Enhancement

- Added a "Knowledge Correction Candidate" card to the after-sales ticket detail page, allowing admins to start a correction proposal from knowledge bases already linked to the ticket.
- FAQ and Case drafts still use the CRM content proposal flow; they are written to the knowledge base or Case DB only after admin confirmation.
- Knowledge corrections continue to reuse the existing Knowledge Correction Assistant. AI creates pending proposals only and never overwrites knowledge chunks directly.
- Tickets without linked knowledge bases show a guardrail message, asking admins to link the target knowledge base first to avoid submitting corrections to the wrong source.
- Verification: focused tests passed for the ticket detail entry, pending correction creation, FAQ proposal apply, and Case proposal apply, with 2 tests / 35 assertions.

## 2026-06-16

### CRM Lightweight Enhancement Batch 1 (Customer Overview and Activity Types)

- Upgraded the customer detail page into a CRM overview with summary metrics for inquiries, active opportunities, document amount, orders, open tickets, and open tasks.
- Reorganized the customer detail information architecture into clear sections for basic information, sales chain, customer contacts, activity timeline, and customer tasks.
- The sales-chain area now shows related inquiries, opportunities, documents, orders, and after-sales tickets from one customer page.
- Completed eager loading for customer detail relations, including opportunities, documents, orders, tickets, activities, and tasks.
- Added a structured `activity_type` field for CRM activities, covering note, call, email, meeting, chat, document, task completed, and system record.
- Customer, inquiry, and opportunity activity forms now reuse the same activity-type selector. The existing `followup_type` field remains as optional free-text notes for backward compatibility.
- Completing a CRM task with a result now writes a `task_completed` activity back to the timeline, preserving the boundary between historical activities and future tasks.
- Verification: full `AdminCrmPagesTest` passed with 32 tests / 292 assertions; the activity-type migration has been applied in the local Docker database.

### CRM Lightweight Enhancement Stage 3: Opportunity Kanban

- Added the `/admin/crm/opportunities/kanban` opportunity Kanban entry and a new "Opportunity Kanban" tab in the CRM navigation.
- The Kanban groups opportunity cards by the seven existing stages: qualified, discovery, solution, proposal, negotiation, won, and lost.
- Cards show customer, amount, probability, expected close date, source inquiry, and the current next task, with each card linking back to the existing opportunity edit page.
- The Kanban supports Collection filtering and top summary metrics for total opportunities, active opportunities, total amount, and open tasks.
- This stage is read-only / light interaction only. Drag-and-drop stage editing was intentionally not added, keeping the existing opportunity save workflow unchanged.
- Verification: full `AdminCrmPagesTest` passed with 33 tests / 301 assertions; browser checks passed on desktop and mobile widths with no horizontal overflow or console errors.

### CRM Lightweight Enhancement Stage 4: Document Chain View

- Added a reusable CRM document-chain component to inquiry details, opportunity edit, and customer details, showing customer, inquiry, opportunity, document, order, and after-sales ticket links in one flow.
- Inquiry details now show a quick path from the current inquiry to related opportunities, documents, orders, and after-sales tickets.
- Opportunity edit now includes a right-side document-chain summary while keeping the existing source inquiry, activities, tasks, and related document entries.
- Customer details now include a "Customer Document Chain" summary before the detailed sales-chain lists.
- Controllers now eager load nested documents, orders, and tickets. The Blade partial only reads objects passed by the page and does not query the database directly.
- This stage does not restore the "Create Copy" entry and does not add document package/version management. The existing print-type switch remains protected.
- Verification: full `AdminCrmPagesTest` passed with 34 tests / 323 assertions; browser checks across inquiry, opportunity, and customer pages passed on desktop and mobile widths with no horizontal overflow or console errors. The in-app screenshot API timed out repeatedly, so DOM, viewport, and console checks were used as the verification evidence.

### Knowledge Base Duplicate and Conflict Detection (Stage 5)

- Added a knowledge governance check page from the knowledge base list. It can scan recently updated knowledge bases or a selected Collection.
- The check reports exact content duplicates, same source URLs, same titles, similar title/content pairs, and numeric fact conflicts between sources that share a source URL, Entity, or similar Collection-scoped title.
- Tightened conflict scope: specification differences such as power, weight, and voltage are no longer treated as conflicts when the sources are linked to different product models, product lines, or product/service subjects. Shared brand/company entities are now treated as context only, not as a standalone conflict trigger.
- The governance page shows scan statistics, duplicate groups, conflict pairs, confidence, source metadata, linked Entities, and detail links.
- This stage is report-only. It does not delete, merge, overwrite, re-chunk, re-embed, or change the existing RAG workflow.
- Verification: added the governance service, route, page, translations, and focused tests covering duplicate/conflict detection plus Collection filtering.

### Knowledge Base Vectorization Async Queue Enhancement (Stage 4)

- Added real status tracking for knowledge chunking and vectorization jobs: no chunks, existing chunks, queued, running, completed, and failed.
- Added chunk-sync status fields on knowledge bases. The background queue job now records start, completion, failure timestamps, and error messages.
- Knowledge base list and detail pages now show the chunk job status. Duplicate refresh is disabled while a job is queued or running, and failed jobs expose a retry action.
- Added a chunk status endpoint. The list and detail pages lightly poll queued / running rows and update badges, vectorization summaries, and messages when the job finishes or fails.
- Manual "Update Chunks" now guards against duplicate dispatches, avoiding repeated queue jobs for large files.
- Existing import, chunking, embedding, and RAG retrieval behavior remains unchanged; this stage improves observability, failure visibility, and retry UX.
- Verification: focused tests passed for queue status, job completion/failure status, missing embedding model protection, knowledge list UI, and the previous correction assistant regression; browser checks found no console errors on knowledge list and detail pages.

### AI Knowledge Correction Assistant (Stage 3)

- Added a knowledge-correction workflow from both knowledge base details and article edit pages.
- AI only creates a correction proposal; it never overwrites knowledge content automatically. Administrators can approve, reject, apply, or roll back corrections.
- Added correction records and knowledge chunk version records with article, knowledge base, and chunk links.
- Applying a correction updates the target `knowledge_chunks.content` plus the matching source segment in the knowledge base body, then refreshes that chunk's embedding / fallback vector.
- Article editors can select an incorrect passage and start a correction from the selected text, making it easier to trace generated errors back to source knowledge.
- Added the `/admin/knowledge-corrections` admin list with status, knowledge base, and article filters.
- Guardrails: if no AI model is available or the AI response is invalid, the system creates a safe pending proposal; if the source chunk changed before apply, the update is blocked instead of overwriting newer content.
- Verification: the new `AdminKnowledgeCorrectionTest` passed; browser checks confirmed the article edit page, knowledge base detail page, and correction list render without console errors.

### Skill Prompt Smart Recommendation (Stage 2)

- The task create page Skill Prompt control now supports three paths: smart recommendation, no Skill Prompt, or manual selection.
- Smart recommendation uses the title library name and title samples to infer intent, such as comparison, buying guide, or application, then maps it to a matching Skill Prompt.
- The page shows the recommended Skill, confidence, matched samples, and reason; users can still override it manually or explicitly disable Skill Prompt.
- The backend runs the same recommendation on submit so the saved task matches the visible suggestion. Direct form/API submissions remain compatible with integer `skill_prompt_id` values.
- Verification: focused tests passed for opening the task page, manual Skill Prompt selection, automatic Skill recommendation, and explicitly disabling Skill Prompt.

### GEO Generation QA and RAG Explanation Enhancement (Stage 1)

- Refined article quality scoring: each review item now returns readable metrics and deduction reasons, including language detection, knowledge chunk count, knowledge base count, average evidence score, keyword coverage, factual sources, structure completeness, FAQ, image match, and repetition checks.
- The article edit page now displays metrics and suggestions directly under each quality item, so reviewers can understand low scores without reading only the total grade.
- The "Generation Source" knowledge retrieval area now works as a RAG explanation card, showing retrieval strategy, evidence chunk count, used knowledge bases, retrieval sources, selected Entity / Case IDs, chunk type, role, importance, evidence score, and match reasons.
- Fixed the missing legacy `entity()` relation on `CaseRecord`, which was exposed by RAG case tag filtering tests and keeps historical `entity_id` data compatible.
- Verification: focused quality scoring, article generation trace, and RAG retrieval tests passed; browser inspection confirmed the article edit quality and RAG explanation cards render correctly with no console errors.

### CRM Document PDF Generation and Print Pagination

- Replaced the old PDF path with Chromium/Puppeteer generation that reuses the existing HTML print templates for A4 PDFs instead of relying on the missing `Spatie\LaravelPdf\Facades\Pdf` stack.
- Added `CrmDocumentPdfService` and `scripts/render-crm-document-pdf.mjs` for temporary HTML rendering, Chromium PDF output, failure fallback, and temp file cleanup.
- Added "Download PDF" entries to document details and print preview pages. Failures redirect back to print preview with a clear manual-save fallback message.
- Hardened the print template with A4 `@page`, repeated table header/footer behavior, and page-break protection for key blocks such as tables, signatures, bank details, and summaries.
- Added dynamic item pagination to the print template: item rows are split by document type, image rows, long descriptions, and packing fields, with first, continuation, and final item pages rendered separately.
- PI page labels are no longer hardcoded as `Page 1 of 2`; long PI documents now expand into multiple item pages plus the final bank-account page with dynamic `Page X of Y` labels.
- Updated user docs, agent handoff notes, and known issues so the Chromium/Puppeteer PDF path is treated as the active route while HTML print preview remains the failure fallback.
- Added the `fonts-wqy-zenhei` CJK font package to Docker images and updated the print font stack with `WenQuanYi Zen Hei`, fixing Chinese note text that rendered as square boxes in real invoice PDFs.
- Completed visual regression checks for real Quotation, Proforma Invoice, Commercial Invoice, Packing List, and Contract PDF samples.
- Added the `crm:document-pdf-regression` Artisan command to generate five real-sample PDFs, HTML files, page screenshots, and a regression report while checking PDF page counts against rendered HTML pages.
- Refined quotation / PI item pagination so image-row capacity is used only when item rows actually render images, fixing compact no-image quotations that were split onto an extra page too early.
- Added a "PDF Regression Check" admin entry to CRM document production, with one-click regression package generation, historical runs, report viewing, and safe artifact links.
- Added PDF regression run records and a default visual baseline record. Reports now persist the render context, including `print` media, A4, Chromium, and viewport metadata, so PDF pagination is not judged by the larger screen-preview layout.
- Added visual screenshot diffing against the default baseline, with per-page diff output to catch broken document templates before users notice.
- Added the `crm:document-pdf-regression:prune` cleanup command and a daily scheduled task. Dry-run previews candidates first; real pruning is limited to `storage/app/pdf-regression` and protects baselines, running jobs, and recent runs.
- HTML print preview remains the stable fallback.

### CRM Document Print Flow Simplification

- Removed the frontend "Create Copy" entry from quote/document details to prevent inquiry or opportunity records from accumulating duplicate PI, CI, packing list, and contract copies in the document list.
- Kept the "Print Document..." type switch as the default output path, allowing the same document to be printed as a quotation, proforma invoice, commercial invoice, packing list, or contract without creating a new database record.
- The backend `source_quote_id` field and conversion endpoint remain for historical compatibility. If independent document versions are needed later, they should first be designed as a document package / version management / derived-document folding workflow.

### Agent Collaboration Flow and Requirement Translation Rules

- Added `agent-docs/USER_REQUIREMENT_INTERPRETATION.md` to define the default collaboration rule: users may describe requests in business language, and the agent must first translate them through a product-manager interpretation pass.
- Added `agent-docs/CODEX_GLOBAL_PERSONAL_PROMPT.md` with a compact prompt that can be copied into Codex custom instructions, plus the invocation note for the local global skill `pm-requirement-translator`.
- Connected the rule to the shortest handoff path, document reading policy, agent workflow rules, and tool configuration docs to reduce misunderstanding after context compaction or agent handoff.
- Clarified that every optimization should first identify the business goal, affected pages, expected behavior, guardrails, and acceptance checks; the agent should ask the user only for high-risk or genuinely ambiguous decisions.

## 2026-06-15

### CRM Sales Pipeline V2: UI, Tests, and Documentation Closure

- Completed CRM Sales Pipeline V2 stages 0-7 with restorable checkpoints.
- Updated CRM usage docs, Agent brief, implementation status, known issues, and the V2 whitepaper for low-token handoff.
- Full regression passed: `CrmPipelineAuditTest`, `AdminCrmPagesTest`, and `AdminTasksPageTest` with 42 tests / 361 assertions.
- Browser checks covered the CRM dashboard, opportunity edit page, and quote/document create page with no Server Error and no horizontal overflow; system screenshots were saved when the in-app screenshot channel timed out.
- The final audit still has 8 ambiguous historical items: 1 orphan opportunity, 5 documents without a unique candidate, and 2 activities without a unique candidate. They remain manual by design.

### CRM Sales Pipeline V2: Historical Link Repair

- Added `--apply` to `crm:pipeline-audit`; the command remains read-only by default.
- `--apply` repairs only unique candidates: tasks, documents, and activities linked to opportunities, plus explicit empty Collection fixes.
- Live data repair linked 1 task, 3 documents, and 3 activities to their opportunity, and fixed 1 document Collection.
- Audit issues dropped from 16 to 8; orphan opportunities, documents without candidates, and activities without candidates remain for manual review.
- Repair operations are written to the administrator activity log, with before/after reports saved.
- Verification: `CrmPipelineAuditTest` 3 tests / 21 assertions; `AdminCrmPagesTest` 23 tests / 211 assertions; all Blade templates compiled.

### CRM Sales Pipeline V2: Document Chain Consistency

- Quote/document saves now validate that customer, inquiry, opportunity, and Collection belong to the same sales chain.
- Selecting an inquiry automatically links its active opportunity when present; selecting an opportunity automatically links its source inquiry.
- Mismatched inquiry/opportunity, customer, or Collection combinations are rejected before saving.
- The document form now explains the consistency rule, and documents created from an opportunity prefill both source inquiry and opportunity.
- Verification: `AdminCrmPagesTest` 23 tests / 211 assertions; `CrmPipelineAuditTest` 2 tests / 12 assertions; all Blade templates compiled.

### CRM Sales Pipeline V2: Unified Activity Timeline

- Added `CrmActivityService` so customers, inquiries, and opportunities share one activity-write path.
- Added an activity timeline and activity entry form to opportunity details.
- Activities can create a next-step task while preserving the customer, inquiry, and opportunity chain.
- Completing a task can now create a linked activity result when a result note is provided.
- Activity rows show linked inquiry, opportunity, and source task while keeping edit and soft-delete behavior.
- Verification: `AdminCrmPagesTest` 22 tests / 202 assertions; `CrmPipelineAuditTest` 2 tests / 12 assertions; all Blade templates compiled.

### CRM Sales Pipeline V2: Unified Inquiry Conversion

- Added a transactional `OpportunityConversionService` shared by inquiry-detail and global source-inquiry creation flows.
- Conversion links existing open tasks and unassigned documents in place without copying records; completed tasks remain historical.
- Inquiry details show the affected task/document counts before conversion and require explicit confirmation.
- Tasks created from either an inquiry or opportunity automatically retain the complete inquiry, opportunity, and customer chain; contradictory combinations are rejected.
- Conversion results are written to the administrator activity log.
- Verification: `AdminCrmPagesTest` 21 tests / 185 assertions; all Blade templates compiled.

### CRM Sales Pipeline V2: Opportunity Lifecycle

- Added explicit "from inquiry" and "direct without inquiry" modes plus a searchable source-inquiry selector.
- Source inquiries synchronize the customer and Collection; cross-customer, cross-Collection, and duplicate active links are rejected.
- Added a database constraint allowing only one active opportunity per source inquiry.
- Added safe opportunity archive, archived filtering, and restore while preserving linked tasks, activities, and documents.
- Archive impact is summarized before confirmation, and restore is blocked when the source is already occupied.
- Verification: `AdminCrmPagesTest` 19 tests / 172 assertions; `CrmPipelineAuditTest` 2 tests / 12 assertions; all Blade templates compiled.

### CRM Sales Pipeline V2: Contracts and Read-only Audit

- Added opportunity and task links to CRM activities; a task can retain multiple historical activities.
- Added the read-only `crm:pipeline-audit` command for orphan or duplicate opportunities, unlinked tasks and documents, relationship mismatches, open tasks on archived opportunities, and activity-link candidates.
- Added JSON output and `--fail-on-issues`; the audit never mutates historical records or guesses ambiguous links.
- The current live audit found 16 relationships requiring later governance, with identical CRM counts before and after the audit.
- Verification: `CrmPipelineAuditTest` 2 tests / 12 assertions; `AdminCrmPagesTest` 16 tests / 146 assertions.

### CRM Activity, Contact, and Opportunity Next-Action Refinement

- Historical activities can now be edited and soft-deleted:
  - Customer and inquiry detail pages expose persistent edit and delete controls.
  - Editing reuses the lightweight Markdown editor; document, order, and ticket timelines remain read-only.
- Fixed the `500` error when creating a customer contact with only a name or blank optional fields:
  - Laravel converts empty strings to `null`, while the contact table keeps non-null string constraints.
  - The controller now normalizes optional strings before persistence and safely synchronizes legacy customer contact fields.
- Clarified CRM responsibilities:
  - Activities store completed communication facts.
  - CRM tasks store future actions, assignees, and due dates.
  - Opportunities store pipeline data and display the first unfinished task as the current next action.
- Removed duplicated manual next-action fields from the active opportunity form while retaining legacy database columns for compatibility.
- Fixed a `500` error when manually creating an opportunity with a blank competitor field; blank amount input now also falls back to `0`.
- Verification: the complete `AdminCrmPagesTest` suite passed with `16 tests / 146 assertions`.

## 2026-06-14

### CRM Activity Editor Refinement

- Kept “activity records” and “CRM tasks” as separate concepts:
  - Activity records store completed communication outcomes.
  - CRM tasks store future follow-up actions.
- Upgraded the activity input into a lightweight Markdown editor with the article editor visual style:
  - Supports write, preview, and source modes.
  - Quick insert actions cover headings, bold, italic, quotes, lists, links, inline code, and dividers.
  - Reuses admin form borders and button styling without loading full Vditor on CRM detail pages.
  - Preview rendering now restricts link protocols so unsafe links are not rendered as direct clickable targets.
- Verification:
  - `_markdown-editor.blade.php` syntax check passed.
  - `AdminCrmPagesTest` passed: `12 passed / 129 assertions`.

### CRM Inquiry and Opportunity Boundary Optimization

- Refined inquiry statuses: new/edit screens now focus on demand-processing states (`new`, `analyzing`, `qualified`, `converted`, `invalid`, `closed`) while remaining compatible with historical `quoted / won / lost` records.
- Added a clear inquiry-to-opportunity conversion flow:
  - Carries over Collection, customer, source inquiry, opportunity name, owner, and demand-summary context.
  - Marks the source inquiry as converted after successful opportunity creation.
  - Shows a “view opportunity” path when an inquiry already has linked opportunities.
- Enhanced opportunity editing:
  - Shows the source inquiry summary, missing questions, and linked Entity / Knowledge Base / Case counts.
  - Adds a “new document” entry and linked document list.
- Improved document creation:
  - Added an “Associated Opportunity” selector to the document form.
  - Creating a document from an opportunity now carries over opportunity, customer, Collection, and source inquiry.
  - Document list and detail pages show the linked opportunity with a jump link.
- Documentation:
  - Updated `功能说明文档/10-轻量CRM与报价使用说明.md`.
  - Updated `agent-docs/IMPLEMENTATION_STATUS.md`, `FEATURE_DOC_INDEX.md`, and the CRM inquiry/opportunity optimization prompt execution notes.
- Verification:
  - PHP / Blade syntax checks passed.
  - `AdminCrmPagesTest` passed: `12 passed / 129 assertions`.
  - Browser checks confirmed the inquiry list, inquiry detail, opportunity create page, and document create page render without horizontal overflow; the source-inquiry card and opportunity selector render correctly.

## 2026-06-12

### Upstream Integration: Article Editor, Template Factory, and First-Deploy Hint

- Integrated the upstream Markdown article editor:
  - Article create/edit pages now use the Vditor Markdown editor.
  - Quick insert actions cover headings, quotes, lists, dividers, and images.
  - Admins can copy the Markdown body directly.
  - Admins can generate and copy rich-text HTML suitable for WeChat-style editors.
  - Images uploaded from the editor are stored in the dedicated article-editor image library and linked to the article.
- Added article editor backend support:
  - Added `ArticleEditorAssetController`.
  - Added `WeChatArticleHtmlExporter`, which strips unsafe HTML and applies inline styles to common Markdown elements.
  - Added editor image upload and WeChat-ready HTML export endpoints.
- Added the site template replication workflow:
  - Added the Template Factory entry in Site Settings.
  - Admins can submit home, listing, and article reference URLs to create a controlled template cloning task.
  - Reference URL validation blocks localhost, private networks, and reserved addresses.
  - Generated themes are first written into isolated draft directories instead of overwriting active themes.
  - The workflow supports previews, feedback iteration, copy-as-new-template, publish, package download, archive, and draft cleanup.
  - Added `site_theme_replications`, `site_theme_replication_logs`, and `site_theme_replication_versions` tables.
- Improved distribution logs:
  - Recent logs on the distribution index are now paginated.
  - Previous/next links and page jump controls are available.
- Added a first-deployment login hint:
  - Before the default admin signs in successfully, the login page can show the initial account hint.
  - In production environments without a fixed password, the page points admins to the `geoflow-init` initialization log.
  - The hint can be disabled with `GEOFLOW_INITIAL_ADMIN_HINT_ENABLED=false`.
- Documentation:
  - Added `docs/site-template-replication-agent-plan.md`.
  - Added `功能说明文档/11-文章编辑器与模板复刻使用说明.md`.
  - Updated README, agent handoff docs, implementation status, and known issues.
- Verification:
  - Applied migration `2026_06_10_000000_create_site_theme_replication_tables`.
  - PHP / Blade / translation syntax checks passed.
  - Combined regression tests passed for `AdminArticlesPageTest`, `AdminLoginPageTest`, `AdminSiteSettingsPageTest`, `AdminSiteThemeReplicationTest`, and `AdminDistributionPageTest`: `104 passed / 743 assertions`.
  - Browser smoke check confirmed `http://localhost:18080/admin/site-settings` opens and the Template Factory entry is rendered.

### Skipped Upstream Update

- Skipped the system update center readiness patch because this customized branch does not currently include the full update-center foundation. Integrating only the readiness layer would create a partial feature and unnecessary conflicts. Treat the update center as a separate future phase if needed.

## 2026-06-11

### CRM Workflow and Data Safety Upgrade

- Added a CRM workspace for due tasks, pipeline, active orders, after-sales cases, and recent inquiries.
- Added an opportunity pipeline with stages, amount, probability, expected close date, next step, and required lost reason.
- Separated completed interaction history from future CRM tasks; inquiry activity is now scoped to the current inquiry.
- Added multiple external contacts per customer with a primary-contact designation and backward-compatible legacy-field synchronization.
- Added soft-delete archiving for customers, inquiries, documents, orders, tickets, and activities so customer archival no longer removes commercial records.
- Added independent document conversion between quotation, PI, CI, packing list, and contract while retaining the source document link.
- Unified CRM navigation and detail-page UI, with desktop and mobile visual verification.
- Expanded CRM regression coverage to 11 tests and 108 assertions.

## 2026-06-09

### CRM Follow-up Timeline and Stable Print Baseline

- Extended follow-up records across the CRM lifecycle:
  - Customer and inquiry detail pages can create, view, and delete follow-up records.
  - Document, order, and after-sales detail pages show the related customer's follow-ups as a read-only timeline.
  - Added shared Markdown editor and follow-up item partials for consistent rendering.
- Completed the document form section rebuild with consistent collapsible sections for basic, buyer, seller, commercial, items, totals, terms, contract terms, and notes.
- Established HTML print preview as the current stable output path:
  - Experimental PDF/Excel backend methods remain without frontend entry points.
  - Restored the shared A4 styles for all five document types and created the `print-stable-20260609` Git recovery tag.
- Fixed buyer email and address fallback behavior when importing customer data into a document.

## 2026-06-08

### Consistent CRM Customer Selection and Display

- Unified customer labels across document, order, and after-sales pages, prioritizing the contact person while retaining company context.
- Added customer selection to order editing, including controller validation and persistence.
- Standardized customer dropdown metadata to include contact/company and Collection context, reducing ambiguous selections.

## 2026-06-07

### CRM Document Fields and Print Template Refinement

- Normalized customer and buyer field semantics:
  - Customers now separate `contact_person` from `company_name`.
  - Documents separate `buyer_contact` from `buyer_company` across forms, customer import, and print output.
  - Renamed customer `region` to `address`.
- Added packing terms, PI deposit percentage, loading/destination ports, transport mode, shipping mark, package counts, weights, volume, and package dimensions.
- Standardized bank-account JSON fields and removed the unused SKU field.
- Refined all five print templates:
  - PI payment/bank details, CI parties/declaration, and PL shipment/packing information now follow their document-specific responsibilities.
  - Unified signature, contact, buyer/seller naming, and notes rendering rules.
- Added the Entity-to-Entity relationship foundation for future inquiry recommendations, document items, orders, and after-sales context expansion.

## 2026-06-06

### CRM Document System Phases 2-10

- Completed the CRM document form rebuild:
  - Create/edit pages are now organized into Basic Info, Buyer Info, Commercial Info, Line Items, Totals, Terms & Notes, and Custom Contract Terms sections.
  - Line items support line type, Entity, SKU, model, HS code, item image, package count, net weight, gross weight, and volume CBM.
  - Item images can be selected from the image library or uploaded locally, with uploaded image files limited to 200KB.
  - The frontend previews item subtotal and grand total in real time, while backend calculation remains authoritative on save.
- Split document print rendering by document type:
  - Quotation, Proforma Invoice, Invoice, Packing List, and Contract now route to type-specific print wrappers using a shared base layout.
  - Proforma Invoice displays bank account details; Packing List hides price / amount columns; Contract displays custom customer terms, governing law, dispute resolution, and signature blocks.
  - Print pages read seller basics from site settings and allow document-level `seller_company_json` overrides.
  - Print templates support basic English and Simplified Chinese labels, with legacy documents falling back to the default language.
- Updated quote detail rendering:
  - Detail pages now show buyer information, commercial terms, image thumbnails, packaging / weight fields, and grand total.
  - Creating orders from quotes remains compatible with legacy quote amount fields.
- Documentation:
  - Updated the lightweight CRM guide with the new document form, image fields, type-specific print templates, and current boundaries.
  - Updated `agent-docs/IMPLEMENTATION_STATUS.md` for future agent handoff.
- Verification:
  - `AdminCrmPagesTest` passed, covering quote creation, image upload fields, Proforma Invoice, Packing List, Contract print pages, and quote-to-order flow.
  - Completed headless Chrome screenshot checks for the new document form and multiple print pages.

### CRM Document System Phase 1

- Expanded the CRM quote / invoice / packing list / contract data model:
  - Added buyer fields, document language, trade term, origin country, warranty / installation terms, shipping fee, discount, tax, grand total, bank account, seller company, signature notes, custom contract terms, governing law, and dispute resolution to `crm_quotes`.
  - Added line type, SKU, model, HS code, image reference / uploaded image path, package count, net weight, gross weight, and volume CBM to `crm_quote_items`.
  - Added `invoice` as a supported `document_type`.
- Updated backend save behavior:
  - Item subtotal remains backend-calculated as `quantity × unit price`.
  - Grand total is now calculated as `items subtotal + shipping fee + tax - discount`.
  - When old quotes have `grand_total=0`, order creation still falls back to the legacy `total_amount`.
- Documentation:
  - Documented the CRM document implementation plan, including line item images and custom contract terms; completion details are now maintained in the agent implementation status and usage guide.
  - Updated the lightweight CRM usage guide to remove old external-contact wording and keep the internal-owner model consistent.
- Verification:
  - Applied migration `2026_06_06_030000_enhance_crm_quote_documents`.
  - Related PHP / Blade syntax checks passed.
  - `AdminCrmPagesTest` passed, covering invoice creation, grand total calculation, and print title rendering.
  - Completed a headless Chrome render screenshot check for the quote creation page.

## 2026-06-05

### Lightweight CRM Phases 4-7

- Phase 4: added order management:
  - Quote detail pages can create sales orders directly.
  - Orders preserve customer, inquiry, quote, Collection, and Entity context.
  - Order items support product text, quantity, unit price, amount, and status maintenance.
- Phase 5: added after-sales tickets:
  - Tickets can link to customers, orders, Collections, a core Entity, Knowledge Bases, and Cases.
  - Ticket AI analysis can extract issue summaries, suggested replies, missing questions, and related material suggestions.
  - AI only recommends existing materials and does not write directly to Knowledge Bases or Case DB.
- Phase 6: added CRM content proposals and task source linkage:
  - Inquiry detail pages can create title and FAQ proposals.
  - Ticket detail pages can create FAQ and Case proposals.
  - Content proposals must be approved by an administrator before writing into title libraries, Knowledge Bases, or Case DB.
  - Task creation supports customer, inquiry, or ticket as a CRM source, with Collection-aware filtering.
- Phase 7: improved CRM filters and admin entry points:
  - CRM list pages now include Collection, status, and type filters where relevant.
  - CRM navigation covers customers, inquiries, quotes, orders, after-sales tickets, and content proposals.
  - Task CRM source selection is limited to the current Collection by default; cross-Collection use requires explicit opt-in.
- Verification:
  - Applied migration `2026_06_05_040000_create_crm_order_ticket_and_content_tables`.
  - PHP / Blade syntax checks passed.
  - `AdminCrmPagesTest` and `AdminTasksPageTest` passed.
  - Visual screenshot checks were completed for orders, tickets, ticket detail, proposals, task creation, and ticket creation pages.

### Lightweight CRM Phases 1-3

- Added a new admin `CRM` menu entry, positioned as lightweight customer / inquiry / quotation support rather than a full ERP module.
- Phase 1: added basic customer management:
  - Customers can be assigned to Collections.
  - Customer detail pages support internal owners and follow-up records.
  - Customer lists support search, status filtering, and Collection filtering.
- Phase 2: added inquiry management and AI need recognition:
  - Inquiries can link to customers, internal owners, Collections, Entities, Knowledge Bases, Cases, and tags.
  - AI analysis can fill language, need summary, product interest, reply points, missing questions, and urgency.
  - AI only recommends existing Entities, Knowledge Bases, and Cases; it does not create new materials.
  - When no model is configured or the AI call fails, a local keyword-matching fallback provides recommendations.
  - Inquiry-linked materials are Collection-limited to avoid cross-business-context mistakes.
- Phase 3: added quotation management:
  - Quotes can be created from customers or inquiries.
  - Creating a quote from an inquiry can prefill the customer, Collection, and linked Entities.
  - Quote items support Entity links, quantity, unit, unit price, and backend amount calculation.
  - Added quote detail and printable quote pages.
- Documentation:
  - Added `功能说明文档/10-轻量CRM与报价使用说明.md`.
  - Updated `agent-docs` handoff, implementation status, known issues, and feature-doc index.
- Verification:
  - `AdminCrmPagesTest` passed, covering customers, inquiries, AI fallback recommendations, quotes, and printable pages.
  - `AdminCollectionsPageTest` passed, confirming existing Collection/material entry points remain intact.

### Article Generation Max Output Token Setting

- Added an "Article Max Output Tokens" field to the AI model configuration page:
  - Only chat models show and save this field; embedding models ignore it.
  - Empty values fall back to `GEOFLOW_CONTENT_MAX_TOKENS`, defaulting to `8192`.
  - The model list shows whether each chat model uses a model-level value or the system default.
- The article-generation Worker now passes the max output token setting into model requests:
  - OpenAI uses `max_output_tokens`.
  - Gemini uses `maxOutputTokens`.
  - OpenAI-compatible / DeepSeek / OpenRouter-style providers use `max_tokens`.
- Added truncation-risk logging:
  - Warnings are written when the provider returns `finish_reason=length`, Markdown code fences are unclosed, or long content appears unfinished.
- Verification:
  - Applied migration `2026_06_05_020000_add_max_tokens_to_ai_models`.
  - Focused `AdminAiModelsPageTest` and `WorkerExecutionServiceMaxTokensTest` tests passed.

### Article Skill Prompt v1

- Added a lightweight Master Prompt + Skill Prompt generation layer:
  - Existing `prompt_id` remains the required Master Prompt for tasks, preserving old task-generation behavior.
  - Added optional `tasks.skill_prompt_id` for task create/edit pages.
  - During article generation, the Worker composes Master Prompt and Skill Prompt before rendering title, keyword, and RAG knowledge variables.
  - Generation traces now record `skill_prompt_id`, `skill_prompt`, and `has_skill_prompt` for article review and debugging.
- Expanded the Article Prompt Configuration entry:
  - The former content prompt page now manages both `content` Master Prompts and `skill` Skill Prompts.
  - Added starter Skill Prompt templates for Comparison, Buying Guide, and Application article types.
  - Prompts already referenced by tasks cannot change type directly, preventing semantic drift for existing tasks.
- Added `skill_prompts` to the API catalog response for future external task creation flows.
- Verification:
  - Applied migration `2026_06_05_010000_add_skill_prompt_id_to_tasks`.
  - `WorkerExecutionServicePromptTest`, `AdminAiPromptsPageTest`, `AdminTasksPageTest`, and focused API catalog / task-create tests passed.

### AI Material Analysis Prompts and URL Import Language Fixes

- Added AI analysis model selection when creating URL Smart Import jobs:
  - Admins can choose a specific chat model or keep the default auto-select behavior.
  - The selected model is tried first for page cleaning, knowledge organization, keyword generation, and title generation.
  - Existing failover remains in place, so other available models are still tried if the selected model fails.
- Added shared AI material analysis rules:
  - Centralized language consistency, fact-grounding, table/spec preservation, and JSON-only output constraints.
  - Manual Knowledge Base, Entity, and Case AI analysis now reuse these rules.
  - Knowledge analysis now clearly separates summary, admin-facing description, and storage-ready Markdown content instead of encouraging raw paragraph copying across fields.
- Improved preset analysis rules for Knowledge Base, Entity, and Case forms:
  - Knowledge Base analysis prioritizes product parameters, FAQ, steps, constraints, and table content.
  - Entity analysis treats Entities as lightweight index nodes rather than full knowledge documents.
  - Case analysis only extracts cases when real scenarios, problems, solutions, results, or metrics are present, and metrics must not be invented.
- Added a collapsible supplemental analysis instructions field:
  - Available on Knowledge Base create/detail, Entity, and Case forms.
  - Includes quick templates for table/spec preservation, English output, and conservative Case extraction.
  - Supplemental instructions act only as additional guidance and cannot override schema, language, or fact-grounding rules.
- Fixed mixed-language URL Smart Import Entity descriptions:
  - Non-Chinese pages no longer use a hard-coded Chinese description template.
  - Entity descriptions now preserve evidence/context according to the resolved import language.
- Verification:
  - Added tests for English URL Entity descriptions and shared prompt rules.
  - Relevant PHP syntax checks and focused material page tests passed.

### Knowledge Base Entity Relations and Case Type Governance

- Improved the Knowledge Base to Entity linking workflow:
  - Knowledge base create, edit, and detail pages now reuse the same multi-select plus per-item relation UI used by Entity material links.
  - A single knowledge base can link to multiple Entities and assign each Entity its own relation, such as primary subject, supporting reference, application reference, competitor reference, or troubleshooting reference.
  - The global default relation field remains as a compatibility fallback for existing data, bulk actions, and older form submissions.
  - Entity edit pages also reuse the same shared relation selector component when linking knowledge bases, reducing duplicated JavaScript and UI rules.
- Added a shared relation multi-selector component:
  - Added `resources/views/admin/partials/relation-multi-selector.blade.php`.
  - Centralizes the interaction where selected items each receive an independent relation setting.
- Improved Case type governance:
  - Added controlled Case types for customer success, application scenario, troubleshooting, comparison validation, implementation delivery, ROI/metrics, and general cases.
  - URL Smart Import and AI form analysis now normalize generated Cases to controlled types instead of creating free-form types such as `URL采集案例`.
  - RAG and Worker generation context now includes Case-type reference rules so article generation can use case evidence more accurately.
- Improved material list deletion behavior:
  - Keyword library, title library, image library, and knowledge base deletes preserve the current query filters and return to the material list section.
  - Knowledge base saved views now include a Clear option that clears view filters while keeping the current Collection.
- Documentation:
  - Updated the feature docs for overview, Entity/Case, Knowledge Base RAG, material management, and URL Smart Import.
  - Updated agent implementation status so future agents can quickly understand the workflow changes.
- Verification:
  - Relevant PHP, Blade, and translation syntax checks passed.
  - `AdminMaterialsPagesTest` passed, covering per-Entity knowledge base relations, shared Entity-page relation UI, and core material workflows.

## 2026-06-04

### Entity Internal Link Suggestions and UI Guidelines

- Added a controlled Entity type system:
  - Entity types are now constrained to product model, product line, industry, application scenario, material/component, technology/process, brand/company, competitor, customer segment, and general business entity.
  - Historical free-form types remain editable for backward compatibility.
  - Internal-link fields are shown only for linkable Entity types such as product model, product line, application scenario, technology/process, and brand/company.
- Added article draft internal link suggestions:
  - Article edit pages now show an internal-link suggestion card after article content and before generation sources.
  - Suggestions are based on linked Entities, Collection scope, and matched article text.
  - Admins must explicitly select and apply suggestions before Markdown links are written to the article body.
  - Added the `article_internal_links` table to record applied links for review and traceability.
- Improved Entity usage in RAG and generation traces:
  - Entity traces now include writing role information and whether the Entity can participate in draft link suggestions.
  - Final generation prompts explicitly tell the AI not to insert internal links directly; draft review handles them separately.
- Improved AI form analysis fallback:
  - Entity AI analysis now normalizes unknown free-form types to the general business Entity type.
- Added a local Codex UI guideline skill:
  - Created `/Users/leo/.codex/skills/geoflow-ui-guidelines/SKILL.md`.
  - Future GEOFlow admin UI, Blade, Tailwind, form, dropdown, selector, CSS, or JavaScript work should load this skill automatically.
  - The skill prioritizes existing partial/component reuse, consistent input border/focus styles, and avoiding duplicated CSS/JS.
- Verification:
  - Applied migration `2026_06_03_090000_add_entity_link_fields_and_article_internal_links`.
  - Relevant PHP syntax checks passed.
  - Focused feature tests passed for Entity link fields, article internal-link application, and RAG Entity traces.

## 2026-05-28

### v2.0.2

- Upgraded the admin dashboard into a GEOFlow automation workflow panel:
  - Shows how APIs, material libraries, tasks, articles, distribution, Analytics, and site settings connect in the automated production flow.
  - Keeps the three-step setup guide and companion Skill shortcuts while removing duplicated dashboard metric cards.
- Improved Analytics data accuracy:
  - Total views, viewed content, top content, and log analytics now prefer `view_logs` event data and filter out non-GET requests.
  - Publishing trends use actual `published_at` timestamps, and distribution metrics respect task/category filters through related articles.
  - AI crawler, search bot, other automation, and human traffic classification now share one rule set to reduce misclassification.
- Improved local Docker development behavior:
  - The development image disables CLI OPcache so mounted code updates are reflected without stale admin pages.
- Updated the admin version to `2.0.2`, including `version.json`, environment examples, and default admin version display values.

## 2026-05-24

### AI Models and Knowledge Bases

- Added native Gemini model support:
  - Gemini chat and embedding models can be configured without relying only on OpenAI-compatible routes.
  - Model listings, connection tests, and task generation now recognize Gemini providers consistently.
- Added knowledge-base chunking strategy configuration:
  - Supports structured rule chunking, automatic strategy selection, and optional LLM semantic planning.
  - The LLM only plans semantic boundaries; final chunks are rebuilt from the source text, with rule chunking as the stable fallback.
  - Chunk metadata now includes title, section path, strategy, sequence, and source hash for preview, debugging, and rebuilds.

### Tasks and Distribution

- Improved task create/edit pages:
  - Form width now aligns with the task-management list and reduces unused side whitespace.
  - Content settings, material choices, and distribution-scope sections use the wider layout more effectively.
- Fixed channel selection when the publication scope is local-only:
  - Selecting “publish only to local site” disables and clears distribution channel checkboxes in the UI.
  - The backend ignores stale `distribution_channel_ids` under `local_only`, preventing accidental remote distribution jobs.

### Documentation

- Updated the repository README and localized READMEs with Gemini, semantic chunking, WordPress REST channels, and publication-scope behavior.
- Updated the Chinese and English Wiki outline and added focused pages for Distribution Management, Analytics, and Knowledge Chunking / RAG.

## 2026-05-23

### Distribution Management

- Added WordPress REST API distribution channel support:
  - Supports WordPress Application Password authentication, with encrypted storage and no plaintext reveal.
  - Supports post publish, update, delete, media upload, category/tag sync, and basic site settings sync.
  - Shows different configuration fields and onboarding guidance for GEOFlow Agent and WordPress REST channels.
  - Reuses the unified distribution queue, remote metadata, health checks, remote edit/delete actions, and distribution logs for WordPress channels.

### Documentation

- Systematically refreshed the repository homepage README and localized READMEs:
  - Updated the hero description from future multi-channel distribution to the current GEO content engineering and multi-site distribution system.
  - Added Analytics, Distribution Management, target-site packages, static page distribution, `llms.txt` / TXT maps, remote site-settings sync, and LLM-friendly output to the feature tables.
  - Updated runtime and architecture sections with target-site Agents, distribution queues, remote static pages, and log analytics.

## 2026-05-22

### v2.0.1

- Added a working Distribution Management flow:
  - The admin now includes distribution channel listing, creation, editing, detail pages, queue view, logs, connection tests, pause/enable actions, secret reset, and remote article management.
  - Channel secrets are shown once after creation, and super admins can temporarily reveal them again by verifying the current login password.
  - Tasks and articles can be bound to distribution channels. After local publishing, articles can automatically enter the distribution queue, with distribution status visible on task and article lists.
  - The distribution queue supports remote-copy editing and deletion. Remote edits also update the local GEOFlow article, and remote deletion refreshes the target homepage and map files.
- Added target-site packages and static-site delivery:
  - Channel detail pages can download target-site packages preconfigured with the current channel secret, site settings, and deployment path.
  - Packages include a PHP Agent, homepage, article detail pages, static assets, sitemap, TXT map, Apache `.htaccess`, and Nginx rewrite-rule examples.
  - Static mode is enabled by default. Publishing or deleting articles regenerates the static homepage, detail pages, sitemap, and LM-friendly TXT map files.
  - Article pages now include Markdown rendering, tables, code blocks, quotes, image rendering, Schema structured data, and external CSS asset references.
- Added remote site-settings synchronization:
  - Distribution channel edit pages can manage target-site title, subtitle, description, copyright, ICP/filing text, theme template, and categories.
  - Added an Update Target Site action to resync homepage, article pages, map files, and remote configuration after uploading a fresh package or changing settings.
  - Added static-mode and rewrite-mode guidance, plus copyable Apache/Nginx rules in the admin.
- Added the Analytics page:
  - The admin top navigation now includes Analytics, centralizing system overview, single-site operations, multi-site distribution, and self-service log data.
  - Analytics supports date range, quick time ranges, distribution channel, task, category, article, traffic type, and log source filters. Quick time selection updates the form first; data refreshes after clicking Apply Filters.
  - Content analytics includes publishing trends, task trends, content funnel, category distribution, and task/material/AI health panels.
  - Log analytics includes visit trends, top articles, top channel sites, AI crawler recognition, status codes, source types, and sample access-log visualization.
- Reworked the admin dashboard into a navigation hub:
  - Removed dashboard statistics cards and moved statistics into Analytics.
  - Kept the three-step setup guide and grouped common entries into Single-Site Operations, Multi-Site Distribution, and companion Skill resources.
  - Added prompt configuration and user management entries under single-site operations, plus target packages, distribution queue/logs, and related skills under multi-site distribution.
- Improved the first-deployment guide:
  - `GEOFlow 2.0 First Deployment Guide` now uses a compact white Kami-style document layout with smaller title and body typography.
  - Copy now covers dashboard navigation, Analytics, single-site operations, multi-site distribution, and backup checks before production.
- Incorporated low-risk Docker deployment PR improvements:
  - Development and production compose files can now configure PHP, Composer, Nginx, pgvector, Redis, and Composer Packagist mirror images through environment variables.
  - `.dockerignore` now excludes local Docker data, logs, caches, sessions, view caches, and upload directories so runtime data is not copied into built images.
  - Added default-admin seeder coverage for creating the initial admin and preserving existing credentials.
- Expanded test coverage:
  - Added tests for Distribution Management, Analytics, access logs, admin activity sanitization, the welcome guide, migration structure, and retry policy.
  - Full release verification passed with `188 passed` and `1231 assertions`.

## 2026-05-21

### v2.0

- Updated the admin version to `2.0`, including `version.json`, environment examples, and default admin version display values.
- Reworked the first-login admin welcome panel into a first-deployment guide:
  - Reminds administrators to check passwords, admin path, site URL, language, and baseline security settings first
  - Guides verification of PostgreSQL, Redis, queue workers, scheduler, and writable storage paths
  - Clarifies the first-run flow: configure models and prompts, prepare materials, generate a small sample, review/publish, then scale to larger tasks
- Added first-use guidance for Distribution Management 2.0:
  - Explains target channels, Agent URL, secrets, static mode, and target-site packages
  - Guides package download, connection tests, remote settings sync, and distribution log review
  - Emphasizes backing up the database, `.env`, uploads, `storage`, and target-site packages before upgrades or migrations

## 2026-05-10

### v1.2.x

- Improved third-party AI title generation compatibility:
  - The title generation flow no longer hardcodes the `openai` driver
  - Runtime driver selection now uses the API base URL and model ID
  - Prevents DeepSeek, Zhipu, MiniMax, Volcengine Ark, Alibaba DashScope, and other OpenAI-compatible providers from being routed to `/v1/responses` and returning 404 errors
- Strengthened URL Smart Import security configuration:
  - SSRF protection remains strict by default
  - Added `URL_IMPORT_ALLOW_MIXED_DNS=false` as an example setting only for explicitly controlled transparent proxy, Docker, or VPN mixed-DNS environments
  - Application code reads `config('geoflow.url_import_allow_mixed_dns')`, so it is compatible with Laravel config caching
- Added coverage for model driver resolution and URL normalization.
- Fixed default admin initialization for production Docker first-time deployment:
  - `docker/entrypoint.prod.sh` now supports `AUTO_SEED`
  - `docker-compose.prod.yml` enables seeding only for the one-shot `init` service
  - The default admin account is created after first-time migrations, and repeated runs do not overwrite an existing `admin` user

## 2026-05-08

### v1.2.x

- Added AI model connection testing:
  - Admin AI model lists can now test API connectivity directly
  - Basic checks cover both chat models and embedding models
  - Failed tests return concrete errors to help diagnose API keys, endpoints, model IDs, and provider settings
- Improved frontend and admin asset loading stability:
  - Replaced external Tailwind Play CDN and Lucide CDN usage in frontend templates with locally hosted assets
  - Reduces the risk of broken styles or scripts in regions where external CDNs are unstable
- Added one-click deployment scripts and deployment documentation:
  - Added `deploy-scripts/` for Docker deployment, server preflight checks, and post-deployment health checks
  - Updated the Wiki with deployment guidance, server sizing recommendations, and deployment script usage notes
- Fixed task deletion compatibility:
  - Task deletion no longer depends on the legacy `article_queue` table
  - Prevents `Undefined table: article_queue` errors on the current database schema
- Improved optional material field handling in the task creation API:
  - API task creation can now omit optional author, image library, knowledge base, and fixed category fields
  - Omitted fields are written as explicit `null` values, keeping the API contract aligned with admin task creation
  - Added API contract coverage for omitted optional material fields
- Added a NetEase News-inspired frontend theme:
  - Added the `netease-news-20260429` frontend theme
  - Homepage, category, and article pages now support a cleaner two-column news-style reading layout
  - Preserves GEOFlow article, category, author, SEO, and Schema data contracts
- Added a TDWH English theme fork:
  - Added the `tdwh-english-20260501` English theme sample
  - Provides a clearer internationalized homepage, listing page, and article page structure for English content sites

## 2026-05-06

### v1.2.x

- Fixed the author fallback logic during task-based article generation:
  - If a task has no author configured, GEOFlow now uses an existing author automatically
  - If the configured author no longer exists, GEOFlow falls back to an available author
  - If no author exists in the system, GEOFlow creates a default `GEOFlow` author
  - This prevents PostgreSQL `NOT NULL` failures caused by writing `null` into `articles.author_id`
- Improved AI parsing compatibility for `URL Smart Import`:
  - When one AI model fails, GEOFlow continues with the next available model
  - Keyword and title stages can now parse plain-text AI lists, reducing failures caused by non-standard JSON responses
  - Error messages keep the model name and concrete failure reason for easier API key, response format, and provider debugging
- Upgraded the admin dashboard:
  - Added overview panels for tasks, materials, AI models, URL imports, and popular content
  - Repositioned the quick-start and trend sections to make the dashboard more useful for operations
  - Fixed overly tight spacing between the weekly trend chart and the health panels below it
- Stabilized the local runtime after the fixes:
  - Cleared Laravel optimize cache and restarted the app / queue / scheduler containers
  - Added tests for task author fallback across empty-author, missing-author, and no-author initialization scenarios

## 2026-04-18

### v1.2

- Added first-stage Chinese/English interface support:
  - English is now available across the formal admin pages
  - The login page now has its own language selector
  - The frontend shell follows the admin language selection
- Added `Smart Model Failover` for tasks:
  - Tasks can now use `Fixed Model` or `Smart Failover`
  - When the primary model fails, GEOFlow automatically tries the next available chat model by priority
- Improved provider endpoint handling:
  - Supports versioned chat and embedding endpoints for OpenAI, DeepSeek, MiniMax, Zhipu GLM, and Volcengine Ark
  - Model settings now accept either a base URL or a full endpoint
- Improved task execution behavior:
  - `task-execute.php` now queues execution instead of blocking the page synchronously
  - `published_count` is now updated correctly for tasks that publish directly
- Added frontend theme preview and activation:
  - dynamic `preview/<theme-id>` routes for safe preview-first inspection
  - theme package support under `themes/<theme-id>`
  - admin-side theme preview and activation in Site Settings
  - sample theme `qiaomu-editorial-20260418` is now included in the public repository
  - homepage, category, and archive card summaries now strip Markdown artifacts before rendering
- Added an admin first-login welcome panel:
  - shown automatically after the first admin login
  - redesigned as a single welcome letter instead of a multi-card module layout
  - defaults to Chinese with an in-panel English switch
  - footer now includes a `Project Intro` entry that reopens the panel
  - implementation notes are documented in `project/ADMIN_WELCOME_en.md`
- Added the companion `geoflow-template` skill entry:
  - maps reference URLs into GEOFlow-compatible theme packages
  - outputs `tokens.json`, `mapping.json`, and preview-first theme plans
- Upgraded default GEO prompt templates:
  - Long-form templates now cover article generation, ranking articles, keywords, and descriptions
  - Templates are aligned with GeoFlow's variable rules
- Fixed multiple admin usability issues:
  - PostgreSQL timezone drift
  - Missing leading `/` in generated image paths
  - PostgreSQL boolean write error when saving AI-generated titles
  - Default provider examples now use a neutral DeepSeek sample instead of the old third-party domain
