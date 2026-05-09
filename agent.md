# Agent Handoff Notes

This file is for agents working on this repository. Read it first when taking over the task, and keep it updated so context is not lost when work moves between agents.

## Update Log

Add every new update at the top of this section, newest first. Keep entries concise but specific enough for the next agent to continue without rediscovering the same context. Include decisions, code changes, tests run, blockers, and next actions. Do not delete older entries unless they are clearly wrong, and if you correct one, add a new note explaining the correction.

- 2026-05-09 13:54 EDT - (codex independent) Added an independent third-party test suite under `tests/codex_independent_test` without reading or editing `agent.md`. Created 300 tests total: 120 PHP helper/DB tests, 120 Python HTTP black-box tests with a temporary PHP server, and 60 Shell/SQLite/static tests, separated into regular, extreme, unexpected, and customer fool-behavior groups. Re-ran existing tests sequentially: `php tests/test.php` passed 5/5; `php tests/Codex_test/extreme_php_tests.php` passed 20/20; `php tests/Codex_test/extreme2_php_tests.php` passed 49/50 with only accented uppercase title search failing; `python3 tests/Codex_test/extreme2_http_tests.py` passed 29/30 with the same accented uppercase search failure. New independent tests: PHP passed 118/120, Python HTTP passed 116/120, CLI/SQLite/static passed 52/60, total 286/300. Main findings: Unicode/accented case-insensitive search is incomplete, document creation audit logs the default current staff instead of the actual creator staff ID, recipient share links are reusable despite the one-time-link product description, unknown share actions create links instead of being rejected, and request parameter scalar hardening is incomplete. Re-ran `php seed.php`; current sample token is `09e0f909bf76ad36b18f8ddbaff46f20`, current seeded readable ID is `welcome-packet-b879`.
- 2026-05-09 13:30 EDT - Implemented the three approved fixes from the `extreme2` review. `create_document()` now validates blank/whitespace title and body at the helper layer while preserving the original nonblank body content. Added `normalize_published_at()` so `create_document()` and `update_document_schedule()` only accept `NULL`, blank-as-NULL, or `YYYY-MM-DD HH:MM:SS` storage strings. `recipient_document_for_token()` now trims copied token whitespace and returns null for empty tokens. Verification run sequentially to avoid `db.sqlite` seed races: `php -l lib/bootstrap.php` passed; `php tests/test.php` passed 5/5; `php tests/Codex_test/extreme_php_tests.php` passed 20/20; `php tests/Codex_test/extreme2_php_tests.php` passed 49/50 with only the user-deferred accented uppercase search case failing; `python3 tests/Codex_test/extreme2_http_tests.py` passed 29/30 with the same deferred search case failing. Re-ran `php seed.php`; current sample token is `7e75b763dd21770d85ac70103bd75a34`, current seeded readable ID is `welcome-packet-734c`.
- 2026-05-09 13:25 EDT - Reviewed the four issue categories from the 9 `extreme2` failures. Recommendation: fix helper-level document validation, helper-level schedule validation, and token whitespace trimming because they are data integrity/user-input issues with small, contained fixes. Decision from the user: do not fix accented/non-ASCII uppercase title search for now; keep it as a known tradeoff/future improvement.
- 2026-05-09 13:22 EDT - Added the requested `extreme2` tests: `tests/Codex_test/extreme2_php_tests.php` has 50 executable PHP tests, with tests 01-30 focused on the new scheduled publishing, readable ID, and title search features, and tests 31-50 focused on general/system edge cases. `tests/Codex_test/extreme2_http_tests.py` has 30 executable Python HTTP tests against the local PHP server. Syntax checks passed: `php -l tests/Codex_test/extreme2_php_tests.php` and `python3 -m py_compile tests/Codex_test/extreme2_http_tests.py`. Test results: `php tests/Codex_test/extreme2_php_tests.php` reported 43 passed, 7 failed; `python3 tests/Codex_test/extreme2_http_tests.py` initially hit sandboxed localhost access, then passed 28 and failed 2 with elevated local HTTP permission. Baseline suites still pass: `php tests/test.php` 5/5 and `php tests/Codex_test/extreme_php_tests.php` 20/20. Issues exposed and not fixed: accented/non-ASCII uppercase title search does not match, `create_document()` accepts blank title/blank body when called directly, `create_document()` and `update_document_schedule()` accept invalid schedule strings when called directly, and token lookup does not trim copied whitespace. Re-ran `php seed.php` after testing; current sample token is `3e4969ebdcd4f774ab10f7210691759a`, current seeded readable ID is `welcome-packet-8d36`.
- 2026-05-09 13:13 EDT - Fixed the three issues found by the Codex extreme tests. Readable ID lookups now normalize input to lowercase, so uppercase typed IDs resolve. `create_share()` now trims and validates recipient email with PHP's email validator, and `public/share.php` displays the validation error instead of failing. Added `migrations/000_create_schema_migrations.sql` and updated `apply_migrations()` to record applied migration filenames and skip repeats. Verification: `php -l lib/bootstrap.php` and `php -l public/share.php` passed; `php seed.php` passed; manual `apply_migrations()` re-run returned `ok`; `php tests/test.php` passed 5/5; `php tests/Codex_test/extreme_php_tests.php` passed 20/20. HTTP checks confirmed uppercase readable ID share URL resolves and direct POST with `email=not-an-email` shows `Recipient email must be valid.` without inserting a share. Current sample token after reseed is `caf6ad647473c7ca5838b3175908d0de`; current seeded readable ID is `welcome-packet-aede`.
- 2026-05-09 13:05 EDT - Added `tests/Codex_test/` for bug-hunting tests at the user's request. `extreme_php_tests.php` contains 20 executable PHP stress tests; `extreme_scenarios.md` contains 20 non-PHP manual/HTTP/UI scenario tests. Ran `php -l tests/Codex_test/extreme_php_tests.php`: no syntax errors. Ran `php tests/Codex_test/extreme_php_tests.php`: 17 passed, 3 failed. Failures found without fixing product code: readable ID lookup is case-sensitive, invalid recipient emails can be inserted server-side, and the migration runner is not idempotent on an already-migrated database. Re-ran baseline `php tests/test.php`: 5 passed, 0 failed. Re-ran `php seed.php` afterward to reset demo data; current sample token is `0059461cf55f4902f25d220851052957`.
- 2026-05-09 12:55 EDT - Cleaned one test query to use a prepared statement instead of SQLite double-quoted string syntax. Re-ran `php tests/test.php`: 5 passed, 0 failed. Re-ran `php seed.php` afterward to reset demo data. Current clean seeded readable ID is `welcome-packet-f573`; current sample recipient token is `efd37fda2a4cfa26b36344dfd2d12250`.
- 2026-05-09 12:54 EDT - Implemented all three requested features. Added `.gitignore` for `db.sqlite` and `.DS_Store`. Added `migrations/001_document_publishing_and_readable_ids.sql` with `documents.published_at`, `documents.readable_id`, and a unique readable ID index. `seed.php` now applies migrations after `schema.sql`. `lib/bootstrap.php` now owns migration execution, readable ID generation, document creation auditing, share creation auditing, schedule update auditing, readable/numeric document lookup, recipient token lookup, publishing visibility checks, datetime parsing/display, and title search. `public/admin.php` now supports optional publish datetime on create, title search, readable IDs, and availability status. `public/share.php` now accepts readable IDs while keeping numeric fallback, includes a schedule update form, and still generates token-based recipient links. `public/view.php` now blocks future-scheduled documents with a not-yet-available message. `tests/test.php` now has five tests covering seeded shares, migrations, scheduled publishing, readable IDs, and title search. Verification run: `php -l` passed for changed PHP files; `php tests/test.php` passed with 5/5 tests; `php seed.php` was run after tests to return the local app to a clean seeded state. Local server is still running at `http://127.0.0.1:8000/admin.php`; clean seeded readable ID at last check was `welcome-packet-0a51`, and sample token from the last seed was `1ac9b69d56f5c148470ac15dec3cf34b`.
- 2026-05-09 12:44 EDT - Confirmed product decision with the user: use option B for human-readable document IDs. Readable IDs should complement, not replace, existing share tokens. Staff-facing workflows can use readable IDs, while recipient access should continue to depend on opaque random tokens. Local Docker was unavailable because `docker` was not installed, and local `php` was also missing. Installed PHP via Homebrew, ran `php seed.php`, and started the PHP built-in server with `php -S 127.0.0.1:8000 -t public/`. Verified `/admin.php` responds at `http://127.0.0.1:8000/admin.php`.
- 2026-05-09 12:37 EDT - Initial agent notes created. Current understanding: this is the CivicPlus/Folio take-home project, a small PHP 8.3 + SQLite document-sharing app. The task is to extend it with scheduled publishing, human-readable document IDs, and document title search for sharing. No feature implementation has started yet.

