# Codex Independent Test Suite

This directory contains a third-party test pack that is independent of the existing `tests/test.php` and `tests/Codex_test` suites.

It intentionally does not depend on, read, or modify `agent.md`.

## Test Count

- `independent_php_tests.php`: 120 helper/database tests
  - 50 regular
  - 30 extreme
  - 20 unexpected
  - 20 customer fool behavior
- `independent_http_tests.py`: 120 black-box HTTP tests against a temporary PHP built-in server
  - 50 regular
  - 30 extreme
  - 20 unexpected
  - 20 customer fool behavior
- `independent_cli_sql_tests.sh`: 60 CLI, SQLite, migration, lint, and static robustness tests
  - 25 regular
  - 15 extreme
  - 10 unexpected
  - 10 customer fool behavior

Total: 300 tests.

## Running

Run each file directly:

```sh
php tests/codex_independent_test/independent_php_tests.php
python3 tests/codex_independent_test/independent_http_tests.py
bash tests/codex_independent_test/independent_cli_sql_tests.sh
```

Or run all three sequentially:

```sh
bash tests/codex_independent_test/run_all.sh
```
