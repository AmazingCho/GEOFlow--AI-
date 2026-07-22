# Article Prompt Skills Phase 3 Governance Report

Date: 2026-07-20

## Outcome

Phase 3 is implemented as a controlled upgrade tool. It does not change generation routing, hide legacy Masters, or automatically apply the V2 candidate package.

The local business database received only the nullable Prompt governance columns and the one-time bootstrap installation marker. The marker is recorded as `active-v1 / legacy-existing`, so later production seeding cannot mistake this established database for a fresh install. The V2 synchronization command was run in dry-run mode, leaving all 18 existing Prompt records unchanged and without governance keys.

## Implemented Safety Contract

- Stable preset identity uses `preset_key`; names are display labels and one-time legacy match inputs.
- The synchronized hash covers `content + variables`, not the display name.
- Seeder consumes only the active V1 catalog and never consumes `prompt_presets_v2.php`.
- Seeder recognizes verified historical SQLite/PostgreSQL defaults only once on a provably pristine installation. `prompt_preset_installations` records completion, so later seeds cannot recreate deleted defaults. An existing business database receives zero Prompt writes from normal `db:seed`.
- New installations intentionally start from the safe V1 baseline and must explicitly preview/apply V2.
- Default synchronization is read-only and reports `create`, `rename`, `update`, `unchanged`, `conflict`, and `skip`.
- Apply requires the exact `plan_fingerprint` from the reviewed preview.
- Preview and apply can be limited with repeated `--preset` options, and apply uses a shared cross-process lock.
- An unresolved conflict aborts the complete apply; there is no partial synchronization.
- Per-record resolutions are limited to `keep-local` and `use-preset`.
- `keep-local` does not advance `last_synced_hash`, so the customization remains visible in later previews.
- A later production seed cannot downgrade an already synchronized newer preset or re-enable a disabled one.
- Apply updates existing Prompt rows in place. Prompt IDs and task/title-library references are preserved.
- Backup is written before mutation inside the transaction and includes Prompts, task Prompt mappings, title-library Prompt mappings, and the reviewed manifest.
- Backups use the private local disk under `storage/app/private/prompt-preset-backups/`, remain outside Git, and use restricted permissions.

## Verification

- Focused prompt governance and source-contract suite: 37 tests, 686 assertions.
- Runtime integration suite with injected test `APP_KEY`: 53 tests, 784 assertions.
- Full repository suite: 503 passed, 2 known unrelated assertions failed, 3989 assertions total. The remaining failures are the stale welcome-intro title expectation and the materials-page expectation for the removed `author_manage_title` entry; neither touches Prompt governance or generation routing.
- Docker PostgreSQL isolated database:
  - all migrations completed, including nullable unique `preset_key`;
  - fresh seed safely migrated the PostgreSQL legacy defaults;
  - preview had no unresolved conflicts;
  - apply produced four private backup files;
  - 12 governed keys were unique and marked system-owned;
  - second preview reported 12 `unchanged` actions;
  - a subsequent production-style `db:seed` left all 12 V2 records byte-for-byte unchanged;
  - isolated validation database and generated test backup were removed afterward.

## Local Dry-Run Result

Plan fingerprint at inspection time: `ab6d0df9f467de067c11aed464aa00611e42950506d3b2472ddfbf8312128d0e`.

- Conflicts: 10
- Safe updates: 2
- Creates: 0
- Database writes by preview: 0
- Backups created by preview: 0
- Existing Prompt records: 18
- Governed Prompt records after preview: 0
- Bootstrap installation marker: `active-v1 / legacy-existing`

The fingerprint is not an authorization token and may become stale as soon as a Prompt changes. Run a new preview before any future apply.

## Product Decisions Preserved

- Legacy language/ranking Masters remain visible and selectable.
- `is_enabled` is reserved but does not yet filter admin lists or task selectors.
- Existing administrator Prompt bodies are treated as conflicts rather than assumed obsolete.
- Phase 4 intent metadata and seven-intent routing remain separate work.
- Case Study and Troubleshooting runtime evidence gates are not claimed by this governance phase.

## Next Decision Gate

Before applying V2 to the local database, review each of the 10 conflicts and decide separately whether to keep the local body or use the packaged V2 body. Re-run preview, capture the current fingerprint, and use explicit resolutions for every conflict. Do not copy the inspection fingerprint above into an apply command later.
