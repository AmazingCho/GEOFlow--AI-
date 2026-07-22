# Article V2.3 Grounding And Governance Design

## 1. Business Goal

V2.3 does not add another article template. It fixes the reliability chain beneath the existing Standard/Deep and Master/Skill/Style system so that:

- specific claims can be traced to a known evidence item;
- unsupported or unsafe content cannot be declared "passed" by the same model that wrote it;
- evaluation reports compare like with like;
- prompt corrections remain narrow and do not strengthen template-like writing;
- no real-model spend, prompt application, Docker source switch, publishing, or production deployment occurs without separate approval.

## 2. Confirmed Problems

1. The Phase 8 corpus contains V2.1 controls, V2.2 candidates, Style variants, and repeated titles. One combined average is not a valid release metric.
2. The 24 Phase 8 articles were direct single-turn prompt calls. They do not prove the real Deep `plan -> draft -> review -> revision` workflow.
3. Deep plans accept arbitrary `evidence_refs`; the validator checks only that strings are present, not that the referenced source exists.
4. Deep review can omit metrics, use unrecognized issue codes, or return `passed=true` with weak factual support.
5. The writer and reviewer use the same model, so model review is advisory rather than a deterministic safety boundary.
6. A single troubleshooting control failure was previously attributed to the V2.2 candidate even though the V2.2 troubleshooting article improved.
7. Application, Case Study, and Comparison show the clearest factual or structural weakness. Style prompts and Troubleshooting do not have enough evidence to justify rewriting.

## 3. Product Boundaries

### In scope

- Evaluation governance and release-gate correction.
- Stable evidence identifiers for knowledge chunks, knowledge-base fallback content, Entity, and Case sources.
- Validation that plan references point to retrieved evidence.
- A deterministic factual/safety screening result with `pass`, `pending_review`, and `blocked` outcomes.
- Hard blocking in Deep generation and evaluation for high-confidence violations.
- Diagnostic recording only for Standard generation in this version, preserving existing behavior.
- Minimal corrections to Master plus Application, Case Study, and Comparison prompts.
- Sanitized offline regression fixtures and tests.

### Out of scope without a new approval

- Paid model calls or a new 6/12/24 article run.
- Applying candidate prompts to business data.
- Changing the active Docker bind mount or deployment source.
- Publishing or distributing generated articles.
- Rewriting Style prompts, Troubleshooting, or unrelated Skills.
- A universal hard gate for Standard mode.
- Database migrations solely for this correction.

## 4. Evaluation Governance

Release reports use `threshold_version=article-v2.3-rubric-1` and must separate and validate explicit cohort metadata.

The eight required metric keys are `factual_support`, `clarity`, `buyer_decision_value`, `structure_naturalness`, `uncertainty_and_negative_fit`, `privacy_and_safety`, `style_fitness`, and `non_template_naturalness`.

- every artifact has a unique `artifact_id`, `cohort` (`candidate`, `control`, or `style`), and `workflow_mode` (`single_turn` or `deep_pipeline`);
- `pair_key` is mandatory for candidate/control artifacts; each pair must contain exactly one candidate and one control. An unpaired candidate is invalid rather than silently excluded;
- Style artifacts use `style_matrix_key` and are never inserted into a candidate/control pair or core release average;
- missing scores, scores outside 1-5, duplicate artifact IDs, duplicate cohort members within a pair, and incomplete candidate/control pairs make the report invalid and `no_go`;
- repeated titles are allowed only as distinct artifacts with an unambiguous `pair_key`;
- **Candidate absolute gate:** only candidate outputs. Every candidate requires all eight rubric scores at least 3, plus `factual_support >= 4`, `structure_naturalness >= 4`, `non_template_naturalness >= 4`, and `privacy_and_safety >= 4`;
- **Version-pair relative gate:** candidate `factual_support` and `privacy_and_safety` must not be lower than its matched control. Controls do not participate in candidate averages or absolute gates;
- **Style diagnostic:** Style recognition is reported independently. It cannot release or reject the core candidate prompt.
- **Deep workflow gate:** a report may claim Deep validation only when every core artifact records `workflow_mode=deep_pipeline` and contains plan, draft, and review stages. When the first review passes, that `deep_review` is the final review. When a revision occurs, `deep_revision` and `deep_final_review` are both mandatory.

