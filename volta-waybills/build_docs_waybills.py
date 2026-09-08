# -*- coding: utf-8 -*-
"""
Builds the public GitHub Pages copies of the waybill dashboards and pushes
them to `main` of Volta-ge/reporting:

  waybill_dashboard.html     -> docs/waybills.html      (RS_Old DB, myvolta.info CRM)
  waybill_dashboard_gia.html -> docs/waybills-new.html  (RS_New DB, VoltaStoreDB CRM)

Each source is an Artifact-style body fragment (no <!DOCTYPE>/<html>/<head>/
<body>); it is wrapped into a standalone document here. Only pages whose
content actually changed are committed, so a quiet day produces no commit.

Runs in its own detached-HEAD worktree (PAGES_WORKTREE), re-pinned to
origin/main on every run, and pushes with `git push origin HEAD:main` — so it
no longer depends on which branch the shared Desktop\\reporting clone happens
to have checked out. That clone flips between `main` and
`perf/stream-dashboard` as other sessions work in it, and on 2026-09-08 the
daily docs commit (7b310a8) landed on perf/stream-dashboard, leaving GitHub
Pages stale, for exactly that reason. Detached HEAD (not a checkout of `main`)
is deliberate: a worktree holding `main` would make `git checkout main` fail
in the shared clone for whoever uses it next.

Deliberately NOT done for docs/index.html (Volta_Analytics) — the user asked
that one stay manual, only updated on explicit request.
"""
import subprocess
import sys
from pathlib import Path

LIVE = Path(r"C:\Users\Lenovo\Desktop\Volta_Waybills")
REPO = Path(r"C:\Users\Lenovo\Desktop\reporting")
PAGES_WORKTREE = Path(r"C:\Users\Lenovo\Desktop\reporting-pages")
PAGES = [
    (LIVE / "waybill_dashboard.html", "docs/waybills.html"),
    (LIVE / "waybill_dashboard_gia.html", "docs/waybills-new.html"),
]

MARKER = "</style>\n"


def build_wrapped_html(src: Path) -> str:
    content = src.read_text(encoding="utf-8")
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


def git(args, cwd):
    return subprocess.run(["git", *args], cwd=cwd, check=True, capture_output=True, text=True)


def ensure_worktree():
    """Create the pages worktree on first use; on every run re-pin it to the
    freshly fetched origin/main so the commit is always built on top of the
    live branch tip (no local branch, no merge, no rebase needed)."""
    if not (PAGES_WORKTREE / ".git").exists():
        git(["worktree", "prune"], REPO)
        git(["fetch", "-q", "origin", "main"], REPO)
        git(["worktree", "add", "--detach", str(PAGES_WORKTREE), "origin/main"], REPO)
    git(["fetch", "-q", "origin", "main"], PAGES_WORKTREE)
    git(["checkout", "-q", "--detach", "origin/main"], PAGES_WORKTREE)


def main():
    ensure_worktree()
    changed = []
    for src, rel in PAGES:
        if not src.is_file():
            print(f"skipping {rel}: source missing ({src})", file=sys.stderr)
            continue
        (PAGES_WORKTREE / rel).write_text(build_wrapped_html(src), encoding="utf-8")
        if git(["status", "--porcelain", "--", rel], PAGES_WORKTREE).stdout.strip():
            changed.append(rel)

    if not changed:
        print("GitHub Pages copies unchanged, nothing to commit.")
        return

    git(["add", *changed], PAGES_WORKTREE)
    git(["commit", "-q", "-m", "Daily refresh: waybills dashboards static build (" + ", ".join(changed) + ")"], PAGES_WORKTREE)
    git(["push", "-q", "origin", "HEAD:main"], PAGES_WORKTREE)
    print("pushed to main: " + ", ".join(changed))


if __name__ == "__main__":
    main()
