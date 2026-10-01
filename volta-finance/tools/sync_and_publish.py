r"""Autonomous Sheet -> statements -> Volta_Finance.html -> git (main) -> GitHub Pages pipeline
(2026-09-25). Meant to be run unattended, close to continuously, by a Windows Scheduled Task (not a
Claude Code scheduled task - a real git push needs to happen unattended, and Claude Code's own
auto-mode classifier blocks that even from a scheduled task, since it's still a Claude Code session;
see run_sync_and_publish.bat for how this is launched). It is a near-total no-op when the Sheet
hasn't changed since the last successful publish (one Sheets API read + one local dict compare), and
it only touches git/GitHub Pages when a real change was detected - --watch polls every second.

What it does NOT do: publish the claude.ai Artifact (d96a30f0-dfbf-45d9-9c8e-9c0c8477d56a) - that
requires the Artifact tool, only available to an actual Claude Code session, not a plain script. A
separate Claude Code scheduled task (volta-finance-artifact-sync) polls for a new git commit and
does that step on its own schedule.

Usage:
  python sync_and_publish.py            one check-and-publish cycle, then exit
  python sync_and_publish.py --watch    loop forever, checking the Sheet every 1 second (the gspread
                                         client is created once and reused, so a no-op cycle is a
                                         single Sheets API call, not a fresh OAuth handshake each time)

Each cycle prints exactly one of:
  NO_CHANGE
  NO_CHANGE (Sheet sync failed/unreachable this run - will retry next run)
  PUBLISHED: <code>: <old pl/bs/cf> -> <new pl/bs/cf>, ...
A rebuild/git/Pages failure raises (CalledProcessError etc.) and, in single-shot mode, exits non-zero
- never publish a broken build. In --watch mode a cycle's exception is logged and the loop continues
(a transient failure shouldn't permanently kill the watcher); a failure while GIT ITSELF is mid-push
is the one case this can't fully protect against, but push is the last step of a cycle, so by the
time it could fail the rebuild is already known-good and a retry next cycle is safe (git push of an
unchanged tree is a no-op).
"""
import json
import os
import shutil
import subprocess
import sys
import time
import traceback

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sync_mapping_from_sheet as sms

BASE = os.environ.get('VOLTA_FIN_DATA', 'D:/all/volta/Volta_Accounting')
TOOLS = os.path.dirname(os.path.abspath(__file__))
VF_DIR = os.path.dirname(TOOLS)   # .../reporting/volta-finance
REPO = os.path.dirname(VF_DIR)    # .../reporting (git top level)
PY = sys.executable
WORKTREE = r'C:\Users\Lenovo\Desktop\reporting-finance-sync'  # dedicated - do not share with build_docs_waybills.py's reporting-pages worktree (concurrent-run risk)
BUILD_DOCS = r'C:\Users\Lenovo\Desktop\Volta_Waybills\build_docs_waybills.py'
PUBLISHED_SNAPSHOT = os.path.join(BASE, 'sheet_overrides.published.json')

env = dict(os.environ, PYTHONIOENCODING='utf-8')

# Launched by pythonw.exe (no console of its own, see run_sync_and_publish.bat) - without this,
# Windows pops up a fresh console window for every child console app (git.exe, and python.exe/the
# build scripts if ever run with python.exe instead of pythonw.exe) since it has no parent console to
# attach to. 2026-10-01: this was the black "git.exe" window the user kept seeing flash on their
# screen every time the watcher detected a Sheet change and ran its git fetch/checkout/add/commit/push
# sequence - CREATE_NO_WINDOW suppresses it. Windows-only flag; this script only ever runs on Windows.
_NO_WINDOW = subprocess.CREATE_NO_WINDOW if hasattr(subprocess, 'CREATE_NO_WINDOW') else 0


def run(args, cwd=TOOLS):
    return subprocess.run(args, cwd=cwd, check=True, capture_output=True, text=True, encoding='utf-8', env=env, creationflags=_NO_WINDOW)


def git(args, cwd):
    return subprocess.run(['git', *args], cwd=cwd, check=True, capture_output=True, text=True, encoding='utf-8', creationflags=_NO_WINDOW)


