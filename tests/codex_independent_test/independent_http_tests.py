#!/usr/bin/env python3

from __future__ import annotations

import base64
import html
import json
import os
import socket
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DB = os.path.join(ROOT, "db.sqlite")
PASS = 0
FAIL = 0
COUNTER = 0
BASE = ""


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def seed() -> None:
    subprocess.run(["php", "seed.php"], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)


def b64(value: str) -> str:
    return base64.b64encode(value.encode("utf-8")).decode("ascii")


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


def php_string_expr(value: str) -> str:
    return f'base64_decode("{b64(value)}")'


def db_conn() -> sqlite3.Connection:
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    return conn


def db_row(query: str, params: tuple = ()) -> sqlite3.Row | None:
    conn = db_conn()
    try:
        return conn.execute(query, params).fetchone()
    finally:
        conn.close()


def db_value(query: str, params: tuple = ()):
    row = db_row(query, params)
    return None if row is None else row[0]


def unique_title(label: str) -> str:
    global COUNTER
    COUNTER += 1
    return f"Independent HTTP {label} {COUNTER}"


def create_doc(title: str, body: str = "HTTP body", published_at: str | None = None) -> sqlite3.Row:
    published = "null" if published_at is None else php_string_expr(published_at)
    code = (
        'require "lib/bootstrap.php"; '
        f"$id = create_document({php_string_expr(title)}, {php_string_expr(body)}, 1, {published}); "
        '$stmt = db()->prepare("SELECT * FROM documents WHERE id = ?"); '
        '$stmt->execute([$id]); '
        'echo json_encode($stmt->fetch());'
    )
    data = json.loads(php_eval(code))
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    keys = list(data.keys())
    conn.execute("CREATE TABLE t (" + ", ".join(f"{key} TEXT" for key in keys) + ")")
    conn.execute("INSERT INTO t VALUES (" + ", ".join("?" for _ in keys) + ")", [data[key] for key in keys])
    return conn.execute("SELECT * FROM t").fetchone()


def create_share(doc_id: int, email: str = "http-reader@example.com") -> str:
    return php_eval(
        'require "lib/bootstrap.php"; '
        f"echo create_share({doc_id}, {php_string_expr(email)});"
    )


def shared_doc(title: str, body: str = "HTTP body", published_at: str | None = None, email: str = "http-reader@example.com") -> tuple[sqlite3.Row, str]:
    doc = create_doc(title, body, published_at)
    token = create_share(int(doc["id"]), email)
    return doc, token


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


