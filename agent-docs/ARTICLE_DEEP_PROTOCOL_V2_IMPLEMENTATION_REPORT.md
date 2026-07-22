# Article Deep Protocol V2.4 Implementation Report

Date: 2026-07-22

## Verdict

Phases 0-5 and the zero-cost P0 hardening pass are implemented and qualified offline. The candidate is ready for a bounded paid protocol-operability canary, but Phase 6 has not been run and no claim is made that article quality has improved.

## Implemented

- Protocol version: `deep-v2.4-structured-plan-1`.
- Plan and review use dedicated neutral structured-output agents; draft and revision remain Markdown-only.
- Plan V2 removes `central_answer`, `article_angle`, duplicated open-question fields, and punctuation-based question validation.
- Plan V2 uses typed `answer_mode`, `evidence_sufficiency`, `supported_sections`, exact `evidence_mapping`, and categorized `verification_items`.
- Planning receives compact intent constraints and frozen evidence only. Full writing and Style rules are kept out of planning.
- Validation aggregates safe code/path/expected violations and permits one bounded repair only.
- Repair exhaustion becomes terminal `protocol_failure`, preserves safe provider attempt/token metadata, and does not enter the queue retry loop.
- `insufficient_evidence` remains terminal and exposes only controlled missing-information categories.
- Provider/model failures use typed exceptions, preserve safe per-stage attempts, follow the existing queue retry budget, and become terminal `provider_failure` only after that budget is exhausted.
- Deterministic evidence, grounding, privacy, and final-review blockers become terminal `content_blocked` outcomes and never enter the queue retry loop.
- Successful runs explicitly record `draft_ready` or `draft_review_required` from the persisted review status.
- Plan schema, evidence, consistency, and semantic-claim checks now share one aggregated validator path; there is no second validator pass with conflicting rules.
- The task list distinguishes all six terminal/success outcomes without showing raw Plan, Prompt, evidence text, provider secrets, or private source material.
- Laravel `failed(null)` callbacks release the run correctly instead of leaving a pending/running record stranded.

## Qualification Evidence

- Offline matrix: 30/30 expected outcomes, with 10 sufficient, 10 limited, and 10 insufficient cases in English and Chinese.
- Mutation coverage: missing fields, enum normalization, empty structures, unknown and near-match evidence IDs, hostile evidence instructions, malformed JSON, and repair exhaustion.
- P0 focused regression: 141 tests / 757 assertions passed.
- Full Laravel regression: 873 passed / 5823 assertions. Two previously recorded, unrelated copy-baseline tests still fail: the old welcome-title expectation and the removed author entry on the foundation-materials page. Neither source/test pair changed in this pass.
- Covered boundaries: Deep and Standard Worker paths, six-call/provider-attempt budget, queue retry ownership, trace sanitization, grounding, publication guards, Prompt composition, and admin task UI.
- PHP/Blade lint passed; Pint formatted the explicit changed PHP set; `git diff --check` passed.
- No paid model call was made during Phases 0-5. Test HTTP calls used Laravel fakes.

## UI Review

- Desktop screenshot: `output/playwright/tasks-generation-outcomes-desktop.png`.
- Mobile screenshot: `output/playwright/tasks-generation-outcomes-mobile.png`.
- Document-level horizontal overflow: none at 1600px and 390px; browser console/page errors: none.
- The existing task table remains horizontally scrollable inside its own container on mobile; this is existing behavior, not introduced by V2.4.
- All six outcome labels were captured with temporary QA rows, including paused-task failures. The six QA tasks and temporary admin were deleted immediately after capture.

## Repair Checkpoint

- Pre-hardening checkpoint commit: `756a7e5`.
- Frozen candidate ID: `deep-v2.4-p0-candidate-20260722`.
- Immutable local tag: `deep-protocol-v2.4-p0-candidate-20260722`; its target is the authoritative candidate commit.
- Candidate manifest: `agent-docs/ARTICLE_DEEP_PROTOCOL_V2_CANDIDATE_MANIFEST.md`.
- Fixture source: `tests/Fixtures/article-deep-protocol-v2/offline-matrix.php`.

## Deliberate Decisions

- No V2 feature flag was retained because the repository has no complete V2.3.1 runtime fallback. A flag without a tested fallback would be a false control.
- No automatic fallback to the old protocol occurs after V2 validation failure.
- No paid Phase 6 canary, Prompt preset apply, article publish, or distribution action was performed.
- The long-running `geoflow-queue` container was restarted after the exception-policy change so the active worker uses the current code.

## Review Limitation

- Two independent read-only PM and regression subagent reviews were started, but both exceeded the bounded wait window and were stopped under the no-loop policy. Their approval is not claimed. The completed evidence is the automated suite, main-agent code/PM review, and browser screenshots described above.

## Next Gate

Phase 6 still requires explicit approval. Use only the frozen commit/tag and manifest with a small fixed matrix and hard provider-attempt budget to test protocol operability. Stop after the bounded run if any repeated protocol failure appears; evaluate article quality separately so protocol failure and writing quality are not mixed in one verdict.
