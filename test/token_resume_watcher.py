#!/usr/bin/env python3
"""
token_resume_watcher.py
=======================
Watches for the Claude usage/rate limit to reset, then resumes the backend
build automatically.

WHY THIS EXISTS
---------------
The VFI backend is built in 10 phases (0..9). A long build can exhaust the
current token/usage window. This watcher polls every 10 minutes; the moment the
limit is back it fires a "resume" command so development continues where it
left off — no human needed to babysit the clock.

HOW IT DETECTS "LIMIT IS BACK"
------------------------------
Two strategies, tried in order:

  1. --reset-at "<ISO8601>"  : if you know when the window resets (Claude Code
     prints an "upgrade / resets at" time when you hit the cap), pass it and the
     script simply sleeps until then, then resumes. Most reliable.

  2. Live probe (default)     : every INTERVAL seconds it runs a tiny throwaway
     Claude CLI call (PROBE_CMD). If that call succeeds (exit 0 and no
     rate-limit wording in the output) the quota is considered restored.

On restore it runs RESUME_CMD and exits 0. Everything is logged to
test/token_resume_watcher.log so you can see exactly what happened while away.

USAGE
-----
  # simplest: poll with the default probe, then resume
  python test/token_resume_watcher.py

  # if Claude told you the window resets at a specific time:
  python test/token_resume_watcher.py --reset-at "2026-08-10T16:00:00"

  # customise what "resume" means (defaults to writing a RESUME_NOW flag file
  # AND invoking the Claude CLI to continue the build):
  python test/token_resume_watcher.py --resume-cmd "claude -p \"resume the VFI backend build from the current phase\""

NOTE / HONEST LIMITATION
------------------------
A script cannot read Anthropic's internal quota counter directly. It infers the
reset either from the time you give it (--reset-at, exact) or from a successful
probe call (heuristic). If your CLI/probe is unavailable, prefer --reset-at.
"""

import argparse
import datetime as _dt
import os
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(HERE)
LOG_PATH = os.path.join(HERE, "token_resume_watcher.log")
FLAG_PATH = os.path.join(HERE, "RESUME_NOW.flag")

DEFAULT_INTERVAL = 600  # 10 minutes, per spec
# A minimal, cheap call. Exit 0 + no limit wording => quota is available again.
DEFAULT_PROBE_CMD = 'claude -p "ping"'
# What to do once the limit is back. By default: drop a flag file the build can
# watch for, AND ask the Claude CLI to resume the backend build.
DEFAULT_RESUME_CMD = (
    'claude -p "Resume the VFI backend build. Continue from the current phase '
    'in docs/phases/ (0..9) and keep going."'
)

# Words that mean "still limited" if they show up in a probe's output.
LIMIT_MARKERS = (
    "rate limit", "rate-limit", "usage limit", "quota", "too many requests",
    "429", "overloaded", "limit reached", "limit exceeded", "resets at",
    "try again later",
)


def log(msg):
    stamp = _dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{stamp}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_PATH, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except OSError:
        pass


def run(cmd, timeout=120):
    """Run a shell command, return (exit_code, combined_output)."""
    try:
        p = subprocess.run(
            cmd, shell=True, cwd=PROJECT_ROOT, timeout=timeout,
            capture_output=True, text=True,
        )
        return p.returncode, (p.stdout or "") + (p.stderr or "")
    except subprocess.TimeoutExpired:
        return 124, "TIMEOUT"
    except Exception as e:  # noqa: BLE001
        return 1, f"ERROR: {e}"


def looks_available(code, output):
    """True if the probe suggests the quota is back."""
    if code != 0:
        return False
    low = output.lower()
    return not any(m in low for m in LIMIT_MARKERS)


def sleep_until(target):
    """Block until the given datetime, logging progress every ~10 min."""
    while True:
        now = _dt.datetime.now()
        if now >= target:
            return
        remaining = (target - now).total_seconds()
        chunk = min(remaining, DEFAULT_INTERVAL)
        log(f"waiting for reset at {target.isoformat(timespec='seconds')} "
            f"— {int(remaining // 60)} min left")
        time.sleep(max(1, chunk))


def fire_resume(resume_cmd):
    log("limit appears RESTORED — resuming the build.")
    # 1) leave a flag file any external process/build loop can watch for
    try:
        with open(FLAG_PATH, "w", encoding="utf-8") as f:
            f.write(_dt.datetime.now().isoformat())
        log(f"wrote resume flag: {FLAG_PATH}")
    except OSError as e:
        log(f"could not write flag file: {e}")
    # 2) run the resume command
    log(f"running resume command: {resume_cmd}")
    code, out = run(resume_cmd, timeout=60 * 60)
    log(f"resume command exit={code}")
    if out.strip():
        log("resume output (first 800 chars):\n" + out[:800])
    return code


def main():
    ap = argparse.ArgumentParser(description="Resume the VFI backend build once the Claude limit resets.")
    ap.add_argument("--interval", type=int, default=DEFAULT_INTERVAL,
                    help="seconds between probes (default 600 = 10 min)")
    ap.add_argument("--reset-at", default=None,
                    help='ISO8601 time to wait until, e.g. "2026-08-10T16:00:00". '
                         "If given, the script sleeps until then instead of probing.")
    ap.add_argument("--probe-cmd", default=DEFAULT_PROBE_CMD,
                    help="command whose success means the quota is back")
    ap.add_argument("--resume-cmd", default=DEFAULT_RESUME_CMD,
                    help="command to run once the quota is back")
    ap.add_argument("--max-hours", type=float, default=24.0,
                    help="give up after this many hours (default 24)")
    args = ap.parse_args()

    log("=" * 60)
    log("token_resume_watcher started")
    log(f"interval={args.interval}s  reset_at={args.reset_at}  max_hours={args.max_hours}")

    # Strategy 1: explicit reset time
    if args.reset_at:
        try:
            target = _dt.datetime.fromisoformat(args.reset_at)
        except ValueError:
            log(f"bad --reset-at value: {args.reset_at!r}")
            return 2
        sleep_until(target)
        # small grace so the window is definitely open
        time.sleep(15)
        return fire_resume(args.resume_cmd)

    # Strategy 2: probe loop
    deadline = _dt.datetime.now() + _dt.timedelta(hours=args.max_hours)
    attempt = 0
    while _dt.datetime.now() < deadline:
        attempt += 1
        code, out = run(args.probe_cmd, timeout=90)
        if looks_available(code, out):
            log(f"probe #{attempt}: AVAILABLE")
            return fire_resume(args.resume_cmd)
        log(f"probe #{attempt}: still limited (exit={code}); "
            f"sleeping {args.interval}s")
        time.sleep(args.interval)

    log("gave up: max-hours reached without a detected reset.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
