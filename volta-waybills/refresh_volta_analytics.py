# -*- coding: utf-8 -*-
"""
Renders the "Volta_Analytics" dashboard (formerly "Volta Funnel Dashboard",
https://claude.ai/code/artifact/8c1cd133-55be-4b16-850e-32ea7154bd2e) and
writes it ready to publish.

HISTORY: this used to run a whole separate pipeline — start a local PHP
server, render the funnel app, fetch+reconcile RS.ge data in Python, merge
the two, inject an email nav-filter — because "Accounting" (waybills/
invoices/reconciliation) lived only in this Python project. As of
2026-08-27 that entire pipeline (RS.ge fetching, the bipartite matching
algorithm, and the email nav-filter) was ported natively into
`Volta_Daily Sales\\php-dashboard` itself (see AccountingRepository.php and
the volta_php_accounting_port Claude memory) — Accounting and the auth gate
are now permanent, first-class parts of dashboard.php, computed fresh on
every render. There is nothing left to merge: this script just runs the PHP
app and hands you a publish-ready file.

Requires the same local PHP 8.3 CLI as before, at PHP_EXE below, and the
sibling `Volta_Daily Sales\\php-dashboard` project with its own config.php
(RS.ge + DB credentials) and access_permissions.json (nav-filter emails) set
up — see that project's README.
"""
import re
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
PHP_EXE = HERE / "php83" / "php.exe"
PHP_PROJECT_DIR = Path(r"C:\Users\Lenovo\Desktop\Volta_Daily Sales\php-dashboard")
OUT = HERE / "volta_analytics_merged.html"


def render() -> str:
    """Runs the PHP app directly via CLI (no web server needed — index.php
    has no request-specific dependencies) and returns its full HTML output."""
    result = subprocess.run(
        [str(PHP_EXE), "public/index.php"],
        cwd=str(PHP_PROJECT_DIR),
        capture_output=True, timeout=180,
    )
    if result.returncode != 0:
        raise RuntimeError(f"PHP exited {result.returncode}: {result.stderr.decode('utf-8', 'replace')}")
    stderr = result.stderr.decode("utf-8", "replace").strip()
    if stderr:
        print(f"PHP stderr (non-fatal, exit 0):\n{stderr}", file=sys.stderr)
    return result.stdout.decode("utf-8")


def strip_for_artifact(raw: str) -> str:
    """Converts the full <!DOCTYPE html><html><head>...</head><body>...
    </body></html> page into the body-content-only shape the Artifact tool
    requires (title/links/style pulled out of <head>, placed directly in
    the body) — publishing the full document as-is produces NESTED
    <html>/<head>/<body> once the platform wraps it in its own skeleton.
    Caught this by reading a first publish attempt back, not by assuming
    it was safe — see volta_php_accounting_port memory."""
    head_m = re.search(r"<head>(.*?)</head>", raw, re.S)
    body_m = re.search(r"<body>(.*)</body>", raw, re.S)
    assert head_m and body_m, "expected a full <head>/<body> document from PHP"
    head, body = head_m.group(1), body_m.group(1)

    title_m = re.search(r"<title>.*?</title>", head, re.S)
    links = re.findall(r"<link\b[^>]*>", head)
    style_m = re.search(r"<style>.*?</style>", head, re.S)

    pieces = []
    if title_m:
        pieces.append(title_m.group(0))
    pieces.extend(links)
    if style_m:
        pieces.append(style_m.group(0))
    pieces.append(body)
    return "\n".join(pieces)


def main():
    print("rendering Volta_Analytics via PHP CLI (RS.ge + DB, ~30-90s)...", file=sys.stderr)
    raw = render()
    print(f"got {len(raw.encode('utf-8'))} bytes from PHP", file=sys.stderr)

    final = strip_for_artifact(raw)
    OUT.write_text(final, encoding="utf-8")
    print(f"wrote {OUT} ({len(final.encode('utf-8'))} bytes)", file=sys.stderr)


if __name__ == "__main__":
    main()
