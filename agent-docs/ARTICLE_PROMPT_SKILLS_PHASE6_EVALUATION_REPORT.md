# Article Prompt Skills Phase 6 Evaluation Report

Date: 2026-07-20

## PM conclusion

Phase 6 technical tooling and the first approved real-model comparison are complete. The release decision remains **No-Go**: the run proved that routing and paired comparison work, but two automatic layout checks and five independent PM threshold checks failed.

The result is intentionally retained for manual review. It is evidence that some Skills improve decision structure, not evidence that every Skill output is publishable.

## Delivered scope

- A fixed 15-case catalog: two English cases for each of the seven controlled intents, one clear case and one boundary case per intent, plus one Chinese Master-only control. This exercises the two currently supported generation languages without claiming coverage for other locales.
- A deterministic offline fixture pinned to `offline-fixture-v1`, provider `deterministic_fixture`, temperature `0`, max output tokens `1800`, and `is_real_model=false`.
- A read-only command:

```bash
docker exec geoflow-app php artisan geoflow:article-skills:evaluate --json
```

- The command has no `--model` or `--generate` option and makes no network request. Real model output can only be imported through an explicitly prepared `--input` JSON file.
- Reports are stored under `storage/app/private/article-skill-evaluations/` with file mode `0600`. No download route or admin UI was added.

## Automatic checks

Each case records only output hash, length, check results, and non-sensitive routing metrics.

1. Expected intent and eligible/blocked routing state.
2. Estimated Master + Skill Prompt size, capped at 4,000 estimated tokens.
3. Target-language consistency.
4. No body H1.
5. Heading density.
6. No one-sentence sections.
7. No duplicate Introduction, Quick Answer, Key Takeaways, or Conclusion modules.
8. Case evidence/governance state.
9. Case restricted-term leakage.
10. Troubleshooting stop/isolation/escalation boundaries and forbidden unsafe advice.

The safety check rejects energized or pressurized cover removal, guard/interlock or emergency-stop bypass, and similar instructions even when the same text also contains generic stop or escalation words. Explicit prohibitions such as "never bypass the guard" are not treated as unsafe advice.

The report excludes Prompt bodies, RAG/source context, full generated output, provider secrets, restricted customer terms, reviewer names, raw external model/provider/version names, and free-form PM evidence notes. Output, model identity, reviewer identity, review notes, and paired-control content are represented only by SHA-256 hashes. External model metadata is also type-, length-, and format-validated before a report can be written, and raw model metadata is never used in the report filename.

## Offline result

- Cases: 15
- Fixture outputs: 15
- Routing passed: 15
- Automatic failures: 0
- PM reviews complete: no
- Release decision: `no_go`
- Blockers: `real_model_evaluation_required`, `pm_content_review_required`, and `manual_release_approval_required`

Generated local report:

`storage/app/private/article-skill-evaluations/20260720-213810-offline-fixture-v1-nsifgp7s.json`

The file is private (`0600`). A sensitive-output scan found no Prompt body, RAG/source text, full generated output, provider secret, restricted customer term, or free-form PM review note.

## Approved DeepSeek V4 Pro real-model result

- Model: local AI model record `deepseek-v4-pro`, temperature `0`, max output tokens `4096`.
- Successful requests: 25/25; total usage: 124,073 tokens (54,068 prompt and 70,005 completion).
- Evaluation outputs: 15; validated Master-only pairs: 10.
- Retained comparison drafts: 20 articles, IDs `61-80`, category `Prompt Skill Phase 6 Evaluation`.
- All retained articles are `draft` + `pending`, with no task, Collection, publish timestamp, or distribution record.
- Automatic result: all 15 routes passed; `comparison-boundary` and `application-clear` failed the one-sentence-section check.
- Independent PM review: complete. Five cases failed at least one threshold: `comparison-boundary`, `buying-guide-boundary`, `application-clear`, `troubleshooting-clear`, and `case-study-clear`.
- Paired improvement score: average `3.2/5`; four cases scored 4, four scored 3, and two scored 2. No universal improvement claim is supported.
- Final blockers: `pm_score_threshold_failed`, `automatic_checks_failed`, `external_input_provenance_unverified`, and `manual_release_approval_required`.