def request(path: str, data: dict | list[tuple[str, str]] | None = None, method: str | None = None) -> tuple[int, str, str]:
    encoded = None
    headers = {}
    if data is not None:
        encoded = urllib.parse.urlencode(data, doseq=True).encode("utf-8")
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    req = urllib.request.Request(BASE + path, data=encoded, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status, resp.geturl(), resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.geturl(), exc.read().decode("utf-8", errors="replace")


def post_create(title: str, body: str, published_at: str = "") -> tuple[int, str, str]:
    return request("/admin.php", {"title": title, "body": body, "published_at": published_at})


def post_share(readable_id: str, email: str, action: str = "share") -> tuple[int, str, str]:
    return request(
        "/share.php?doc=" + urllib.parse.quote(readable_id),
        {"action": action, "email": email},
    )


def post_schedule(readable_id: str, published_at: str, action: str = "schedule") -> tuple[int, str, str]:
    return request(
        "/share.php?doc=" + urllib.parse.quote(readable_id),
        {"action": action, "published_at": published_at},
    )


def test(category: str, name: str, fn) -> None:
    global PASS, FAIL
    try:
        fn()
        print(f"  [ok] [{category}] {name}")
        PASS += 1
    except Exception as exc:
        print(f"  [FAIL] [{category}] {name}: {exc}")
        FAIL += 1


def assert_true(cond, msg: str = "expected true") -> None:
    if not cond:
        raise AssertionError(msg)


def assert_false(cond, msg: str = "expected false") -> None:
    if cond:
        raise AssertionError(msg)


def assert_eq(expected, actual, msg: str | None = None) -> None:
    if expected != actual:
        raise AssertionError(msg or f"expected {expected!r}, got {actual!r}")


def assert_ne(unexpected, actual, msg: str | None = None) -> None:
    if unexpected == actual:
        raise AssertionError(msg or f"unexpected value {actual!r}")


def assert_in(needle: str, haystack: str, msg: str | None = None) -> None:
    if needle not in haystack:
        raise AssertionError(msg or f"missing {needle!r}")


def assert_not_in(needle: str, haystack: str, msg: str | None = None) -> None:
    if needle in haystack:
        raise AssertionError(msg or f"unexpected {needle!r}")


def assert_not_500(response: tuple[int, str, str]) -> None:
    assert_ne(500, response[0], "request produced a server error")


def latest_audit(action: str, entity_type: str, entity_id: int) -> sqlite3.Row:
    row = db_row(
        """
        SELECT *
        FROM audit_log
        WHERE action = ? AND entity_type = ? AND entity_id = ?
        ORDER BY id DESC
        LIMIT 1
        """,
        (action, entity_type, entity_id),
    )
    if row is None:
        raise AssertionError(f"audit row missing for {action} {entity_type} {entity_id}")
    return row


def assert_ui_create_immediate() -> None:
    title = unique_title("Immediate Create")
    response = post_create(title, "body")
    assert_eq(200, response[0])
    assert_in("Document ", response[2])
    assert_in("created.", response[2])
    assert_in(title, response[2])


def assert_visible_shared_body(title_label: str, body: str, published_at: str | None, email: str) -> None:
    _doc, token = shared_doc(unique_title(title_label), body, published_at, email)
    response = request("/view.php?token=" + token)
    assert_eq(200, response[0])
    assert_in(body, response[2])


def assert_schedule_set_via_http() -> None:
    doc = create_doc(unique_title("Schedule Set"))
    response = post_schedule(doc["readable_id"], "2999-06-01T10:30")
    assert_eq(200, response[0])
    assert_in("Publishing schedule updated.", response[2])
    assert_eq("2999-06-01 15:30:00", db_value("SELECT published_at FROM documents WHERE id = ?", (doc["id"],)))


def assert_schedule_clear_via_http() -> None:
    doc = create_doc(unique_title("Schedule Clear"), "body", "2999-01-01 00:00:00")
    response = post_schedule(doc["readable_id"], "")
    assert_eq(200, response[0])
    assert_eq(None, db_value("SELECT published_at FROM documents WHERE id = ?", (doc["id"],)))


def assert_body_script_escaped() -> None:
    _doc, token = shared_doc(unique_title("XSS Body"), "<img src=x onerror=alert(1)>", None, "xss-http@example.com")
    response = request("/view.php?token=" + token)
    assert_eq(200, response[0])
    assert_in(html.escape("<img src=x onerror=alert(1)>", quote=True), response[2])
    assert_not_in("<img src=x onerror=alert(1)>", response[2])


def assert_title_in_recipient_view() -> None:
    doc, token = shared_doc(unique_title("Recipient Title"), "body", None, "title-http@example.com")
    response = request("/view.php?token=" + token)
    assert_eq(200, response[0])
    assert_in(doc["title"], response[2])


def assert_future_token_blocked() -> None:
    _doc, token = shared_doc(unique_title("Future Hidden"), "hidden future body", "2999-01-01 00:00:00", "future-http@example.com")
    response = request("/view.php?token=" + token)
    assert_eq(403, response[0])
    assert_in("Document not yet available", response[2])
    assert_not_in("hidden future body", response[2])


def assert_clear_future_schedule_makes_token_visible() -> None:
    doc, token = shared_doc(unique_title("Clear Future"), "clear me body", "2999-01-01 00:00:00", "clear-http@example.com")
    assert_eq(403, request("/view.php?token=" + token)[0])
    post_schedule(doc["readable_id"], "")
    response = request("/view.php?token=" + token)
    assert_eq(200, response[0])
    assert_in("clear me body", response[2])


def assert_large_body_delivered() -> None:
    body = ("large body line\n" * 2000) + "large-body-end"
    _doc, token = shared_doc(unique_title("Large Body"), body, None, "large-http@example.com")
    response = request("/view.php?token=" + token)
    assert_eq(200, response[0])
    assert_in("large-body-end", response[2])


def assert_multiline_body_delivered() -> None:
    body = "line one\n\nline three"
    _doc, token = shared_doc(unique_title("Multiline"), body, None, "multi-http@example.com")
    response = request("/view.php?token=" + token)
    assert_in(body, response[2])


def assert_one_time_link_not_reusable() -> None:
    _doc, token = shared_doc(unique_title("One Time"), "one-time body", None, "one-time@example.com")
    assert_eq(200, request("/view.php?token=" + token)[0])
    assert_eq(404, request("/view.php?token=" + token)[0], "share token was reusable")


def assert_share_title_escaped() -> None:
    doc = create_doc("<script>alert('share-title')</script>", "body")
    body = request("/share.php?doc=" + doc["readable_id"])[2]
    assert_in("&lt;script&gt;alert(", body)
    assert_not_in(doc["title"], body)


def assert_scheduled_share_does_not_leak() -> None:
    doc = create_doc(unique_title("Scheduled Share"), "scheduled secret", "2999-01-01 00:00:00")
    response = post_share(doc["readable_id"], "scheduled-share@example.com")
    assert_eq(200, response[0])
    token = db_value("SELECT token FROM shares WHERE recipient_email = ?", ("scheduled-share@example.com",))
    blocked = request("/view.php?token=" + token)
    assert_eq(403, blocked[0])
    assert_not_in("scheduled secret", blocked[2])


def assert_ampersand_body_escaped() -> None:
    _doc, token = shared_doc(unique_title("Amp Body"), "Tom & Jerry", None, "amp@example.com")
    response = request("/view.php?token=" + token)
    assert_in("Tom &amp; Jerry", response[2])


def main() -> int:
    global BASE
    seed()
    port = free_port()
    BASE = f"http://127.0.0.1:{port}"
    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", "public"],
        cwd=ROOT,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    try:
        for _ in range(50):
            try:
                request("/admin.php")
                break
            except Exception:
                time.sleep(0.1)
        else:
            raise RuntimeError("PHP test server did not start")

        print("\nRunning Codex independent Python HTTP tests (120):")

        # Regular behavior: 50 tests.
        test("regular", "01 root redirects to admin", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_true(r[1].endswith("/admin.php"))))(request("/"))
        ))

        test("regular", "02 admin page renders creation form", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("New document", r[2]), assert_in('name="title"', r[2])))(request("/admin.php"))
        ))

        test("regular", "03 admin page renders title search form", lambda: (
            (lambda body: (assert_in('name="q"', body), assert_in("Search by title", body)))(request("/admin.php")[2])
        ))

        test("regular", "04 admin page renders publish datetime field", lambda: (
            assert_in('name="published_at"', request("/admin.php")[2])
        ))

        test("regular", "05 admin table shows required columns", lambda: (
            (lambda body: (
                assert_in("<th>ID</th>", body),
                assert_in("<th>Title</th>", body),
                assert_in("<th>Availability</th>", body),
                assert_in("<th>Creator</th>", body),
            ))(request("/admin.php")[2])
        ))

        test("regular", "06 seeded document is listed in admin", lambda: (
            assert_in("Welcome Packet", request("/admin.php")[2])
        ))

        test("regular", "07 seeded readable ID is visible in admin", lambda: (
            assert_in(seeded_doc()["readable_id"], request("/admin.php")[2])
        ))

        test("regular", "08 share page opens by readable ID", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in('Share "Welcome Packet"', r[2])))(request("/share.php?doc=" + urllib.parse.quote(seeded_doc()["readable_id"])))
        ))

        test("regular", "09 share page renders scheduling form", lambda: (
            (lambda body: (assert_in("Publishing schedule", body), assert_in("Update schedule", body)))(request("/share.php?doc=" + seeded_doc()["readable_id"])[2])
        ))

        test("regular", "10 share page renders recipient email form", lambda: (
            (lambda body: (assert_in('name="email"', body), assert_in("Generate link", body)))(request("/share.php?doc=" + seeded_doc()["readable_id"])[2])
        ))

        test("regular", "11 share page keeps numeric fallback route", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in('Share "Welcome Packet"', r[2])))(request("/share.php?doc=1"))
        ))

        test("regular", "12 share page accepts uppercase typed readable ID", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in('Share "Welcome Packet"', r[2])))(request("/share.php?doc=" + seeded_doc()["readable_id"].upper()))
        ))

        test("regular", "13 missing document share route returns 404", lambda: (
            (lambda r: (assert_eq(404, r[0]), assert_in("Document not found.", r[2])))(request("/share.php?doc=missing-doc"))
        ))

        test("regular", "14 seeded token view returns 200", lambda: (
            assert_eq(200, request("/view.php?token=" + seeded_token())[0])
        ))

        test("regular", "15 seeded token view shows body", lambda: (
            assert_in("Welcome to Folio!", request("/view.php?token=" + seeded_token())[2])
        ))

        test("regular", "16 seeded token view shows recipient", lambda: (
            assert_in("Shared with recipient@example.com", request("/view.php?token=" + seeded_token())[2])
        ))

        test("regular", "17 unknown token returns not found", lambda: (
            (lambda r: (assert_eq(404, r[0]), assert_in("Share link not found", r[2])))(request("/view.php?token=nope"))
        ))

        test("regular", "18 empty token returns not found", lambda: (
            (lambda r: (assert_eq(404, r[0]), assert_in("Share link not found", r[2])))(request("/view.php?token="))
        ))

        test("regular", "19 readable ID is not accepted as recipient token", lambda: (
            (lambda r: (assert_eq(404, r[0]), assert_in("Share link not found", r[2])))(request("/view.php?token=" + seeded_doc()["readable_id"]))
        ))

        test("regular", "20 UI create immediate document redirects to success banner", assert_ui_create_immediate)

        test("regular", "21 UI-created document receives readable ID", lambda: (
            (lambda title: (
                post_create(title, "body"),
                assert_true(str(db_value("SELECT readable_id FROM documents WHERE title = ?", (title,))).startswith("independent-http-ui-readable")),
            ))("Independent HTTP UI Readable")
        ))

        test("regular", "22 UI-created body is stored", lambda: (
            (lambda title, body: (
                post_create(title, body),
                assert_eq(body, db_value("SELECT body FROM documents WHERE title = ?", (title,))),
            ))(unique_title("Stored Body"), "stored through admin")
        ))

        test("regular", "23 UI future publish stores converted UTC timestamp", lambda: (
            (lambda title: (
                post_create(title, "future", "2999-05-09T12:00"),
                assert_eq("2999-05-09 17:00:00", db_value("SELECT published_at FROM documents WHERE title = ?", (title,))),
            ))(unique_title("Future UTC"))
        ))

        test("regular", "24 future UI-created document appears scheduled in admin", lambda: (
            (lambda title: (
                post_create(title, "future body", "2999-05-09T12:00"),
                (lambda r: (assert_eq(200, r[0]), assert_in(title, r[2]), assert_in("Scheduled", r[2])))(request("/admin.php?q=" + urllib.parse.quote(title))),
            ))(unique_title("Future Listed"))
        ))

        test("regular", "25 past scheduled document can be viewed through a token", lambda: assert_visible_shared_body("Past HTTP", "past body", "2000-01-01 00:00:00", "past-ui@example.com"))

        test("regular", "26 valid share POST renders token link", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Share link ready", r[2]), assert_in("/view.php?token=", r[2])))(post_share(seeded_doc()["readable_id"], "valid-http@example.com"))
        ))

        test("regular", "27 valid share POST persists share row", lambda: (
            (lambda before: (
                post_share(seeded_doc()["readable_id"], "persist-http@example.com"),
                assert_eq(before + 1, db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("persist-http@example.com",))),
            ))(int(db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("persist-http@example.com",))))
        ))

        test("regular", "28 invalid email POST is rejected without insert", lambda: (
            (lambda before, r: (
                assert_eq(200, r[0]),
                assert_in("Recipient email must be valid.", r[2]),
                assert_eq(before, db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("not-an-email",))),
            ))(db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("not-an-email",)), post_share(seeded_doc()["readable_id"], "not-an-email"))
        ))

        test("regular", "29 blank email POST is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Recipient email is required.", r[2])))(post_share(seeded_doc()["readable_id"], ""))
        ))

        test("regular", "30 schedule POST can set a future publish date", assert_schedule_set_via_http)

        test("regular", "31 schedule POST can clear a publish date", assert_schedule_clear_via_http)

        test("regular", "32 invalid schedule POST is rejected", lambda: (
            (lambda doc, r: (assert_eq(200, r[0]), assert_in("Publish date must be a valid date and time.", r[2])))(
                create_doc(unique_title("Bad Schedule")), post_schedule(create_doc(unique_title("Bad Schedule Target"))["readable_id"], "not-a-date")
            )
        ))

        test("regular", "33 admin search finds seeded document by lowercase term", lambda: (
            assert_in("Welcome Packet", request("/admin.php?q=welcome")[2])
        ))

        test("regular", "34 admin search finds seeded document by uppercase ASCII term", lambda: (
            assert_in("Welcome Packet", request("/admin.php?q=WELCOME")[2])
        ))

        test("regular", "35 admin search with no match shows empty state", lambda: (
            assert_in("No documents matched your search.", request("/admin.php?q=term-that-does-not-exist")[2])
        ))

        test("regular", "36 admin search trims leading and trailing query spaces", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote("  Trimmed  "))[2]),
            ))("Independent HTTP Trimmed Search")
        ))

        test("regular", "37 admin search handles apostrophes", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in("Independent HTTP Mayor", request("/admin.php?q=" + urllib.parse.quote("Mayor's"))[2]),
            ))("Independent HTTP Mayor's Search")
        ))

        test("regular", "38 admin search treats percent literally", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote("100%"))[2]),
            ))("Independent HTTP 100% Search")
        ))

        test("regular", "39 admin search handles Chinese exact substring", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote("公告"))[2]),
            ))("Independent HTTP 市政公告")
        ))

        test("regular", "40 UI create action is audited", lambda: (
            (lambda title: (
                post_create(title, "body"),
                (lambda doc_id: assert_true(latest_audit("create", "document", int(doc_id)) is not None))(db_value("SELECT id FROM documents WHERE title = ?", (title,))),
            ))(unique_title("Audit Create UI"))
        ))

        test("regular", "41 UI schedule update is audited", lambda: (
            (lambda doc: (
                post_schedule(doc["readable_id"], "2999-01-01T01:02"),
                assert_true(latest_audit("schedule_update", "document", int(doc["id"])) is not None),
            ))(create_doc(unique_title("Audit Schedule UI")))
        ))

        test("regular", "42 UI share action is audited", lambda: (
            (lambda before: (
                post_share(seeded_doc()["readable_id"], "audit-http@example.com"),
                assert_eq(before + 1, int(db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'create' AND entity_type = 'share'"))),
            ))(int(db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'create' AND entity_type = 'share'")))
        ))

        test("regular", "43 title script is escaped in admin HTML", lambda: (
            (lambda title: (
                create_doc(title),
                (lambda body: (assert_in("&lt;script&gt;alert(", body), assert_not_in(title, body)))(request("/admin.php?q=script")[2]),
            ))("<script>alert('title')</script>")
        ))

        test("regular", "44 body script is escaped in recipient HTML", assert_body_script_escaped)

        test("regular", "45 document title appears in recipient view", assert_title_in_recipient_view)

        test("regular", "46 future token is blocked and body is not leaked", assert_future_token_blocked)

        test("regular", "47 clearing a future schedule makes existing token visible", assert_clear_future_schedule_makes_token_visible)

        test("regular", "48 past scheduled token is visible", lambda: assert_visible_shared_body("Past Visible", "past visible body", "2001-01-01 00:00:00", "past-visible@example.com"))

        test("regular", "49 admin share links use readable document references", lambda: (
            assert_in("/share.php?doc=" + urllib.parse.quote(seeded_doc()["readable_id"]), request("/admin.php")[2])
        ))

        test("regular", "50 generated recipient token differs from readable ID", lambda: (
            (lambda token: assert_ne(seeded_doc()["readable_id"], token))(create_share(int(seeded_doc()["id"]), "token-diff@example.com"))
        ))

        # Extreme behavior: 30 tests.
        test("extreme", "01 very long title can be created through UI", lambda: (
            (lambda title, r: (assert_eq(200, r[0]), assert_in("created.", r[2])))(("L" * 240), post_create("L" * 240, "long title body"))
        ))

        test("extreme", "02 very long title is escaped and searchable", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote(title[:20]))[2]),
            ))("Independent HTTP Long " + "A" * 220)
        ))

        test("extreme", "03 slash title produces slash-free readable ID", lambda: (
            (lambda doc: assert_not_in("/", doc["readable_id"]))(create_doc("Independent HTTP Finance/Legal/HR"))
        ))

        test("extreme", "04 punctuation-only title creates document fallback ID", lambda: (
            (lambda doc: assert_true(str(doc["readable_id"]).startswith("document-")))(create_doc("!!! --- ###"))
        ))

        test("extreme", "05 emoji-only title creates document fallback ID", lambda: (
            (lambda doc: assert_true(str(doc["readable_id"]).startswith("document-")))(create_doc("🚀🔥✨"))
        ))

        test("extreme", "06 duplicate UI titles receive unique readable IDs", lambda: (
            (lambda title: (
                post_create(title, "a"),
                post_create(title, "b"),
                (lambda rows: assert_eq(2, len(set(row["readable_id"] for row in rows))))(
                    db_conn().execute("SELECT readable_id FROM documents WHERE title = ?", (title,)).fetchall()
                ),
            ))(unique_title("Duplicate UI"))
        ))

        test("extreme", "07 twenty repeated helper titles receive unique readable IDs", lambda: (
            (lambda ids: assert_eq(len(ids), len(set(ids))))([create_doc("Independent HTTP Burst", f"body {i}")["readable_id"] for i in range(20)])
        ))

        test("extreme", "08 large body can be delivered through recipient view", assert_large_body_delivered)

        test("extreme", "09 multiline body keeps line breaks in preformatted view", assert_multiline_body_delivered)

        test("extreme", "10 invalid calendar date through UI is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Publish date must be a valid date and time.", r[2])))(post_create(unique_title("Invalid Calendar"), "body", "2026-02-31T12:00"))
        ))

        test("extreme", "11 timezone suffix through UI datetime is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Publish date must be a valid date and time.", r[2])))(post_create(unique_title("Timezone Suffix"), "body", "2026-05-09T12:00Z"))
        ))

        test("extreme", "12 schedule update rejects impossible date", lambda: (
            (lambda doc, r: (assert_eq(200, r[0]), assert_in("Publish date must be a valid date and time.", r[2])))(
                create_doc(unique_title("Impossible Schedule")), post_schedule(create_doc(unique_title("Impossible Schedule Target"))["readable_id"], "2026-02-31T12:00")
            )
        ))

        test("extreme", "13 far future scheduled token stays blocked", lambda: (
            (lambda doc, token, r: assert_eq(403, r[0]))(
                create_doc(unique_title("Far Future"), "far body", "9999-12-31 23:59:59"),
                (lambda d: create_share(int(d["id"]), "far-http@example.com"))(create_doc(unique_title("Far Future Token"), "far body", "9999-12-31 23:59:59")),
                request("/view.php?token=" + create_share(int(create_doc(unique_title("Far Future View"), "far body", "9999-12-31 23:59:59")["id"]), "far-view@example.com")),
            )
        ))

        test("extreme", "14 token with encoded spaces is accepted after trimming", lambda: (
            (lambda token, r: (assert_eq(200, r[0]), assert_in("Welcome Packet", r[2])))(seeded_token(), request("/view.php?token=%20" + seeded_token() + "%20"))
        ))

        test("extreme", "15 uppercase token is not treated as the same secret", lambda: (
            (lambda r: assert_eq(404, r[0]))(request("/view.php?token=" + seeded_token().upper()))
        ))

        test("extreme", "16 very long search query does not 500", lambda: (
            assert_not_500(request("/admin.php?q=" + urllib.parse.quote("x" * 5000)))
        ))

        test("extreme", "17 SQL-looking search query does not return every document", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("No documents matched your search.", r[2])))(request("/admin.php?q=" + urllib.parse.quote("%' OR 1=1 --")))
        ))

        test("extreme", "18 script search query is escaped in the input value", lambda: (
            (lambda body: (assert_in(html.escape("<script>x</script>", quote=True), body), assert_not_in('value="<script>x</script>"', body)))(
                request("/admin.php?q=" + urllib.parse.quote("<script>x</script>"))[2]
            )
        ))

        test("extreme", "19 encoded readable ID opens share page", lambda: (
            (lambda doc: assert_eq(200, request("/share.php?doc=" + urllib.parse.quote(doc["readable_id"]))[0]))(seeded_doc())
        ))

        test("extreme", "20 readable ID length stays compact for long titles", lambda: (
            (lambda doc: assert_true(len(doc["readable_id"]) <= 37))(create_doc("Independent HTTP " + ("Compact " * 40)))
        ))

        test("extreme", "21 POST to view with valid token still does not break", lambda: (
            assert_not_500(request("/view.php?token=" + seeded_token(), {"extra": "ignored"}))
        ))

        test("extreme", "22 share email plus alias is accepted", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Share link ready", r[2])))(post_share(seeded_doc()["readable_id"], "reader+alias@example.com"))
        ))

        test("extreme", "23 schedule page displays converted human-readable time", lambda: (
            (lambda doc, body: (assert_in("Current availability:", body), assert_in("2999", body)))(
                create_doc(unique_title("Display Time"), "body", "2999-01-02 03:04:05"),
                request("/share.php?doc=" + create_doc(unique_title("Display Time Target"), "body", "2999-01-02 03:04:05")["readable_id"])[2],
            )
        ))

        test("extreme", "24 admin marks immediate document available", lambda: (
            (lambda title: (
                create_doc(title),
                (lambda body: (assert_in(title, body), assert_in("Available", body)))(request("/admin.php?q=" + urllib.parse.quote(title))[2]),
            ))(unique_title("Available Label"))
        ))

        test("extreme", "25 admin marks future document scheduled", lambda: (
            (lambda title: (
                create_doc(title, "body", "2999-01-01 00:00:00"),
                (lambda body: (assert_in(title, body), assert_in("Scheduled", body)))(request("/admin.php?q=" + urllib.parse.quote(title))[2]),
            ))(unique_title("Scheduled Label"))
        ))

        test("extreme", "26 non-Latin title renders safely in admin", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote("市政"))[2]),
            ))("Independent HTTP 市政 服务")
        ))

        test("extreme", "27 percent-encoded unknown token returns controlled 404", lambda: (
            (lambda r: (assert_eq(404, r[0]), assert_in("Share link not found", r[2])))(request("/view.php?token=%E2%98%83"))
        ))

        test("extreme", "28 root remains responsive after 404", lambda: (
            request("/view.php?token=missing"),
            assert_eq(200, request("/")[0]),
        ))

        test("extreme", "29 repeated migration call from CLI does not break web", lambda: (
            php_eval('require "lib/bootstrap.php"; apply_migrations(db(), __DIR__ . "/migrations"); echo "ok";'),
            assert_eq(200, request("/admin.php")[0]),
        ))

        test("extreme", "30 invalid route returns server-controlled 404 rather than crashing", lambda: (
            assert_ne(500, request("/definitely-missing.php")[0])
        ))

        # Unexpected behavior: 20 tests.
        test("unexpected", "01 one-time recipient link is not reusable after first successful view", assert_one_time_link_not_reusable)

        test("unexpected", "02 unknown share action should not create a link", lambda: (
            (lambda before, r, after: (assert_ne(500, r[0]), assert_eq(before, after, "unknown action created a share")))(
                int(db_value("SELECT COUNT(*) FROM shares")),
                post_share(seeded_doc()["readable_id"], "unknown-action@example.com", action="delete"),
                int(db_value("SELECT COUNT(*) FROM shares")),
            )
        ))

        test("unexpected", "03 admin q array parameter should not 500", lambda: (
            assert_not_500(request("/admin.php?q[]=welcome"))
        ))

        test("unexpected", "04 share doc array parameter should not 500", lambda: (
            assert_not_500(request("/share.php?doc[]=1"))
        ))

        test("unexpected", "05 view token array parameter should not 500", lambda: (
            assert_not_500(request("/view.php?token[]=x"))
        ))

        test("unexpected", "06 admin POST title array should not 500", lambda: (
            assert_not_500(request("/admin.php", [("title[]", "x"), ("body", "body"), ("published_at", "")]))
        ))

        test("unexpected", "07 admin POST body array should not 500", lambda: (
            assert_not_500(request("/admin.php", [("title", "title"), ("body[]", "body"), ("published_at", "")]))
        ))

        test("unexpected", "08 share POST email array should not 500", lambda: (
            assert_not_500(request("/share.php?doc=" + seeded_doc()["readable_id"], [("action", "share"), ("email[]", "x")]))
        ))

        test("unexpected", "09 schedule POST published_at array should not 500", lambda: (
            assert_not_500(request("/share.php?doc=" + seeded_doc()["readable_id"], [("action", "schedule"), ("published_at[]", "x")]))
        ))

        test("unexpected", "10 action array parameter should not 500", lambda: (
            assert_not_500(request("/share.php?doc=" + seeded_doc()["readable_id"], [("action[]", "share"), ("email", "array-action@example.com")]))
        ))

        test("unexpected", "11 accented uppercase search finds accented title", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote("RÉSUMÉ"))[2]),
            ))("Independent HTTP Résumé Packet")
        ))

        test("unexpected", "12 German uppercase search finds lowercase sharp-s title", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote("STRASSE"))[2]),
            ))("Independent HTTP straße notice")
        ))

        test("unexpected", "13 Turkish dotted uppercase search finds lowercase title", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(title, request("/admin.php?q=" + urllib.parse.quote("İSTANBUL"))[2]),
            ))("Independent HTTP İstanbul notice")
        ))

        test("unexpected", "14 share route with empty doc reference returns controlled 404", lambda: (
            (lambda r: (assert_ne(500, r[0]), assert_eq(404, r[0])))(request("/share.php?doc=%20%20"))
        ))

        test("unexpected", "15 negative numeric document reference is rejected", lambda: (
            (lambda r: (assert_ne(500, r[0]), assert_eq(404, r[0])))(request("/share.php?doc=-1"))
        ))

        test("unexpected", "16 decimal numeric document reference is rejected", lambda: (
            (lambda r: (assert_ne(500, r[0]), assert_eq(404, r[0])))(request("/share.php?doc=1.0"))
        ))

        test("unexpected", "17 valid share POST should not expose database path on page", lambda: (
            assert_not_in(DB, post_share(seeded_doc()["readable_id"], "no-path@example.com")[2])
        ))

        test("unexpected", "18 invalid schedule should preserve the previous schedule", lambda: (
            (lambda doc: (
                post_schedule(doc["readable_id"], "not-a-date"),
                assert_eq(doc["published_at"], db_value("SELECT published_at FROM documents WHERE id = ?", (doc["id"],))),
            ))(create_doc(unique_title("Preserve Bad Schedule"), "body", "2999-01-01 00:00:00"))
        ))

        test("unexpected", "19 invalid email should preserve share count", lambda: (
            (lambda before: (
                post_share(seeded_doc()["readable_id"], "bad-email"),
                assert_eq(before, int(db_value("SELECT COUNT(*) FROM shares"))),
            ))(int(db_value("SELECT COUNT(*) FROM shares")))
        ))

        test("unexpected", "20 share page title escapes dangerous document title", assert_share_title_escaped)

        # Customer fool behavior: 20 tests.
        test("fool", "01 blank title through UI is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Title and body are required.", r[2])))(post_create("", "body"))
        ))

        test("fool", "02 spaces-only title through UI is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Title and body are required.", r[2])))(post_create("     ", "body"))
        ))

        test("fool", "03 blank body through UI is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Title and body are required.", r[2])))(post_create("title", ""))
        ))

        test("fool", "04 spaces-only body through UI is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Title and body are required.", r[2])))(post_create("title", "     "))
        ))

        test("fool", "05 copied readable ID with spaces opens share page", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in('Share "Welcome Packet"', r[2])))(request("/share.php?doc=%20" + seeded_doc()["readable_id"] + "%20"))
        ))

        test("fool", "06 copied token with newline opens view", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Welcome Packet", r[2])))(request("/view.php?token=" + seeded_token() + "%0A"))
        ))

        test("fool", "07 user can clear schedule by submitting blank field", lambda: (
            (lambda doc: (
                post_schedule(doc["readable_id"], ""),
                assert_eq(None, db_value("SELECT published_at FROM documents WHERE id = ?", (doc["id"],))),
            ))(create_doc(unique_title("User Clear"), "body", "2999-01-01 00:00:00"))
        ))

        test("fool", "08 email with surrounding spaces is accepted and trimmed", lambda: (
            (lambda before: (
                post_share(seeded_doc()["readable_id"], "  trim-ui@example.com  "),
                assert_eq(before + 1, int(db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("trim-ui@example.com",)))),
            ))(int(db_value("SELECT COUNT(*) FROM shares WHERE recipient_email = ?", ("trim-ui@example.com",))))
        ))

        test("fool", "09 malformed email with spaces inside is rejected", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Recipient email must be valid.", r[2])))(post_share(seeded_doc()["readable_id"], "bad email@example.com"))
        ))

        test("fool", "10 missing token parameter gives not found page", lambda: (
            (lambda r: (assert_eq(404, r[0]), assert_in("Share link not found", r[2])))(request("/view.php"))
        ))

        test("fool", "11 missing doc parameter gives not found page", lambda: (
            (lambda r: (assert_eq(404, r[0]), assert_in("Document not found.", r[2])))(request("/share.php"))
        ))

        test("fool", "12 user-entered zero document reference does not 500", lambda: (
            assert_not_500(request("/share.php?doc=0"))
        ))

        test("fool", "13 lowercase query no results page remains usable", lambda: (
            (lambda body: (assert_in("No documents matched your search.", body), assert_in("Clear", body)))(request("/admin.php?q=zzzzzzzzz")[2])
        ))

        test("fool", "14 duplicate create submissions both succeed with separate IDs", lambda: (
            (lambda title: (
                post_create(title, "body"),
                post_create(title, "body"),
                assert_eq(2, int(db_value("SELECT COUNT(*) FROM documents WHERE title = ?", (title,)))),
            ))(unique_title("Double Submit"))
        ))

        test("fool", "15 user can share a scheduled document without leaking body", assert_scheduled_share_does_not_leak)

        test("fool", "16 user can reschedule from future to a past time", lambda: (
            (lambda doc: (
                post_schedule(doc["readable_id"], "2000-01-01T00:00"),
                assert_true(str(db_value("SELECT published_at FROM documents WHERE id = ?", (doc["id"],))).startswith("2000-01-01")),
            ))(create_doc(unique_title("Reschedule Past"), "body", "2999-01-01 00:00:00"))
        ))

        test("fool", "17 body containing ampersand is escaped without data loss", assert_ampersand_body_escaped)

        test("fool", "18 title containing quotes is escaped in admin", lambda: (
            (lambda title: (
                create_doc(title),
                assert_in(html.escape(title, quote=True), request("/admin.php?q=Quoted")[2]),
            ))('Independent HTTP "Quoted" Title')
        ))

        test("fool", "19 recipient share with subdomain email works", lambda: (
            (lambda r: (assert_eq(200, r[0]), assert_in("Share link ready", r[2])))(post_share(seeded_doc()["readable_id"], "reader@mail.example.co"))
        ))

        test("fool", "20 server remains responsive after fool input batch", lambda: (
            request("/admin.php?q[]=x"),
            request("/view.php?token[]=x"),
            assert_eq(200, request("/admin.php")[0]),
        ))

        print(f"\nPython HTTP independent result: {PASS} passed, {FAIL} failed.")
        return 1 if FAIL else 0
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)


if __name__ == "__main__":
    sys.exit(main())
