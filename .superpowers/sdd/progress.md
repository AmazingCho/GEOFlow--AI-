# V2.3 Grounding Governance Progress

## Scope

Implement local Phase 0-4 from `agent-docs/ARTICLE_V23_GROUNDING_GOVERNANCE_IMPLEMENTATION_PLAN.md`.

## Protected Boundaries

- [x] No worktree reset/clean/revert.
- [x] Paid model activity limited to the approved six-pair Smoke and bounded two-pair Application recheck; no broad gate started.
- [x] No prompt apply to business data.
- [x] No active Docker source switch.
- [x] No publish/deploy.
- [x] Private evaluation artifacts stay ignored and mode 0600.

## Phase Status

- [x] Plan/specification independent review (NO-GO; 2 critical, 4 high, 1 medium finding)
- [x] Corrected plan independent review (NO-GO; publish retry, canary, offline-enforcement, and exact-contract gaps)
- [x] Final corrected plan independent review (GO; no unresolved high/critical finding)
- [x] Phase 0 implementation (30 focused tests passing)
- [x] Phase 0 specification review (APPROVE)
- [x] Phase 0 regression review (APPROVE)
- [x] Phase 1 implementation (394 related tests / 2547 assertions; final focused rerun 157 / 555)
- [x] Phase 1 specification review (GO)
- [x] Phase 1 regression review (GO after two concrete P1 reproductions were fixed and rechecked)
- [x] Phase 2 implementation (191 focused tests / 617 assertions; final grounding edge-case rerun 14 / 36)
- [x] Phase 2 specification review (GO after five concrete grounding P1 reproductions were fixed)
- [x] Phase 2 regression review (GO after API scope, stale relation, and refresh-queue P0/P1 findings were fixed)
- [x] Phase 3 implementation (24 prompt-contract tests / 551 assertions; corrected sync test 1 / 8)
- [x] Phase 3 specification review (GO)
- [x] Phase 3 regression review (GO)
- [x] Phase 4 implementation (5 offline replay tests / 47 assertions)
- [x] Phase 4 bounded verification (offline replay plus changed-file format/privacy checks; no repeated full-suite loop)
- [x] Related focused test evidence recorded phase by phase
- [x] Final independent prompt review (two fresh read-only reviewers; GO / GO)
- [x] Documentation/handoff update
- [x] Six-pair paid Smoke executed (10/12 generated; 2 Deep protocol failures; 1 automatic factual block)
- [x] Independent blind editorial review completed (control 3 pairwise preferences, candidate 2, one tie in mean score)
- [x] Independent PM/QA gate review completed (NO-GO)
- [x] Fifty-four explicitly tagged evaluation drafts cleared; business database verified at zero evaluation articles
- [x] Deep plan repair bounded to one attempt; marker repair moved entirely to deterministic local normalization
- [x] Marker parser security re-review completed (initial NO-GO, corrected implementation GO with no P0/P1)
- [x] Application recheck completed (single control pass, single candidate blocked, two pre-final-parser Deep failures)
- [x] Independent blind recheck rejected both generated single-turn articles; V2.3 remains NO-GO
- [x] Final related regression completed (233 tests / 1487 assertions)

## Current Finding

Phases 0-4 and the bounded local protocol remediation are complete. Marker repair no longer sends article prose to a model: exact allowlisted punctuation variants and one standard Markdown marker gap are handled deterministically, while unknown or altered references fail closed. The related suite passes 233 tests / 1487 assertions, and independent re-review found no P0/P1. The Application recheck remains **NO-GO**: the V2.3 single-turn candidate invented an unsupported numeric scenario plus extensive process and solution detail and was correctly blocked; the control passed the narrow numeric gate but failed blind factual review as well. The two Deep outputs were generated before the final one-blank-line parser compatibility fix and failed safely without article persistence; no further paid run was started because the candidate had already failed the release boundary. Business prompts remain untouched, all 54 tagged evaluation drafts were cleared, and the database now has zero evaluation articles. Larger paid gates remain blocked pending a new sparse-evidence Application design.
