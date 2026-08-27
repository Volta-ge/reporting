# -*- coding: utf-8 -*-
"""
Wraps the freshly-generated waybill_dashboard.html (an Artifact-style body
fragment, no <!DOCTYPE>/<html>/<head>/<body>) into a standalone HTML document
at reporting/docs/waybills.html, then commits + pushes it if anything changed.

This is the GitHub Pages copy the user asked to keep on the same daily
schedule as the two Claude Artifacts (2026-08-27) — see volta_sales_waybill_reconciliation
memory. Deliberately NOT done for docs/index.html (Volta_Analytics) — the user
asked that one stay manual, only updated on explicit request.
"""
import subprocess
import sys
from pathlib import Path

SRC = Path(r"C:\Users\Lenovo\Desktop\Volta_Waybills\waybill_dashboard.html")
REPO = Path(r"C:\Users\Lenovo\Desktop\reporting")
DST = REPO / "docs" / "waybills.html"

MARKER = "</style>\n"


def build_wrapped_html() -> str:
    content = SRC.read_text(encoding="utf-8")
    idx = content.index(MARKER) + len(MARKER)
    head_part = content[:idx]
    body_part = content[idx:]
    return (
        "<!DOCTYPE html>\n<html lang=\"ka\">\n<head>\n"
        "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        + head_part
        + "</head>\n<body>\n"
        + body_part
        + "\n</body>\n</html>\n"
    )


def run(cmd, **kwargs):
    return subprocess.run(cmd, cwd=REPO, check=True, capture_output=True, text=True, **kwargs)


def main():
    if not SRC.is_file():
        sys.exit(f"Missing source file: {SRC} — run refresh_dashboard.py first.")

    DST.write_text(build_wrapped_html(), encoding="utf-8")

    status = run(["git", "status", "--porcelain", "--", "docs/waybills.html"])
    if not status.stdout.strip():
        print("docs/waybills.html unchanged, nothing to commit.")
        return

    run(["git", "add", "docs/waybills.html"])
    run(["git", "commit", "-m", "Daily refresh: waybills/invoices/reconciliation static build"])
    run(["git", "push"])
    print("docs/waybills.html updated, committed, and pushed.")


if __name__ == "__main__":
    main()
