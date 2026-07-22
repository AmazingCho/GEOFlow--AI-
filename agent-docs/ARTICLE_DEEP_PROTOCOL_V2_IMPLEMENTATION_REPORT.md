# Article Deep Protocol V2.4 Implementation Report

Date: 2026-07-22

## Verdict

Phases 0-5 are implemented and qualified offline. The candidate is ready for a bounded paid protocol canary, but Phase 6 has not been run and no claim is made that article quality has improved.

## Implemented

- Protocol version: `deep-v2.4-structured-plan-1`.
- Plan and review use dedicated neutral structured-output agents; draft and revision remain Markdown-only.
- Plan V2 removes `central_answer`, `article_angle`, duplicated open-question fields, and punctuation-based question validation.
- Plan V2 uses typed `answer_mode`, `evidence_sufficiency`, `supported_sections`, exact `evidence_mapping`, and categorized `verification_items`.
- Planning receives compact intent constraints and frozen evidence only. Full writing and Style rules are kept out of planning.
- Validation aggregates safe code/path/expected violations and permits one bounded repair only.
- Repair exhaustion becomes terminal `protocol_failure`, preserves safe provider attempt/token metadata, and does not enter the queue retry loop.
- `insufficient_evidence` remains terminal and exposes only controlled missing-information categories.
- The task list distinguishes protocol failure and insufficient evidence without showing raw Plan, Prompt, or evidence text.

## Qualification Evidence

- Offline matrix: 30/30 expected outcomes, with 10 sufficient, 10 limited, and 10 insufficient cases in English and Chinese.
- Mutation coverage: missing fields, enum normalization, empty structures, unknown and near-match evidence IDs, hostile evidence instructions, malformed JSON, and repair exhaustion.
- Focused regression: 227 tests / 1177 assertions passed.
- Covered boundaries: Deep and Standard Worker paths, six-call/provider-attempt budget, queue retry ownership, trace sanitization, grounding, publication guards, Prompt composition, and admin task UI.
- PHP/Blade lint passed; Pint formatted the explicit changed PHP set; `git diff --check` passed.
- No paid model call was made during Phases 0-5. Test HTTP calls used Laravel fakes.

## UI Review

- Desktop screenshot: `output/playwright/deep-protocol-task-list-desktop.png`.
- Mobile screenshot: `output/playwright/deep-protocol-task-list-mobile.png`.
- Document-level horizontal overflow: none at 1440px and 390px.
- The existing task table remains horizontally scrollable inside its own container on mobile; this is existing behavior, not introduced by V2.4.
- The protocol-failure label and safe detail are covered by `AdminTasksPageTest`. The temporary live protocol-failure row did not enter the current paginated task list during screenshot capture, so the exact badge was not visually captured.

## Frozen Candidate

- Base commit: `3e554c1` (worktree also contains historical uncommitted changes).
- Relevant source manifest SHA-256: `7e21b01f2e8e50c18630b3d00b99db85e84d6e7cb33f5c6c361218a2f17ef537`.
- Fixture source: `tests/Fixtures/article-deep-protocol-v2/offline-matrix.php`.

## Deliberate Decisions

- No V2 feature flag was retained because the repository has no complete V2.3.1 runtime fallback. A flag without a tested fallback would be a false control.
- No automatic fallback to the old protocol occurs after V2 validation failure.
- No paid Phase 6 canary, Prompt preset apply, article publish, or distribution action was performed.

## Review Limitation

- Two independent read-only PM and regression subagent reviews were started, but both exceeded the bounded wait window and were stopped under the no-loop policy. Their approval is not claimed. The completed evidence is the automated suite, main-agent code/PM review, and browser screenshots described above.

## Next Gate

Phase 6 requires explicit approval. Use a small fixed matrix and hard provider-attempt budget to test protocol operability first. Evaluate article quality separately so protocol failure and writing quality are not mixed in one verdict.
