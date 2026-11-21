---
name: feedback-wsl-unc-workflow
description: Registro working copy lives in WSL; read/edit via \\wsl.localhost UNC, but run greps/git/tests through a scratchpad script, not UNC
metadata:
  type: feedback
---

Edit/Read via `\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app\...` works, but recursive `grep`/`find` over `vendor/` or `docs/` through the UNC path from Git Bash times out (120s) and `git` there fails with "dubious ownership".

**Why:** the 9P/UNC bridge is very slow for directory walks; git refuses the foreign owner.

**How to apply:** put exploration, git, docker and test commands in a `.sh`/`.py` file in the session scratchpad and run it with `MSYS_NO_PATHCONV=1 wsl.exe -d Ubuntu-24.04 -- bash /mnt/c/.../scratchpad/x.sh`. Use `python3` scripts for multi-line source patches (heredoc with a quoted delimiter keeps the content literal). Run one test suite at a time (shared faked disk); the characterization edit test takes ~3 min, so use `run_in_background`.
