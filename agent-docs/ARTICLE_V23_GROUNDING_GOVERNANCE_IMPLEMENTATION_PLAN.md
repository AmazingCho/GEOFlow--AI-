# Article V2.3 Grounding And Governance Implementation Plan

## Global Execution Rules

- Work incrementally on the current branch because V2.2 is an uncommitted baseline; do not reset or clean unrelated changes.
- Use test-driven development: add a focused failing test, confirm failure, implement the smallest change, then rerun the focused test.
- After each phase, use two fresh subagents: one specification reviewer and one code/regression reviewer. Do not reuse reviewer context.
- Keep all private evaluation artifacts ignored and mode `0600`.
- Do not run paid models, apply prompts, switch Docker mounts, publish, or deploy.
- Run tests/replay in ephemeral Docker with `--network none`, `APP_ENV=testing`, a test-only key, and the isolated test database because the active `18080` container mounts another source tree.
- Use `Http::preventStrayRequests()`/fakes in relevant tests. The command allowlist is focused `artisan test`, offline report recomputation, read-only inspection, and permission checks; no model-generation, prompt-apply, queue-worker, publishing/distribution, Compose-switch, push, or deployment command is allowed.

## Phase 0 - Evaluation Governance Correction

### Files

- Add `app/Services/GeoFlow/ArticleSkillReleaseGate.php`.
- Update `app/Services/GeoFlow/ArticleSkillOutputEvaluator.php`.
- Update `tests/Unit/ArticleSkillOutputEvaluatorTest.php`.
- Add `tests/Unit/ArticleSkillReleaseGateTest.php`.
- Correct the private Phase 8 unblind script/report/manifest without copying private content into Git.

### Steps

1. Add a sanitized failing release-gate fixture with explicit candidate/control/style cohorts.
2. Enforce `threshold_version=article-v2.3-rubric-1`, the exact eight metric keys, mandatory candidate/control `pair_key`, exactly one artifact per side, and separate `style_matrix_key` diagnostics.
3. Reject duplicate IDs, unpaired candidates, invalid/missing scores, incomplete pairs, and ambiguous cohort metadata.
4. Require all candidate metrics >= 3 and candidate factual/structure/non-template/privacy scores >= 4.
5. Preserve pairwise factual and privacy/safety no-regression checks as separate gates.
6. Record workflow mode (`single_turn` or `deep_pipeline`) and reject a Deep claim when expected stage evidence is absent.
7. Unify PM threshold naming and bump the report schema version.
8. Recompute the private report offline/network-disabled and update manifest status to `blind_review_completed_no_go`; do not use its mixed average as a release metric.

### Verify

- Focused unit tests pass.
- Existing evaluator and command tests pass.
- Sanitized fixture proves the phase without private files; private files remain `0600` and ignored.

## Phase 1 - Structured Evidence Package

### Files

- Add `app/Services/GeoFlow/ArticleEvidencePackage.php` or equivalent focused builder/validator.
- Update `app/Services/GeoFlow/RagRetrievalService.php`.
- Update `app/Services/GeoFlow/ArticleDeepOutputValidator.php`.
- Update `app/Services/GeoFlow/DeepArticleGenerationService.php`.
- Update `app/Services/GeoFlow/WorkerExecutionService.php`.
- Add/update focused unit and feature tests.

### Steps

1. Add failing tests for revision-aware IDs for chunks, fallback knowledge, Entity, and Case evidence.
2. Return an in-memory `evidence_package` beside `context` and `trace`; never place full evidence content inside the persisted trace.
3. Add sanitized trace metadata containing only IDs, source IDs/types, states/scopes, and content hashes.
4. Render evidence IDs in the frozen context shown to the model.
5. Pass the allowed evidence-ID set into plan validation.
6. Reject unknown refs, empty mappings, and evidence claims sourced only from title/keyword/brief.
7. Dual-read legacy `heading_intent`, canonical-write schema-v2 `contribution`.
8. Require and validate invisible evidence markers for specific-claim paragraphs, build the claim ledger, strip markers before persistence, and persist only hashes/IDs.
9. Detect unmarked numeric/unit, selected Entity/Case/model-name, outcome, and capability claim candidates; mark ledger coverage partial and force review.

### Verify

