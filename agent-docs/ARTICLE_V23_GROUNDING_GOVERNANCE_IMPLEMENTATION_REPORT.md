# Article V2.3 Grounding Governance Implementation Report

## Status

Local source implementation is complete for Phases 0-4, the bounded Deep-protocol remediation, and the zero-cost V2.3.1 sparse-evidence policy. The approved six-pair Smoke, two-pair Application recheck, and final one-pair V2.3.1 paid gate all returned **NO-GO**. V2.3.1 has not been applied to the business prompt database or approved for production publication.

## Delivered

1. Release evaluation governance
   - Candidate, control, and Style cohorts are separated.
   - Candidate/control pairing, exact score keys, absolute thresholds, no-regression checks, and Deep evidence claims fail closed.
   - Style rows remain diagnostic and cannot contaminate the core release score.
2. Structured evidence package
   - Knowledge, Entity, and Case context receives revision-aware evidence IDs.
   - Specific claims can carry invisible evidence markers; markers are validated and stripped before persistence.
   - Persisted traces contain safe IDs and hashes rather than full source text.
3. Deterministic grounding and publication guard
   - Unsupported number-with-unit claims, direct contact exposure, and unsafe operational instructions are blocked at high confidence.
   - Ambiguous or partially covered claims require review.
   - Admin, API, worker, queue, retry, refresh, and distribution paths share the same publication boundary.
   - Approval is re-read before remote distribution; revoked approval fails terminally without a retry loop.
4. Minimal prompt correction
   - Only Master, Application, Case Study, and Comparison candidate presets changed to V2.3.
   - Style and Troubleshooting preset hashes remain unchanged.
   - Optional modules remain optional; no fixed heading, FAQ, table, or conclusion template was introduced.
5. Sanitized offline replay
   - `tests/Fixtures/article-grounding/offline-replay.php` contains synthetic, non-customer cases.
   - `ArticleGroundingOfflineReplayTest` replays grounding, markers, publication approval, release cohorts, and prompt hash boundaries without network or model calls.
6. Bounded Deep-protocol remediation
   - Recoverable marker punctuation and a missing marker colon are normalized locally only when the complete payload contains exact frozen-allowlist IDs.
   - Unknown, truncated, case-altered, zero-width, and garbage-suffixed references fail closed.
   - Article prose is never sent to a marker-repair model call; normal Deep paths remain three or five calls, with six available only when the structured plan needs its one repair before a revision cycle.
   - The six-call ceiling is enforced against real provider attempts, including failed failover requests, rather than only counting logical pipeline stages.
   - One standard Markdown blank line between a claim and marker is accepted; a wider gap remains invalid.
7. Sparse-evidence delivery policy (V2.3.1 local candidate)
   - Deep planning must classify evidence as `sufficient`, `limited`, or `insufficient`.
   - `limited` evidence may produce a shorter, explicitly bounded article. Word-count, section-count, and completeness requests cannot force unsupported expansion, and the saved draft always requires human review.
   - Limited-evidence output is also protected by an executable over-expansion gate; extreme output growth relative to the frozen evidence package is blocked rather than left to Prompt compliance alone.
   - `insufficient` evidence stops after planning and reports the missing information instead of generating an article.
   - An insufficient-evidence result is terminal for that queue run and is not retried as a transient provider error. Only allowlisted missing-information categories reach the admin task error; raw model questions and source content remain private.
   - The Application candidate explicitly forbids inventing plausible process stages, components, consequences, maintenance actions, or integration details to complete a conventional outline.
8. Revision-bound review and publication safety
   - Human approval for governed articles is bound to the exact title/body SHA-256 revision. A stale approval cannot authorize later content.
   - Admin editing, API editing, internal-link application, and distribution-side editing all revoke the earlier approval when title or body changes.
   - Distribution-side content edits save a local pending draft and do not overwrite the remote article before re-review.
   - `insufficient` evidence is a terminal publication block; changing the review-status field cannot bypass it.
   - The final grounding hash is calculated after image insertion, and trace metadata includes Deep protocol plus Prompt preset key/version references.
   - Prompt synchronization fingerprints include source and target versions, and governed presets cannot be downgraded.

## Validation Evidence

