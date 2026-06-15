# CRM Activity, Contact, and Opportunity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix optional contact creation, make historical CRM activities editable and visibly deletable, and remove duplicated next-step entry from opportunities.

**Architecture:** Keep the existing `crm_follow_ups`, `crm_tasks`, and `crm_opportunities` tables for backward compatibility. Normalize optional contact strings at the controller boundary, expose one shared activity update route, and treat CRM tasks as the single source of truth for future opportunity actions.

**Tech Stack:** Laravel, Eloquent, Blade, Tailwind CSS, PostgreSQL, PHPUnit feature tests.

---

### Task 1: Contact Optional Fields

**Files:**
- Modify: `tests/Feature/AdminCrmPagesTest.php`
- Modify: `app/Http/Controllers/Admin/CrmContactController.php`

- [x] Add a failing feature test that creates a contact with only `name` and expects optional string columns to persist as empty strings.
- [x] Run the focused test and confirm the current PostgreSQL not-null failure.
- [x] Normalize nullable validated strings before create/update.
- [x] Run the focused test and confirm it passes.

### Task 2: Activity Editing and Deletion

**Files:**
- Modify: `tests/Feature/AdminCrmPagesTest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Admin/CrmInquiryController.php`
- Modify: `resources/views/admin/crm/partials/_follow-up-item.blade.php`
- Modify: `resources/views/admin/crm/customers/show.blade.php`
- Modify: `resources/views/admin/crm/inquiries/show.blade.php`

- [x] Add a failing feature test for activity update and soft deletion.
- [x] Add a shared activity update route and validated update action.
- [x] Add an inline edit form using the shared lightweight Markdown editor.
- [x] Keep edit controls limited to customer and inquiry detail pages; other timelines remain read-only.
- [x] Make edit/delete controls visible without relying on hover.
- [x] Run focused and full CRM tests.

### Task 3: Opportunity Next Action Source

**Files:**
- Modify: `tests/Feature/AdminCrmPagesTest.php`
- Modify: `app/Http/Controllers/Admin/CrmOpportunityController.php`
- Modify: `resources/views/admin/crm/opportunities/form.blade.php`

- [x] Add a failing test proving opportunities no longer render manual next-step inputs and show the first open CRM task.
- [x] Remove `next_step` and `next_step_at` from active form validation and inquiry conversion payloads while retaining database columns for compatibility.
- [x] Order opportunity tasks by due date and show the first unfinished task as the current next action.
- [x] Keep the existing task creation and task list UI as the editing surface.
- [x] Run CRM regression tests and visually inspect customer, inquiry, and opportunity pages.

### Task 4: Documentation and Verification

**Files:**
- Modify: `docs/CHANGELOG.md`
- Modify: `docs/CHANGELOG_en.md`
- Modify: `agent-docs/IMPLEMENTATION_STATUS.md`
- Modify: `agent-docs/KNOWN_ISSUES.md`

- [x] Document the contact root cause and the activity/task/opportunity responsibility boundary.
- [x] Run PHP lint for changed PHP and Blade files.
- [x] Run `AdminCrmPagesTest` with the current Docker `APP_KEY`.
- [x] Clear Laravel and compiled Blade caches.
- [x] Verify the changed pages in the local browser at desktop width.