- RAG retrieval tests confirm revision-aware IDs, trace redaction, and backward compatibility.
- Validator rejects fabricated refs and accepts valid refs.
- Deep pipeline tests confirm the evidence package reaches plan/draft/review stages and final claim markers are stripped.
- Canary tests prove full evidence text is absent from `TaskRun.meta`, admin/API payloads, logs, exception messages, and tracked report output.
- A focused test proves unverified/unknown Case evidence cannot activate Case Study generation.

## Phase 2 - Deterministic Factual And Safety Gate

### Files

- Add `app/Services/GeoFlow/ArticleGroundingGate.php`.
- Update `app/Services/GeoFlow/ArticleDeepOutputValidator.php`.
- Update `app/Services/GeoFlow/DeepArticleGenerationService.php`.
- Update `app/Services/GeoFlow/WorkerExecutionService.php`.
- Add `app/Services/GeoFlow/ArticlePublicationGuard.php` and apply it to admin, API, worker, batch, and distribution paths.
- Update `app/Services/GeoFlow/ArticleSkillOutputEvaluator.php` where offline evaluation needs the same contract.
- Add focused tests.

### Steps

1. Add failing tests for supported/unsupported number-with-unit claims, harmless numbers, privacy, unsafe instructions, and negated warnings.
2. Return structured issues with code, severity, confidence, excerpt hash, and outcome.
3. Require all eight model-review metrics and force factual/privacy scores below 4 to fail.
4. Run the deterministic gate after Deep draft and revision; block only high-confidence blockers.
5. Mark ambiguous findings for manual review.
6. For Standard mode, record the sanitized gate result and set review pending without refusing persistence.
7. Add controller, API, worker, batch, and distribution tests proving pending/rejected articles cannot publish or queue; an explicit approved review resolves the boundary.
8. Add job/retry tests proving approval revoked after enqueue prevents a remote request and is handled without an endless retry loop.
9. Add compatibility tests for manually authored articles with no generation trace.

### Verify

- Deep blocked drafts are not persisted.
- Standard behavior remains backward compatible apart from pending review/trace metadata.
- No publication/distribution entry point bypasses approval.
- Existing task counters and article persistence tests still pass.
- Existing approved, already-queued work still processes; revoked approval fails safe.

## Phase 3 - Minimal Prompt And Dynamic Structure Correction

### Files

- Update `database/seeders/data/prompt_presets_v2.php`.
- Update prompt contract and seeder tests.
- Update Deep planning prompt only where needed for `contribution` and evidence rules.

### Steps

1. Capture the current V2.2 candidate hashes in the test fixture before editing.
2. Add failing contract tests proving only four candidate presets change: Master, Application, Case Study, Comparison.
3. Add one concise Master grounding rule.
4. Correct Application, Case Study, and Comparison according to the demonstrated failures.
5. Explicitly keep Style and Troubleshooting candidate hashes unchanged.
6. Keep modules optional; permit merge/omit and avoid fixed heading wording.

### Verify

- Prompt catalog/hash tests pass.
- No prompt is applied to the business database.
- Diff review shows no unrelated preset churn.

## Phase 4 - Sanitized Offline Replay

### Files

- Add sanitized fixtures under `tests/Fixtures/article-grounding/`.
- Add focused replay tests.
- Update implementation report and agent handoff/progress docs.

### Steps

1. Consolidate the synthetic fixtures added phase-by-phase for every confirmed failure and positive control, including unpaired candidates, Style-only rows, missing markers, and revoked distribution approval.
2. Replay evidence validation, deterministic gate, release cohort gates, and prompt contract offline.
3. Run all related unit/feature tests in ephemeral Docker.
4. Confirm tracked files contain no private Phase 8 article text, PM notes, API secrets, or customer data.
5. Record remaining paid validation as an explicit future approval gate.

### Verify

- Offline replay is deterministic and zero-cost.
- Secret/privacy scan of changed tracked files passes.
- Fresh final specification and regression reviewers return no unresolved high/critical finding.

## Deferred Approval Gates

1. Six paid smoke comparisons, including real Deep pipeline cases.
2. Twelve targeted comparisons if the smoke gate passes.
3. Full 24-item blind review only if prior gates pass.
4. Separate Style matrix, including Conversational Expert.
5. Prompt preview, explicit apply, Docker source switch, then deployment.