Private reviewed report:

`storage/app/private/article-skill-evaluations/20260720-223223-external-real-model-bysdszck.json`

The first 1,800-token attempt was stopped after an empty completion exposed an insufficient output ceiling. It created no articles. The successful run used a new run ID and did not mix partial outputs into the final comparison.

## Regression and UI verification

- Phase 6 focused tests after two independent security/risk review passes: 19 passed / 115 assertions.
- Full Laravel suite before the final evaluator-only risk hardening: 549 passed / 4247 assertions, with only two pre-existing copy failures outside this phase (`AdminWelcomeIntroCopyTest` and `AdminMaterialsPagesTest`). The final hardening was then covered by the 18-test focused suite above; it does not touch runtime generation or UI code.
- Browser checks covered task create/edit, Prompt management, and article Generation Source/quality views at 1440x1000 and 390x844.
- No page-level horizontal overflow, raw `admin.*` translation key, browser console error, or incoherent overlap was observed.
- No new Phase 6 admin screen was added: this phase deliberately remains an operator command plus private report, avoiding a release button that could imply automatic approval.

## PM review contract

Every real output must receive a 0-5 score for factual support, clarity, buyer decision value, structure naturalness, uncertainty and negative-fit handling, and privacy and safety. `improvement_over_master_only` is required only for an Auto-eligible case with a validated paired control; it is not applicable to Case Study, Troubleshooting, the Master-only control, or any case without a valid pair.

All dimensions must be at least 3. Case Study and Troubleshooting privacy/safety must be at least 4. Reviewer identity and an evidence note are required; the report stores only the evidence-note hash.

## Why 15 fixtures are not enough for an improvement claim

The 15-case set validates routing and automated checks. To claim that Master + Skill improves content, the ten Auto-eligible clear/boundary cases must also be generated with the same title, language, context, model, and settings in Master-only mode.

That means the first real comparison requires at least 15 Skill/routing outputs plus 10 paired Master-only controls. The evaluator counts a pair only when both contents are supplied, the Skill content exactly matches the evaluated output, and the pair includes pinned SHA-256 identifiers for the shared context and model configuration. A number written in model metadata cannot satisfy this gate. Imported JSON always receives `external_input_provenance_unverified`, because a self-declared file cannot prove who generated or reviewed its contents. The tool still cannot issue a final Go automatically because final release approval remains a human decision.

## Protected boundaries

- No AI provider or paid API is called by default or in CI.
- The approved eight-preset V2 evaluation scope was synchronized in place after a private backup. Existing Prompt IDs and task references were preserved; unrelated presets were not changed.
- Case Study and Troubleshooting Auto remain blocked by their Phase 5 governance gates.
- No Worker, RAG, image, task, publication, or distribution behavior was changed.
- Offline success must never be described as real-model content approval.
- Troubleshooting keyword/phrase detection is only a screening layer. It cannot certify operational safety; Case Study and Troubleshooting still require their existing human governance gates.

## Next manual gate

Do not release the V2 set as an approved default yet. First correct the five PM failure cases and the two layout failures, rerun only the affected fixed cases with the same pinned configuration, then repeat independent review. The 20 current drafts remain available for human side-by-side inspection.

## Targeted correction - 2026-07-21

The first corrective implementation is complete in source and remains **No-Go pending a paid real-model rerun**.

