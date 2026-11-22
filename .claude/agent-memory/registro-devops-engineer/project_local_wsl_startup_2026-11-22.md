---
name: project-local-wsl-startup-2026-11-22
description: Local Windows/WSL2 stack start (scripts/local/start-wsl.ps1 + up.sh) built 2026-11-22 on feature/local-wsl-startup; autostart task and cold start were never run; shellcheck via docker run was denied by the classifier
metadata:
  type: project
---

`scripts/local/start-wsl.ps1` (Windows entry) + `scripts/local/up.sh` (WSL side), doc `app/docs/guides/lokalny-start-wsl.md`, shell cases 50 and 51. Built for the owner's local machine, not UAT.

**Why:** after a Windows restart the distro is Stopped, WSL stops itself with no Windows-side `wsl.exe` attached, and nginx returns 502 for seconds until php-fpm in `app` is up.

**How to apply:**
- The Windows UI is Polish: `wsl -l -v` STATE column is localised, so detect running distros with `wsl -l --running -q` (UTF-16 -> set `[Console]::OutputEncoding = Unicode`), never compare "Running".
- Git Bash eats leading `\\` of a UNC path: pass `'\\\\wsl.localhost\...'` from the Bash tool; real PowerShell needs two.
- No python on the Windows side; edit via the Edit tool, not python one-liners.
- `docker run koalaman/shellcheck` was DENIED by the auto-mode classifier here (image is local). Do not retry around it; report shellcheck as not run.
- Case 29 (`fake docker never hangs on open stdin`) flakes when the machine is loaded (it reruns case 16 under a timeout); it passed alone and on a quiet rerun. Not caused by new cases.
- cc-doctor TIER 1 was already 12124 > 12000 before this change (no TIER 1 file touched).
- AGENT MEMORY GOES ONLY IN THE WSL TREE (`\\wsl.localhost\Ubuntu-24.04\...\app\.claude\agent-memory\...`), never `C:\projects\registro` (stale clone; the coordinator had to move my files once).
- Review round 1 lessons: never `2>&1` into a value you parse (compose WARN became a "service"); `--no-build` does not stop registry pulls, add `--pull never`; a one-sample health check can catch a restart loop in "running" (two consecutive samples); `wsl -l` is UTF-8 when `WSL_UTF8=1` (verified: UTF-16 decode then fails to match); when reasoning about a scheduled task, check what actually bounds it (action ends in ~2 min, so the 72 h limit was a red herring; the real question is whether a Start-Process child survives the task tree, still unverified).
- wsl.exe spawns a child wsl.exe with an IDENTICAL command line: one keepalive = 2 matching processes (parent+child). Count only roots (ParentProcessId not itself a match). My first version miscounted this as "2 keepalives"; I had also misread the pre-existing 14564/18888 pair, never checked their parentage.
