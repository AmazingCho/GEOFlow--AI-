# Article Prompt Skills Phase 2 Evaluation

> Evaluation date: 2026-07-20
>
> Scope: source-controlled V2 candidate presets in `database/seeders/data/prompt_presets_v2.php` only. No database synchronization was performed.

## Result

Phase 2 passes its source-contract gate. The canonical Master and seven article Skills are industry-neutral, remain inside the agreed size ranges, contain no body H1 template or reserved unsupported placeholder, and preserve the Phase 1 runtime boundary.

| Component | Words | Combined with Master | Contract result |
|---|---:|---:|---|
| Master | 1,198 | - | Pass |
| Comparison | 487 | 1,685 | Pass |
| Buying Guide | 449 | 1,647 | Pass |
| Application | 465 | 1,663 | Pass |
| Technical | 457 | 1,655 | Pass |
| Troubleshooting | 607 | 1,805 | Pass |
| Case Study | 701 | 1,899 | Pass |
| Definition | 389 | 1,587 | Pass |

Word counts use the same Latin-token matcher as `PromptSkillContractTest`. They measure Prompt contract size, not requested article length.

## Contract checks

- Exactly seven canonical `type=skill` presets exist, with no duplicate display names or extras.
- Every Skill contains `Applies when`, `Do not use when`, `Reasoning approach`, `Evidence requirements`, `Optional modules`, and `Failure checks`.
- The Master owns source priority, evidence states, privacy, relationship evidence, unsupported claims, and anti-hype rules.
- Runtime remains responsible for title, keyword, retrieved context, target language, body-only Markdown, no body H1, and internal-link policy.
- Master and Skills contain no `# {{title}}`, `{{language}}`, `{{audience}}`, or `{{SkillPrompt}}` contract.
- Case Study distinguishes completed, in-progress, inquiry/application, and after-sales evidence states; it defaults to anonymization without explicit publication permission and hands executable diagnostic or repair guidance to the Troubleshooting safety boundary or a qualified technician.
- Troubleshooting separates safe operator checks from qualified-technician actions and includes isolation, pressure, temperature, PPE, stop, and escalation boundaries without inventing equipment-specific procedures.
- Existing public-preset industry-term scan passes.

## Manual review summary

- Comparison uses symmetric criteria and allows an inconclusive result when evidence is asymmetric.
- Buying Guide separates must-have, context-dependent, and preference criteria.
- Application starts from process requirements and does not turn hypothetical scenarios into customer cases.
- Technical is limited to mechanism questions and rejects invented components, values, and pseudo-technical explanations.
- Troubleshooting preserves uncertainty, privacy, and operator safety.
- Case Study prevents CRM probability, sales assessment, inquiry, or unfinished implementation from becoming a public success claim.
- Definition stays concise and routes mechanism or selection intent to the appropriate Skill.

## Database boundary

The live database remained at 18 Prompt records and 7 Skill records, with `max(updated_at) = 2026-07-01 23:09:11` after evaluation. The Seeder-consumed `prompt_presets.php` has no Git diff. Phase 2 did not run `PromptPresetSeeder` or update task foreign keys.

This separation is required because the production init flow can run database seeders automatically. Keeping V2 in the active preset file before Phase 3 would allow a container deployment or restart to overwrite administrator-edited Prompt records without a reviewed preview.

## Verification

```bash
KEY="$(docker exec geoflow-app sh -lc 'grep "^APP_KEY=" /var/www/html/.env | cut -d= -f2-')"
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test \
  tests/Unit/PromptSkillContractTest.php \
  tests/Feature/PromptPresetSeederTest.php \
  --stop-on-failure
```

Focused Phase 2 result: `20 passed / 562 assertions` for the V2 contract and Seeder boundary tests. The broader Prompt/runtime/task regression finished at `40 passed / 654 assertions`.

## Next gate

Phase 3 has now added preview/export/apply synchronization behavior. It preserves existing Prompt IDs and task/title-library foreign keys, requires a reviewed plan fingerprint, and blocks unresolved conflicts. No V2 candidate has been written to the live Prompt table; see `ARTICLE_PROMPT_SKILLS_PHASE3_GOVERNANCE_REPORT.md`.