The reusable report schema must include cohort counts, cohort-specific averages, threshold version, workflow mode, and explicit blockers. A completed blind review must not remain marked pending in its manifest.

## 5. Evidence Contract

The RAG result remains backward compatible (`context` and `trace`) and gains a normalized evidence package.

Stable IDs for the current source revision:

- `KB:{knowledge_base_id}:CHUNK:{chunk_index}:{content_hash_16}` for retrieved chunks.
- `KB:{knowledge_base_id}:FULL:{content_hash_16}` for fallback whole-document content.
- `ENTITY:{entity_id}:{content_hash_16}` for Entity context.
- `CASE:{case_id}:{content_hash_16}` for Case context.

An ID deliberately changes after source content changes. This prevents a new source revision from inheriting an old claim reference.

The in-memory evidence item contains only the fields needed by generation:

- `id`, `source_type`, `source_id`, `label`, `content`;
- `source_state` (`available`, `unverified`, or `restricted`);
- `publication_scope` (`internal_reference`, `restricted`, or `unknown`);
- optional retrieval metadata already present in the trace.

V2.3 adds no database classification fields. Conservative runtime defaults are therefore explicit:

- a Knowledge chunk/full item is `available/internal_reference` only when its Knowledge Base is not archived, is active or has a null legacy status, and its selected content is non-empty;
- an Entity item is `available/internal_reference` only when its selected record exists and its rendered evidence content is non-empty;
- every Case item is `unverified/unknown` because the current schema has no publication approval or anonymization field, and therefore cannot activate Case Study generation;
- archived knowledge is excluded by the existing retrieval rules;
- V2.3 does not claim that a persisted source was independently fact-checked or approved for public quotation.

Full evidence content is not persisted by GEOFlow. During generation it exists in the in-memory package and frozen prompt sent to the AI provider configured for the task, so that provider is part of the trusted processing boundary. It must never enter `TaskRun.meta`, `generation_trace`, GEOFlow API response payloads, tracked reports, logs, or exception messages. Persisted audit metadata contains IDs, source types/IDs, state/scope, and content hashes only.

The title, keyword, writing brief, plan, and Style prompt are instructions or context, never evidence.

Plan and article contract changes:

- schema v2 writes `section_goals[].contribution` so it describes the section's job rather than prescribing a heading; the validator dual-reads legacy `heading_intent` but canonical output and trace use `contribution`;
- evidence sections must cite valid evidence IDs;
- every `evidence_mapping` item must contain at least one valid evidence ID;
- `general_explanation` may explain concepts but may not carry product-specific numbers, customer outcomes, guarantees, or named factual assertions;
- empty or unknown references fail validation before drafting.

Deep draft and revision outputs place an invisible marker after each paragraph that makes a specific product, customer, capability, outcome, or numeric claim: `<!-- evidence:ID[,ID] -->`. The service validates marker IDs against the frozen package, builds a claim ledger, removes the markers before persistence, and persists only paragraph hashes plus evidence IDs.

The ledger includes `coverage_status=complete|partial|not_applicable`. A deterministic detector checks at least number-with-unit claims, selected Entity/Case/model names, and outcome/capability phrases. A detected specific-claim paragraph without a valid marker makes coverage `partial` and forces manual review; it can never be reported as complete. This provides paragraph-level provenance for model-declared and deterministically detected claim candidates, not universal automated fact verification.

## 6. Deterministic Gate

The deterministic checker is a conservative screening layer, not a claim that regex can verify all facts.

Outcomes:

- `pass`: no deterministic issue found;
- `pending_review`: ambiguous or lower-confidence unsupported content;
- `blocked`: high-confidence privacy exposure, dangerous operational advice, or a specific number-with-unit claim absent from the evidence package.

