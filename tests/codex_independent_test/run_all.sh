#!/usr/bin/env bash

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1

php tests/codex_independent_test/independent_php_tests.php
php_status=$?

python3 tests/codex_independent_test/independent_http_tests.py
python_status=$?

bash tests/codex_independent_test/independent_cli_sql_tests.sh
cli_status=$?

if [[ "$php_status" -ne 0 || "$python_status" -ne 0 || "$cli_status" -ne 0 ]]; then
    exit 1
fi

exit 0
