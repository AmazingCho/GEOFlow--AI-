# Article Deep Protocol V2.4 Candidate Manifest

Date: 2026-07-22

## Candidate Identity

- Candidate ID: `deep-v2.4-p0-candidate-20260722`.
- Protocol version: `deep-v2.4-structured-plan-1`.
- Intended immutable Git tag: `deep-protocol-v2.4-p0-candidate-20260722`.
- Baseline checkpoint: `756a7e5`.
- The annotated tag target is the authoritative source commit. Do not run a paid canary from an uncommitted working tree or a different commit under the same protocol version.

## Frozen Scope

This candidate contains Deep Protocol V2.4 Phases 0-5 plus the zero-cost P0 hardening pass:

- structured plan and review agents;
- Plan V2 aggregate validation and one bounded repair;
- six explicit generation outcomes;
- deterministic content blockers without queue retry;
- typed provider/model failures with safe attempt accounting;
- provider-attempt budget enforcement;
- trace sanitization, publication safeguards, and minimal task-list feedback.

This candidate does not include a paid Phase 6 run, Prompt preset apply, article publication, distribution, or an editorial-quality claim.

## Source Fingerprints

The source diff SHA-256 below is the expected commit-to-commit result of `git diff --binary 756a7e5 deep-protocol-v2.4-p0-candidate-20260722 -- app config database lang resources tests` and must be rechecked after tagging:

`a75e3627bdc951a98963e8606709610818143bf5c3a5be2f1f3c6030f0f94f15`

Key frozen files:

| File | SHA-256 |
| --- | --- |
| `database/seeders/data/prompt_presets_v2.php` | `1fc95843c935ddbfa5853ef39efd808e4c977cab6a19f2375709f2e52fc47742` |
| `tests/Fixtures/article-grounding/prompt-v22-baseline-hashes.php` | `577d71da21990949bfa80be5344f3094fa9719caaeaffc699af4e35d6ad38eda` |
| `tests/Fixtures/article-deep-protocol-v2/offline-matrix.php` | `4a78f92854ba1581b3e6db7d642c98029da56fdd9ab9b13f79dfea254283af4c` |
| `app/Services/GeoFlow/DeepArticleGenerationService.php` | `b67602488a755fa7a16780b7b730e6c196a5aef0fbeda2c14ad8a45dc3b628fa` |
| `app/Services/GeoFlow/ArticleDeepOutputValidator.php` | `aa883fac10f5ebbe7ac2cf04142601a3d4aed1774d3dee8549e3a6b65862eeb0` |
| `app/Services/GeoFlow/ArticleDeepPromptBuilder.php` | `b38d0f0d79a2f999cf0c40086f32f17e1defd8565296bf05a67e6b37e64e49f0` |
| `app/Services/GeoFlow/ArticleModelCallService.php` | `e0b1afbad544c15cd74efd7f78c9408dff7edd47b5607efee899355ea1d93bc0` |
| `app/Ai/Agents/ArticlePlanAgent.php` | `30f2b13de9a889f848d159ada404b447ae32a1b0d0ffc1f01c6a528a2dbff5ba` |
| `app/Ai/Agents/ArticleReviewAgent.php` | `e24b8b6bd3795f7bb1789581838c82d6489dbae9011456909b8a61b0a88b5357` |
| `app/Ai/Agents/MarkdownContentWriterAgent.php` | `68916351699ecf6f835cc2577b14c7ef79e03bfb5f5126ece8151e8e8588b2fa` |

## Sanitized Model Contract

The local approved model record was inspected without reading or recording its API key or endpoint:

- model identifier: `deepseek-v4-pro`;
- model type: `chat`;
- status at freeze time: `active`;
- Phase 6 selection mode: `fixed`;
- plan, plan repair, review, and final review maximum output: `2048` tokens;
- draft and revision effective maximum output at freeze time: `8192` tokens because the model row has no override and `GEOFLOW_CONTENT_MAX_TOKENS` resolves to `8192`;
- runtime temperature: `provider_default_unset`;
- maximum provider attempts in one Deep run: `6`.

Sanitized configuration SHA-256:

`c0e9e326a12962018a288042e03091ac27007ba82f9a0f0deee5d8f321de0d41`

API keys, provider endpoints, private prompts, evidence text, and generated article bodies are deliberately excluded. Immediately before a paid run, the operator must verify that the selected model and these non-secret limits still match this manifest.

## Offline Qualification

- Offline protocol matrix: `30/30` expected outcomes.
- Matrix composition: 10 sufficient, 10 limited, and 10 insufficient cases in English and Chinese.
- Focused P0 regression: `141 tests / 757 assertions` passed.
- Full Laravel regression: `873 passed / 5823 assertions`; two unrelated pre-existing copy-baseline tests remain failed.
- PHP/Blade lint, Pint on the changed PHP set, and `git diff --check` passed.
- Desktop and mobile task-outcome screenshots were reviewed locally and remain ignored test output rather than tracked release assets.
- No paid or external model request was made during qualification.

The two known full-suite failures are:

1. `Tests\Unit\AdminWelcomeIntroCopyTest` expects the former welcome-title copy.
2. `Tests\Feature\AdminMaterialsPagesTest` expects the removed author entry on the foundation-materials page.

Neither source/test pair belongs to this candidate diff.

## Paid Canary Gate

Phase 6 remains locked until the user gives a separate explicit approval. A valid run must:

1. resolve this exact Git tag and confirm a clean working tree;
2. re-check every frozen hash and the sanitized model contract;
3. use exactly three sanitized cases: sufficient, limited, and insufficient;
4. enforce the six-provider-attempt ceiling across the complete Deep run;
5. avoid Prompt apply, publication, distribution, and unrelated database writes;
6. stop the protocol version after any repeated protocol failure instead of looping paid retries.

Passing this gate demonstrates protocol operability only. Article quality requires the separate Phase 7 blinded evaluation.