Rules must be evidence-aware and negation-aware. Dates, ordered-list numbers, model identifiers, evidence-supported values, and harmless numbering must not be blocked merely for containing digits.

Deep behavior:

- run after draft and after revision;
- a blocked result cannot be saved;
- pending results require manual review;
- model review must contain all eight metrics;
- `factual_support < 4` or `privacy_and_safety < 4` cannot pass;
- deterministic blockers override model review.

Standard behavior in V2.3:

- evaluate and record the screening result in the generation trace;
- force `review_status=pending` for deterministic pending/blocked findings;
- do not silently reject the draft yet.

Publication boundary:

- every admin, API, worker, batch, and distribution enqueue path must require `review_status` to be `approved` or `auto_approved`;
- distribution processing and retry paths re-check approval immediately before sending; if approval was revoked after enqueue, the job is cancelled/failed-safe without a remote request;
- deterministic pending/blocked Standard drafts stay unpublished until a human explicitly resolves the review;
- the distribution layer repeats the guard so a caller cannot bypass the controller;
- manual articles without a generation trace keep their existing approval workflow.

## 7. Prompt Correction

Only the demonstrated failure areas are changed:

- **Application:** separate verified operating facts from conditional selection guidance; do not invent suitability, capacity, environmental, or process constraints.
- **Case Study:** use only verified/anonymized/publication-approved Case evidence; omit unsupported project detail, customer identity, metrics, and outcome certainty.
- **Comparison:** compare only dimensions supported for both sides; label unknowns and avoid filling symmetry gaps.
- **Master:** one concise grounding rule that specific facts require evidence and missing facts must stay unknown.

No fixed section count, mandatory FAQ, mandatory table, forced conclusion, or repeated heading skeleton is introduced. Styles remain optional and unchanged.

## 8. Offline Replay

Tracked fixtures are synthetic and sanitized. They cover:

- valid and invalid evidence IDs;
- evidence-supported and unsupported number-with-unit claims;
- model numbers, dates, and list numbering as positive controls;
- prohibited unsafe instructions and explicit negated warnings;
- privacy markers;
- candidate/control cohort separation;
- required Deep review metrics;
- dynamic plan contributions that may be merged or omitted.

Private Phase 8 articles and PM notes remain under `storage/app/private`, mode `0600`, and are never copied into tracked fixtures or reports.

## 9. Offline Execution Safety

All implementation and replay commands run in an ephemeral container with `--network none`, `APP_ENV=testing`, a test-only `APP_KEY`, and the isolated test database. Laravel HTTP tests use `Http::preventStrayRequests()` or an equivalent fake. The allowed commands are focused `artisan test`, offline report recomputation, read-only inspection, and file-permission checks. Commands that invoke model generation, prompt apply/sync without dry-run, queue workers, publishing, distribution, Docker Compose source switching, Git push, or deployment are prohibited in this run.

## 10. Acceptance Criteria

1. No paid API request, prompt apply, Docker source switch, publish, or deploy is performed.
2. Candidate and control results are reported separately; candidate factual support requires 4/5 for every candidate.
3. A Deep plan with an unknown or empty evidence reference is rejected.
4. A Deep review missing any required metric is rejected; factual support below 4 cannot pass.
5. Full evidence text never appears in persisted trace/API output; only IDs and hashes are retained.
6. High-confidence unsafe/privacy/unsupported numeric claims block Deep persistence.
7. Ambiguous findings become pending review rather than false certainty.
8. No publish or distribution path accepts a pending/rejected article; Standard findings require explicit human approval.
9. Existing Standard generation remains operational and only receives trace/review-status governance in this release.
10. Only Master, Application, Case Study, and Comparison candidate prompts change.
11. All new behavior has focused tests plus the existing related regression suite.
12. A fresh specification reviewer and a separate regression reviewer approve every phase.
13. A canary evidence string is absent from `TaskRun.meta`, admin/API payloads, logs, exception messages, and tracked artifacts.
14. Approval revoked after distribution enqueue prevents processing and retry from making a remote request.
15. Existing manually authored articles still follow the normal review workflow and are not rejected merely because they lack a generation trace.