- Master Prompt `2.1.0` now uses a closed-world evidence rule and an internal claim inventory for product-, application-, and project-specific statements. Plausible general knowledge may explain a basic concept but may not supply missing process stages, components, effects, thresholds, configurations, or service actions.
- Comparison, Buying Guide, Application, Troubleshooting, Case Study, and Definition Skills were raised to `2.1.0` with failure-specific boundaries from the first review. Technical remains `2.0.0` because its paired cases met the threshold and the stronger Master rule applies to it.
- Runtime now tells the model to shorten earlier sections instead of starting content it cannot finish.
- A response ending because of token length, an unclosed code fence, an unfinished sentence, a dangling heading, or an empty Markdown item is rejected before article persistence. A complete final list item with sentence punctuation remains valid.
- A rejected but completed provider call still increments model usage counters. In smart-failover mode the existing model-selection flow may try the next model; fixed-model tasks fail with an actionable message instead of saving a broken draft.
- The active Docker business database received a read-only seven-preset preview only. All seven entries were classified as safe `update`; V2.1 was not applied.
- No paid model request was made during this correction.

Verification completed in an isolated container bound to this project copy:

- Core Prompt/runtime correction: 39 tests / 487 assertions, including truncated-primary to complete-fallback model switching.
- Preset sync, Seeder, Auto routing, generation trace, evaluator, and recommendation regression: 73 tests / 564 assertions.

## V2.1 frozen baseline for the de-templated deep-generation work

The next optimization started on 2026-07-21 with the current V2.1 candidate frozen as the comparison and rollback baseline. No paid request, business-database apply, or Docker mount change was made while recording it.

| Preset | Version | Normalized content SHA-256 |
|---|---:|---|
| `article.master.trust_based` | 2.1.0 | `b44b26226ea0d91c579fd06e1e9038d6ef76c1558f04e3ce4eb53b2f930c6cf5` |
| `article.skill.comparison` | 2.1.0 | `361258c48f06920852f19eabd5350d83e7fbbe5606184795a00ade53491d2595` |
| `article.skill.buying_guide` | 2.1.0 | `33aba339a8ee259b74686c32585d897846a1d8ffc7b7d8a36bfa0f41c261c98b` |
| `article.skill.application` | 2.1.0 | `1cae01f8e49f542fadfe4b3ce6b52aba106f3396a6512c26ce77d5072b1edad7` |
| `article.skill.technical` | 2.0.0 | `fd72c2a5bb33660bab9b47a5a151a7a82a1cdf48fece312f207585b3e7ca9635` |
| `article.skill.troubleshooting` | 2.1.0 | `e7c56df2732ce5d4e7ace7ad0f096838e9756332cb34cf3131d2745107198c0b` |
| `article.skill.case_study` | 2.1.0 | `caf2aeff690f2dde22d6243022ca8ff6162357afdb4560999f6cc3948be4d590` |
| `article.skill.definition` | 2.1.0 | `8e1cbeae4c9dc405c5d32cbd41e8a37ca91302addc13f94a1b652afdfb4dd6d2` |

The retained real-model articles and the private reviewed report above remain the content baseline. V2.2 must beat this baseline on structure naturalness and non-template variety without lowering factual, privacy, or safety scores.

## V2.2 local implementation result

The de-templated V2.2 candidate and the Standard/Deep generation implementation are complete in local source. This does not change the release decision: V2.2 remains **No-Go** until the controlled real-model comparison and PM blind review are completed.

- Master and all seven Skills now use content-driven structure and no longer require universal FAQ, table, Key Takeaways, or Conclusion modules.
- Four optional Style candidates are available in source without being applied to the business database.
- Deep mode freezes one evidence package, runs plan/draft/review, performs at most one revision, and caps generation at five model calls.
- Deep review feeds the article quality panel without inflating the deterministic score; Case Study and Troubleshooting always retain human governance.
- Anti-template checks now cover heading skeletons, opening patterns, generic modules, paragraph fragmentation, section information gain, Style fitness, and Style boundary violations.
- Desktop and mobile task/article views passed visual inspection. The full Laravel suite reached 586 passes / 4509 assertions, with two unrelated historical copy-baseline failures documented separately.
- An isolated PostgreSQL dry-run applied nothing and found a local `article.style.technical_clarity` conflict. The business database must receive a new preview before any approved apply.

See `agent-docs/ARTICLE_DETEMPLATED_DEEP_GENERATION_IMPLEMENTATION_REPORT.md` for the exact delivered scope, validation evidence, and remaining release gates.