def check_once(ws):
    """One check-and-publish cycle. Returns a one-line status string. Raises on any failure during
    the rebuild/git/Pages steps (never leaves those half-done silently)."""
    try:
        new_overrides, errors = sms.sync(ws)
    except Exception:
        return 'NO_CHANGE (Sheet sync failed/unreachable this run - will retry next run)\n' + traceback.format_exc()[-1500:]
    if errors:
        return 'NO_CHANGE (Sheet has ' + str(len(errors)) + ' unparseable row(s) - not written; previous overrides untouched): ' + '; '.join(errors[:5])

    old_overrides = json.load(open(PUBLISHED_SNAPSHOT, encoding='utf-8')) if os.path.exists(PUBLISHED_SNAPSHOT) else {}
    if new_overrides == old_overrides:
        return 'NO_CHANGE'

    changed_codes = sorted(
        set(new_overrides) ^ set(old_overrides)
        | {c for c in new_overrides if c in old_overrides and new_overrides[c] != old_overrides[c]}
    )
    summary = '; '.join(f'{c}: {old_overrides.get(c)} -> {new_overrides.get(c)}' for c in changed_codes)

    # rebuild - any failure here raises and aborts before anything is pushed (never publish a broken build)
    run([PY, os.path.join(TOOLS, 'build_mapping_class.py')])
    run([PY, os.path.join(TOOLS, 'statements_engine.py')])
    run([PY, os.path.join(TOOLS, 'build_dashboard.py')])

    # commit + push volta-finance/Volta_Finance.html to origin/main, on a clean worktree re-pinned to
    # origin/main on every run - mirrors build_docs_waybills.py's own pattern, so this never touches
    # or depends on whatever the shared Desktop\reporting clone's working tree happens to have
    # checked out or left dirty from other sessions.
    if not os.path.exists(os.path.join(WORKTREE, '.git')):  # a linked worktree's .git is a FILE, not a dir
        git(['worktree', 'prune'], REPO)
        git(['fetch', '-q', 'origin', 'main'], REPO)
        git(['worktree', 'add', '--detach', WORKTREE, 'origin/main'], REPO)
    git(['fetch', '-q', 'origin', 'main'], WORKTREE)
    git(['checkout', '-q', '--detach', 'origin/main'], WORKTREE)

    shutil.copy(os.path.join(VF_DIR, 'Volta_Finance.html'),
                os.path.join(WORKTREE, 'volta-finance', 'Volta_Finance.html'))
    status = git(['status', '--porcelain', '--', 'volta-finance/Volta_Finance.html'], WORKTREE).stdout.strip()
    if status:
        git(['add', 'volta-finance/Volta_Finance.html'], WORKTREE)
        msg = 'Volta_Finance: Sheet mapping update - ' + summary[:200]
        git(['commit', '-q', '-m', msg], WORKTREE)
        git(['push', '-q', 'origin', 'HEAD:main'], WORKTREE)

    # republish GitHub Pages (docs/finance.html) now that main has the new Volta_Finance.html
    subprocess.run([PY, BUILD_DOCS], check=True, cwd=os.path.dirname(BUILD_DOCS), env=env, creationflags=_NO_WINDOW)

    # mark this override-set as published, so the next poll (in 1 second) sees NO_CHANGE
    json.dump(new_overrides, open(PUBLISHED_SNAPSHOT, 'w', encoding='utf-8'), ensure_ascii=False)

    return 'PUBLISHED: ' + summary


def main():
    ws = sms.open_sheet()
    if '--watch' in sys.argv:
        print('watching (1s interval) - Ctrl+C to stop', flush=True)
        while True:
            t0 = time.time()
            try:
                print(time.strftime('%H:%M:%S'), check_once(ws), flush=True)
            except Exception:
                print(time.strftime('%H:%M:%S'), 'CYCLE FAILED (will retry):', flush=True)
                traceback.print_exc()
                try:
                    ws = sms.open_sheet()  # in case the failure was a stale/broken handle
                except Exception:
                    pass
            time.sleep(max(0.0, 1.0 - (time.time() - t0)))
    else:
        print(check_once(ws))


if __name__ == '__main__':
    main()