## Project Summary

Folio is a minimal staff-facing document-sharing app. Staff can create documents, generate one-time share links, and recipients can open those links to view documents.

The exercise is intentionally underspecified. The implementation should show good judgment around scope, tradeoffs, testing, migrations, and AI-assisted workflow.

## Current Repository Structure

- `README.md` - Main task instructions and deliverables.
- `Dockerfile` - PHP 8.3 CLI image with SQLite support.
- `docker-compose.yml` - Starts the app, reseeds the SQLite database, and serves `public/` on port `8000`.
- `schema.sql` - Initial database schema. Do not edit this directly for new schema changes.
- `seed.php` - Deletes and recreates `db.sqlite`, loads `schema.sql`, and inserts sample data.
- `lib/bootstrap.php` - Shared helpers: database connection, current staff lookup, audit logging, random token generation, and HTML escaping.
- `lib/layout.php` - Shared page header and footer rendering.
- `public/admin.php` - Staff admin page for creating documents and viewing the document list.
- `public/share.php` - Staff page for generating a share link for one document.
- `public/view.php` - Recipient-facing page that resolves a share token and displays a document.
- `public/assets/style.css` - App styling.
- `tests/test.php` - Lightweight PHP test runner with one existing seed/share test.

## Existing Behavior

- `docker compose up` rebuilds/reseeds `db.sqlite` and serves the app at `http://localhost:8000`.
- `public/index.php` redirects to `/admin.php`.
- Documents are currently identified by auto-increment integer IDs.
- Share links currently use opaque random hex tokens in `/view.php?token=...`.
- Audit logging exists through `audit_log()` and currently logs document creation and share creation.

