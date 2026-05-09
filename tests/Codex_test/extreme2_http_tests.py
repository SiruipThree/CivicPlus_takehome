#!/usr/bin/env python3

from __future__ import annotations

import html
import os
import sqlite3
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DB = os.path.join(ROOT, "db.sqlite")
BASE = "http://127.0.0.1:8000"

PASS = 0
FAIL = 0


def request(path: str, data: dict[str, str] | None = None, method: str | None = None) -> tuple[int, str, str]:
    url = BASE + path
    encoded = None
    headers = {}
    if data is not None:
        encoded = urllib.parse.urlencode(data).encode("utf-8")
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    req = urllib.request.Request(url, data=encoded, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status, resp.geturl(), resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.geturl(), exc.read().decode("utf-8", errors="replace")


def db_row(query: str, params: tuple = ()) -> sqlite3.Row | None:
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    try:
        cur = conn.execute(query, params)
        return cur.fetchone()
    finally:
        conn.close()


def db_value(query: str, params: tuple = ()):
    row = db_row(query, params)
    if row is None:
        return None
    return row[0]


def php_eval(code: str) -> str:
    result = subprocess.run(
        ["php", "-r", code],
        cwd=ROOT,
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return result.stdout.strip()


def seed() -> None:
    subprocess.run(["php", "seed.php"], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)


def test(name: str, fn) -> None:
    global PASS, FAIL
    try:
        fn()
        print(f"  [ok] {name}")
        PASS += 1
    except Exception as exc:
        print(f"  [FAIL] {name}: {exc}")
        FAIL += 1


def assert_true(cond, msg: str = "expected true") -> None:
    if not cond:
        raise AssertionError(msg)


def assert_eq(expected, actual, msg: str | None = None) -> None:
    if expected != actual:
        raise AssertionError(msg or f"expected {expected!r}, got {actual!r}")


def assert_in(needle: str, haystack: str, msg: str | None = None) -> None:
    if needle not in haystack:
        raise AssertionError(msg or f"missing {needle!r}")


def seeded_doc() -> sqlite3.Row:
    row = db_row("SELECT * FROM documents ORDER BY id LIMIT 1")
    if row is None:
        raise AssertionError("expected seeded document")
    return row


def seeded_token() -> str:
    token = db_value("SELECT token FROM shares ORDER BY id LIMIT 1")
    if not token:
        raise AssertionError("expected seeded token")
    return str(token)


def create_doc(title: str, body: str = "HTTP body", published_at: str | None = None) -> sqlite3.Row:
    published_arg = "null" if published_at is None else repr(published_at)
    code = (
        'require "lib/bootstrap.php"; '
        f"$id = create_document({title!r}, {body!r}, 1, {published_arg}); "
        '$row = db()->query("SELECT * FROM documents WHERE id = " . (int) $id)->fetch(); '
        'echo json_encode($row);'
    )
    import json

    data = json.loads(php_eval(code))
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    keys = list(data.keys())
    conn.execute("CREATE TABLE t (" + ",".join(f"{key} TEXT" for key in keys) + ")")
    conn.execute(
        "INSERT INTO t VALUES (" + ",".join("?" for _ in keys) + ")",
        [data[key] for key in keys],
    )
    return conn.execute("SELECT * FROM t").fetchone()


def create_share(doc_id: int, email: str = "http-reader@example.com") -> str:
    return php_eval(
        'require "lib/bootstrap.php"; '
        f"echo create_share({doc_id}, {email!r});"
    )


def post_create(title: str, body: str, published_at: str = "") -> tuple[int, str, str]:
    return request(
        "/admin.php",
        {"title": title, "body": body, "published_at": published_at},
    )


def post_schedule(readable_id: str, published_at: str) -> tuple[int, str, str]:
    return request(
        "/share.php?doc=" + urllib.parse.quote(readable_id),
        {"action": "schedule", "published_at": published_at},
    )


def post_share(readable_id: str, email: str) -> tuple[int, str, str]:
    return request(
        "/share.php?doc=" + urllib.parse.quote(readable_id),
        {"action": "share", "email": email},
    )


def main() -> int:
    seed()
    print("\nRunning Codex extreme2 Python HTTP tests:")

    test("01 root redirects to admin", lambda: (
        (lambda status, url, body: (
            assert_eq(200, status),
            assert_true(url.endswith("/admin.php"), "root did not land on admin"),
            assert_in("Admin", body),
        ))(*request("/"))
    ))

    test("02 admin page renders create form", lambda: (
        (lambda status, url, body: (
            assert_eq(200, status),
            assert_in("New document", body),
            assert_in('name="published_at"', body),
        ))(*request("/admin.php"))
    ))

    test("03 admin list shows readable ID instead of numeric-only ID", lambda: (
        (lambda body, rid: (
            assert_in(rid, body),
            assert_true("#1" not in body, "numeric-only display leaked in admin list"),
        ))(request("/admin.php")[2], seeded_doc()["readable_id"])
    ))

    test("04 admin search finds seeded document", lambda: (
        (lambda status, url, body: (
            assert_eq(200, status),
            assert_in("Welcome Packet", body),
        ))(*request("/admin.php?q=welcome"))
    ))

    test("05 admin search with no match shows empty state", lambda: (
        (lambda status, url, body: (
            assert_eq(200, status),
            assert_in("No documents matched your search.", body),
        ))(*request("/admin.php?q=missing-term"))
    ))

    test("06 create immediate document redirects and appears", lambda: (
        (lambda status, url, body: (
            assert_eq(200, status),
            assert_in("Document ", body),
            assert_in("created", body),
            assert_in("HTTP Immediate", body),
        ))(*post_create("HTTP Immediate", "Visible immediately."))
    ))

    test("07 created document has readable ID in database", lambda: (
        (lambda row: (
            assert_true(row is not None, "created doc missing"),
            assert_true(str(row["readable_id"]).startswith("http-readable-"), "unexpected readable ID"),
        ))(db_row("SELECT readable_id FROM documents WHERE title = ?", ("HTTP Readable",)) or (post_create("HTTP Readable", "Body") and db_row("SELECT readable_id FROM documents WHERE title = ?", ("HTTP Readable",))))
    ))

    test("08 share page opens by lowercase readable ID", lambda: (
        (lambda doc, response: (
            assert_eq(200, response[0]),
            assert_in('Share "Welcome Packet"', response[2]),
            assert_in(doc["readable_id"], response[2]),
        ))(seeded_doc(), request("/share.php?doc=" + urllib.parse.quote(seeded_doc()["readable_id"])))
    ))

    test("09 share page opens by uppercase readable ID", lambda: (
        (lambda doc, response: (
            assert_eq(200, response[0]),
            assert_in('Share "Welcome Packet"', response[2]),
        ))(seeded_doc(), request("/share.php?doc=" + urllib.parse.quote(seeded_doc()["readable_id"].upper())))
    ))

    test("10 share page keeps numeric fallback", lambda: (
        (lambda response: (
            assert_eq(200, response[0]),
            assert_in('Share "Welcome Packet"', response[2]),
        ))(request("/share.php?doc=1"))
    ))

    test("11 missing share doc returns 404", lambda: (
        (lambda status, url, body: (
            assert_eq(404, status),
            assert_in("Document not found.", body),
        ))(*request("/share.php?doc=does-not-exist"))
    ))

    test("12 valid email POST creates token link", lambda: (
        (lambda doc, response: (
            assert_eq(200, response[0]),
            assert_in("Share link ready", response[2]),
            assert_in("/view.php?token=", response[2]),
        ))(seeded_doc(), post_share(seeded_doc()["readable_id"], "valid-http@example.com"))
    ))

    test("13 invalid email POST is rejected", lambda: (
        (lambda before, response, after: (
            assert_eq(200, response[0]),
            assert_in("Recipient email must be valid.", response[2]),
            assert_eq(before, after, "invalid email inserted a share"),
        ))(
            db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("not-an-email",)),
            post_share(seeded_doc()["readable_id"], "not-an-email"),
            db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("not-an-email",)),
        )
    ))

    test("14 blank email POST is rejected", lambda: (
        (lambda response: (
            assert_eq(200, response[0]),
            assert_in("Recipient email is required.", response[2]),
        ))(post_share(seeded_doc()["readable_id"], ""))
    ))

    test("15 recipient seeded token shows document body", lambda: (
        (lambda response: (
            assert_eq(200, response[0]),
            assert_in("Welcome to Folio!", response[2]),
            assert_in("Shared with recipient@example.com", response[2]),
        ))(request("/view.php?token=" + seeded_token()))
    ))

    test("16 readable ID cannot be used as recipient token", lambda: (
        (lambda response: (
            assert_eq(404, response[0]),
            assert_in("Share link not found", response[2]),
        ))(request("/view.php?token=" + seeded_doc()["readable_id"]))
    ))

    test("17 unknown token returns not found", lambda: (
        (lambda response: (
            assert_eq(404, response[0]),
            assert_in("Share link not found", response[2]),
        ))(request("/view.php?token=unknown-token"))
    ))

    test("18 future scheduled token returns not-yet-available", lambda: (
        (lambda doc, token, response: (
            assert_eq(403, response[0]),
            assert_in("Document not yet available", response[2]),
            assert_true("Hidden HTTP body" not in response[2], "future body leaked"),
        ))(
            create_doc("HTTP Future", "Hidden HTTP body", "2999-01-01 00:00:00"),
            create_share(int(create_doc("HTTP Future Share", "Hidden HTTP body", "2999-01-01 00:00:00")["id"]), "future-http@example.com"),
            request("/view.php?token=" + create_share(int(create_doc("HTTP Future View", "Hidden HTTP body", "2999-01-01 00:00:00")["id"]), "future-view@example.com")),
        )
    ))

    test("19 past scheduled token shows body", lambda: (
        (lambda doc, token, response: (
            assert_eq(200, response[0]),
            assert_in("Past HTTP body", response[2]),
        ))(
            create_doc("HTTP Past View", "Past HTTP body", "2000-01-01 00:00:00"),
            create_share(int(create_doc("HTTP Past Share", "Past HTTP body", "2000-01-01 00:00:00")["id"]), "past-http@example.com"),
            request("/view.php?token=" + create_share(int(create_doc("HTTP Past Token", "Past HTTP body", "2000-01-01 00:00:00")["id"]), "past-token@example.com")),
        )
    ))

    test("20 schedule update POST changes availability", lambda: (
        (lambda doc, response, row: (
            assert_eq(200, response[0]),
            assert_in("Publishing schedule updated.", response[2]),
            assert_true(row["published_at"] is not None, "schedule was not saved"),
        ))(
            create_doc("HTTP Schedule Update"),
            post_schedule(create_doc("HTTP Schedule Update Target")["readable_id"], "2999-02-03T04:05"),
            db_row("SELECT published_at FROM documents WHERE title = ?", ("HTTP Schedule Update Target",)),
        )
    ))

    test("21 invalid schedule POST is rejected", lambda: (
        (lambda doc, response: (
            assert_eq(200, response[0]),
            assert_in("Publish date must be a valid date and time.", response[2]),
        ))(
            create_doc("HTTP Bad Schedule"),
            post_schedule(create_doc("HTTP Bad Schedule Target")["readable_id"], "not-a-date"),
        )
    ))

    test("22 title script is escaped on admin page", lambda: (
        (lambda status, url, body: (
            assert_eq(200, status),
            assert_in(html.escape("<script>alert(1)</script>"), body),
            assert_true("<script>alert(1)</script>" not in body, "raw script title rendered"),
        ))(*(post_create("<script>alert(1)</script>", "script title body") and request("/admin.php?q=script")))
    ))

    test("23 body script is escaped on view page", lambda: (
        (lambda doc, token, response: (
            assert_eq(200, response[0]),
            assert_in(html.escape("<img src=x onerror=alert(1)>"), response[2]),
            assert_true("<img src=x onerror=alert(1)>" not in response[2], "raw script body rendered"),
        ))(
            create_doc("HTTP XSS Body", "<img src=x onerror=alert(1)>"),
            create_share(int(create_doc("HTTP XSS Body Target", "<img src=x onerror=alert(1)>")["id"]), "xss@example.com"),
            request("/view.php?token=" + create_share(int(create_doc("HTTP XSS Body View", "<img src=x onerror=alert(1)>")["id"]), "xss-view@example.com")),
        )
    ))

    test("24 blank title POST is rejected by admin", lambda: (
        (lambda response: (
            assert_eq(200, response[0]),
            assert_in("Title and body are required.", response[2]),
        ))(post_create("", "Body"))
    ))

    test("25 blank body POST is rejected by admin", lambda: (
        (lambda response: (
            assert_eq(200, response[0]),
            assert_in("Title and body are required.", response[2]),
        ))(post_create("No Body", ""))
    ))

    test("26 title search should handle accented uppercase", lambda: (
        (lambda doc, response: (
            assert_eq(200, response[0]),
            assert_in("Résumé HTTP", response[2]),
        ))(
            create_doc("Résumé HTTP", "accent body"),
            request("/admin.php?q=R%C3%89SUM%C3%89"),
        )
    ))

    test("27 token URL should tolerate copied whitespace", lambda: (
        (lambda token, response: (
            assert_eq(200, response[0]),
            assert_in("Welcome Packet", response[2]),
        ))(seeded_token(), request("/view.php?token=%20" + seeded_token() + "%20"))
    ))

    test("28 direct lowercase readable ID appears in share link from admin", lambda: (
        (lambda body, rid: assert_in("/share.php?doc=" + urllib.parse.quote(rid), body))(
            request("/admin.php")[2],
            seeded_doc()["readable_id"],
        )
    ))

    test("29 repeated migration execution via PHP remains ok", lambda: (
        assert_eq("ok", php_eval('require "lib/bootstrap.php"; apply_migrations(db(), __DIR__ . "/migrations"); echo "ok";'))
    ))

    test("30 server remains responsive after failed requests", lambda: (
        request("/view.php?token=bad-token"),
        (lambda response: (
            assert_eq(200, response[0]),
            assert_in("Admin", response[2]),
        ))(request("/admin.php")),
    ))

    print(f"\n{PASS} passed, {FAIL} failed.")
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
