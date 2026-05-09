# Claude Independent Test Suite

**300 tests** by Claude (independent third-party review), covering novel areas not tested by existing suites.

## Test Distribution

| File | Method | Count | Focus |
|---|---|---|---|
| `ci_php_tests.php` | PHP unit/integration | 120 | Internal function contracts, DB interactions, integration flows |
| `ci_http_tests.py` | Python HTTP black-box | 100 | HTML structure, end-to-end workflows, response validation |
| `ci_curl_tests.sh` | Bash/curl | 50 | HTTP protocol, security headers, resilience, methods |
| `ci_sql_tests.sh` | Bash/sqlite3 | 30 | Schema constraints, data integrity, post-seed verification |

## Category Breakdown

Each file is divided into four categories:

- **Regular**: Core contracts, basic workflows, expected behavior
- **Extreme**: Boundary values, stress, large data, Unicode, timing
- **Unexpected**: Wrong types, missing data, error conditions, edge cases
- **Fool behavior**: User mistakes, copy-paste errors, nonsense input

## Running

```bash
bash tests/claude_independent_test/run_all.sh
```

Or individually:

```bash
php tests/claude_independent_test/ci_php_tests.php
python3 tests/claude_independent_test/ci_http_tests.py
bash tests/claude_independent_test/ci_curl_tests.sh
bash tests/claude_independent_test/ci_sql_tests.sh
```

## Requirements

- PHP 8.x CLI with SQLite extension
- Python 3
- curl
- sqlite3