- Phase 0: 30 focused tests passed.
- Phase 1: 394 related tests / 2547 assertions; final focused rerun 157 / 555.
- Phase 2: 191 focused tests / 617 assertions; final edge-case rerun 14 / 36.
- Phase 3: 24 prompt contract tests / 551 assertions; corrected sync expectation test 1 / 8.
- Phase 4: 5 offline replay tests / 47 assertions.
- Phase 3 independent specification review: GO.
- Phase 3 independent regression review: GO.
- Paid Smoke: 6 pairs / 12 variants; 10 variants produced drafts, 2 Application Deep variants failed protocol validation, and 1 completed Application candidate was blocked by the grounding gate.
- Blind editorial result for the 5 complete pairs: control preferred in 3 pairs, candidate preferred in 2; mean editorial score was tied at 4.0/5, while the candidate had lower buyer-decision and non-template-naturalness averages.
- Paid Smoke release decision: NO-GO. The 12-target and 24-item paid gates were not started.
- Remediation regression: 233 related tests / 1487 assertions; changed-file Pint check passed after import formatting.
- Independent remediation review: initial NO-GO exposed two marker-repair P1 risks; the deterministic local replacement received GO with no remaining P0/P1.
- Application recheck: control single passed the deterministic gate, candidate single was blocked, and both pre-final-parser Deep variants failed marker protocol validation.
- Independent Application blind review rejected both generated single-turn articles; the control scored higher on factual support and structure, while the candidate contained the harder unsupported numeric and solution-detail expansion.
- V2.3.1 final zero-cost regression: 331 related tests / 2357 assertions; PHP lint and `git diff --check` passed. The suite includes non-retry termination, safe task-error categories, trace sanitization, queue ownership/recovery, revision-bound publication guards, admin/distribution pages, Prompt contracts, and offline replay.
- Independent PM and code re-review confirmed the provider-attempt ceiling, limited-evidence executable gate, revision invalidation paths, approval hash binding, insufficient-evidence terminal block, Prompt version fingerprint/downgrade protection, and trace version metadata. Final conclusion: GO with no remaining P0/P1 in the reviewed scope.
- V2.3.1 Prompt sync was dry-run only. Master and Application would move from business version `2.0.0` to candidate `2.3.1`; no conflicts were reported, `applied=false` was confirmed, and the final reviewed plan fingerprint was `cb970f5afc0d564a69d38781100547103e7ad1693465c7f8413010d70000d835`.
- Final bounded V2.3.1 paid gate: one Application pair, two Deep variants, four successful provider responses, and no article/task/publication/Prompt writes. Both variants failed their repaired plan validation before drafting: the V2.2 control put an unmapped fact in `article_angle`; the V2.3.1 candidate produced an invalid open-question contribution. Independent blind PM review found no article bodies to compare and returned `NO-GO`. Model usage moved from 183 to 187; token totals were unavailable because neither Deep result object completed.

Validation was deliberately bounded after the focused suites passed. No additional full-suite or repeated subagent loop is required for this local implementation state.

## Protected Boundaries

- Paid model activity was limited to the approved six-pair Smoke, bounded two-pair Application recheck, and the final one-pair V2.3.1 gate. The final gate enforced one process lock, interruption-safe no-auto-retry state, two variants, six provider attempts per variant, and a twelve-attempt ceiling. It recorded four successful provider responses and retained only safe failure classifications.
- No prompt apply to business data.
- The local Docker runtime now mounts this optimized source tree for development verification; this is not a Prompt apply, production deployment, or publication approval.
- No publish, deploy, queue worker, or remote distribution command.
- No private evaluation article text, customer record, API token, or PM blind-review note is stored in the sanitized replay fixture.
- All 54 explicitly tagged evaluation articles and their three empty categories were removed from the business database. The recheck wrote private files only; the database remains at 52 normal articles and zero evaluation articles.

## Paid Smoke Finding

1. Both Application Deep variants failed, but for different shared-protocol reasons:
   - control: `central_answer` carried an unmapped specific claim;
   - candidate: the draft contained a malformed evidence marker.
2. The Application single-turn candidate invented `+/-2 C` and `+/-0.5 C` tolerances that were absent from evidence. The deterministic `unsupported_numeric_unit` rule correctly blocked it.
3. The candidate did not demonstrate a release-worthy quality gain. It improved two pairwise preferences, lost three, and showed stronger evidence-boundary repetition and template stiffness in some Application and Comparison outputs.
4. All 10 generated articles remain private `draft` / `pending` records with `publication_allowed=false` for human comparison. A generated draft is not equivalent to a passed draft.

The shared Deep output protocol remediation is complete, but the Application candidate still does not qualify for release. Prompt-only numeric wording did not prevent sparse-evidence expansion in the earlier recheck. V2.3.1 added explicit evidence-sufficiency and fail-closed behavior, but the final paid gate failed before article drafting because both repaired plans violated the deterministic plan contract. The candidate's attempt to express uncertainty as an open question was directionally safer than unsupported expansion, but a protocol failure is not a release-quality result.

## Future Approval Gates

These are not part of the completed local implementation and require explicit approval:

1. Keep the business Prompt database unchanged and retain the current `NO-GO` release status.
2. Improve the plan contract without weakening factual validation: keep `article_angle` abstract and make verification questions satisfy a deterministic schema.
3. Complete zero-cost validator and replay coverage before requesting another paid retry with a new run ID.
4. Twelve targeted comparisons and the full 24-item blind review remain blocked until a bounded Application gate produces comparable, grounded outputs.
5. A separate Style matrix, prompt apply, production deployment, and publication remain out of scope.

## Operator Note

For fast zero-cost regression coverage, run only:

```bash
docker run --rm --network none --entrypoint php \
  -e APP_ENV=testing \
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -v "$PWD":/var/www/html -w /var/www/html geoflow-app:latest \
  artisan test tests/Unit/ArticleGroundingOfflineReplayTest.php
```