## Task Requirements

- Add scheduled publishing: staff can set a publish date/time, and recipients see a "not yet available" message before that time.
- Add human-readable document IDs: short IDs that are easier to say, type, paste, and recognize than integer IDs.
- Add share-by-name: staff can search for documents by title instead of only scrolling the list.
- Add schema changes through migration file(s), not by editing `schema.sql` directly.
- Add at least one test for each implemented feature.
- Ensure document creation, scheduling changes, and share actions are logged to `audit_log`.
- Keep `docker compose up` working from a fresh clone.

## Working Decisions To Revisit

- Prefer making readable document IDs complement the existing share-token mechanism rather than replacing it. Tokens protect recipient links from guessability, while readable IDs improve staff-facing workflows and URLs where appropriate.
- Prefer a simple, explainable search behavior first: case-insensitive partial title match. This is easy to test and sufficient for a small internal tool.
- Prefer a lightweight migration runner integrated into `seed.php`, because the app currently recreates the database from scratch on each `docker compose up`.

## Suggested Implementation Order

1. Add a minimal migration structure and wire it into `seed.php`.
2. Implement scheduled publishing end to end.
3. Add readable document IDs.
4. Add title search for sharing.
5. Expand tests after each feature, not only at the end.
6. Run the app and tests through Docker before finalizing.

## Handoff Rules

- Keep the `Update Log` newest-first.
- Record what changed and what still needs attention.
- Mention exact commands run when they matter, especially tests and Docker commands.
- Mention any assumptions made because the README leaves choices open.
- Do not treat this file as a substitute for reading the relevant source code before editing.
