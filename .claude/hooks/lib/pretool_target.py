#!/usr/bin/env python3
"""Resolve WHICH git work tree a shell command line acts on.

Why this exists
---------------
pre-tool-use.sh used to ask `git branch --show-current` in its OWN cwd. When
Claude Code runs from the Windows desktop app, that cwd is a read-only mirror
clone that always sits on `develop` -- so the hook would have denied every
`git commit`, including ones aimed at a feature branch in WSL. The branch
that matters is the branch of the tree the COMMAND targets:

    wsl -e bash -c 'cd /var/www/x && git commit'      -> /var/www/x
    wsl.exe --cd /var/www/x -- git commit             -> /var/www/x
    git -C /var/www/x commit                          -> /var/www/x
    git commit                                        -> the session cwd

Contract (stdin = the command text, argv[1] = start cwd, may be empty):

stdout is SHELL CODE for the caller to `eval`, every value shell-quoted, led by
the marker line `#REGISTRO_RESOLVER_OK` (the caller evals nothing without it):

    INV_KIND=(...) INV_DIR=(...) INV_BRANCH=(...) INV_NOTE=(...)   parallel arrays,
                                one entry per real invocation found
    SINK=0|1                    code is built/run dynamically
    RERR='...'                  non-empty: the command could not be analysed

kind is commit | push-main | pr-create. A dir is empty when the target could
not be determined (the note says why). A branch is empty for a detached HEAD
and `?` when the dir is not a git work tree. (This used to be records joined
by 0x1f; a directory name containing 0x1f corrupted them. Quoting cannot.)

The rule that shapes everything here: never turn "I could not tell" into
"there is nothing here". An undeterminable target is reported as such and the
caller denies; it is never guessed.
"""
import os
import re
import shlex
import subprocess
import sys

SENT = "﷐"  # marks "this word contains an expansion we cannot evaluate"
# $NAME is NOT expanded while lexing: `d=/x; git -C $d commit` assigns and uses
# the variable inside one script, so the value is only known when the command
# is reached. The lexer leaves a reference; expand() resolves it in order.
VREF0, VREF1 = "﷑", "﷒"
VREF = re.compile(VREF0 + "([^" + VREF1 + "]*)" + VREF1)
ASSIGN = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*=")
IDENT = re.compile(r"[A-Za-z_][A-Za-z0-9_]*")
SHELLS = {"bash", "sh", "dash", "zsh", "ksh"}
SKIP_WORDS = {"if", "then", "else", "elif", "do", "while", "until", "!", "{", "}",
              "fi", "done", "esac", "time", "exec", "command", "builtin", "nohup"}


class ParseError(Exception):
    pass


# --------------------------------------------------------------------------
# Lexer: a shell command line -> simple commands
# --------------------------------------------------------------------------
def find_close_paren(s, i):
    """s[i-1] is '(' (already consumed). Return the index of its matching ')'."""
    depth = 1
    n = len(s)
    while i < n:
        c = s[i]
        if c == "\\":
            i += 2
            continue
        if c == "'":
            j = s.find("'", i + 1)
            if j < 0:
                raise ParseError("unterminated quote")
            i = j + 1
            continue
        if c == '"':
            i += 1
            while i < n and s[i] != '"':
                i += 2 if s[i] == "\\" else 1
            i += 1
            continue
        if c == "<" and s.startswith("<<", i) and not s.startswith("<<<", i):
            m = re.match(r"<<(-?)\s*(['\"]?)([A-Za-z0-9_]+)\2", s[i:])
            if m:
                strip = bool(m.group(1))
                delim = m.group(3)
                nl = s.find("\n", i)
                if nl < 0:
                    raise ParseError("unterminated heredoc")
                pos = nl + 1
                while pos <= n:
                    eol = s.find("\n", pos)
                    line = s[pos:] if eol < 0 else s[pos:eol]
                    if (line.lstrip("\t") if strip else line) == delim:
                        i = pos + len(line)
                        break
                    if eol < 0:
                        raise ParseError("unterminated heredoc")
                    pos = eol + 1
                else:
                    raise ParseError("unterminated heredoc")
                continue
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    raise ParseError("unbalanced parenthesis")


def lex(s, env):
    """Return ('cmd', dict) / ('open',) / ('close',) items."""
    items = []
    cur = {"words": [], "subs": [], "heredocs": []}
    buf = []
    st = {"inword": False, "skip": False, "here": False}
    pending = []  # (cmd dict, delim, strip)
    n = len(s)
    i = 0

    def end_word():
        if not st["inword"]:
            return
        w = "".join(buf)
        buf.clear()
        st["inword"] = False
        if st["skip"]:
            st["skip"] = False
            return
        if st["here"]:
            st["here"] = False
            cur["heredocs"].append(w)
            return
        cur["words"].append(w)

    def end_cmd():
        nonlocal cur
        end_word()
        if cur["words"] or cur["subs"] or cur["heredocs"] or any(p[0] is cur for p in pending):
            items.append(("cmd", cur))
        cur = {"words": [], "subs": [], "heredocs": []}

    def dollar(i, in_dq):
        """s[i] == '$'. Append to buf, return the new index."""
        nxt = s[i + 1] if i + 1 < n else ""
        if nxt == "(":
            if s.startswith("$((", i):
                j = find_close_paren(s, i + 2)
                buf.append(SENT)
                return j + 1
            j = find_close_paren(s, i + 2)
            cur["subs"].append(s[i + 2:j])
            buf.append(SENT)
            return j + 1
        if nxt == "{":
            j = s.find("}", i + 2)
            if j < 0:
                raise ParseError("unterminated ${")
            name = s[i + 2:j]
            buf.append(VREF0 + name + VREF1 if IDENT.fullmatch(name) else SENT)
            return j + 1
        if nxt == "'" and not in_dq:
            j = i + 2
            while j < n and s[j] != "'":
                j += 2 if s[j] == "\\" else 1
            if j >= n:
                raise ParseError("unterminated $'")
            buf.append(s[i + 2:j])
            return j + 1
        m = IDENT.match(s, i + 1)
        if m:
            buf.append(VREF0 + m.group(0) + VREF1)
            return m.end()
        if nxt and (nxt.isdigit() or nxt in "?$!@*#-"):
            buf.append(SENT)
            return i + 2
        buf.append("$")
        return i + 1

    def read_heredocs(i):
        for cmd, delim, strip in pending:
            lines = []
            while True:
                if i > n:
                    raise ParseError("unterminated heredoc")
                eol = s.find("\n", i)
                line = s[i:] if eol < 0 else s[i:eol]
                i = n + 1 if eol < 0 else eol + 1
                if (line.lstrip("\t") if strip else line) == delim:
                    break
                lines.append(line)
                if eol < 0:
                    raise ParseError("unterminated heredoc")
            cmd["heredocs"].append("\n".join(lines))
        pending.clear()
        return min(i, n)

    while i < n:
        c = s[i]
        if c in " \t":
            end_word()
            i += 1
        elif c == "\n":
            end_cmd()
            i = read_heredocs(i + 1) if pending else i + 1
        elif c == "\\":
            if i + 1 < n and s[i + 1] == "\n":
                i += 2
                continue
            buf.append(s[i + 1] if i + 1 < n else "\\")
            st["inword"] = True
            i += 2
        elif c == "#" and not st["inword"]:
            j = s.find("\n", i)
            i = n if j < 0 else j
        elif c == "'":
            j = s.find("'", i + 1)
            if j < 0:
                raise ParseError("unterminated single quote")
            buf.append(s[i + 1:j])
            st["inword"] = True
            i = j + 1
        elif c == '"':
            st["inword"] = True
            i += 1
            while True:
                if i >= n:
                    raise ParseError("unterminated double quote")
                d = s[i]
                if d == '"':
                    i += 1
                    break
                if d == "\\" and i + 1 < n and s[i + 1] in '$`"\\\n':
                    if s[i + 1] != "\n":
                        buf.append(s[i + 1])
                    i += 2
                elif d == "$":
                    i = dollar(i, True)
                elif d == "`":
                    j = s.find("`", i + 1)
                    if j < 0:
                        raise ParseError("unterminated backtick")
                    cur["subs"].append(s[i + 1:j])
                    buf.append(SENT)
                    i = j + 1
                else:
                    buf.append(d)
                    i += 1
        elif c == "$":
            st["inword"] = True
            i = dollar(i, False)
        elif c == "`":
            j = s.find("`", i + 1)
            if j < 0:
                raise ParseError("unterminated backtick")
            cur["subs"].append(s[i + 1:j])
            buf.append(SENT)
            st["inword"] = True
            i = j + 1
        elif c in "<>" and i + 1 < n and s[i + 1] == "(":
            j = find_close_paren(s, i + 2)  # process substitution
            cur["subs"].append(s[i + 2:j])
            buf.append(SENT)
            st["inword"] = True
            i = j + 1
        elif c == "<" and s.startswith("<<<", i):
            if st["inword"] and "".join(buf).isdigit():
                buf.clear()
                st["inword"] = False
            end_word()
            st["here"] = True
            i += 3
        elif c == "<" and s.startswith("<<", i):
            if st["inword"] and "".join(buf).isdigit():
                buf.clear()
                st["inword"] = False
            end_word()
            m = re.match(r"<<(-?)[ \t]*(?:'([^']*)'|\"([^\"]*)\"|\\?([A-Za-z0-9_]+))", s[i:])
            if not m:
                raise ParseError("cannot read heredoc delimiter")
            delim = m.group(2) if m.group(2) is not None else (
                m.group(3) if m.group(3) is not None else m.group(4))
            pending.append((cur, delim, bool(m.group(1))))
            i += m.end()
        elif c in "<>":
            if st["inword"] and "".join(buf).isdigit():
                buf.clear()
                st["inword"] = False
            end_word()
            i += 1
            while i < n and s[i] in "<>&|":
                i += 1
            st["skip"] = True  # the redirect target is not an argument
        elif c in ";&|":
            end_cmd()
            while i < n and s[i] in ";&|":
                i += 1
        elif c == "(" and not st["inword"]:
            end_cmd()
            items.append(("open",))
            i += 1
        elif c == ")":
            end_cmd()
            items.append(("close",))
            i += 1
        else:
            buf.append(c)
            st["inword"] = True
            i += 1
    if pending:
        raise ParseError("heredoc body missing")
    end_cmd()
    return items


# --------------------------------------------------------------------------
# Path handling
# --------------------------------------------------------------------------
def dyn(w):
    return SENT in w


def expand(w, env):
    """Resolve $NAME references against what has been assigned so far."""
    return VREF.sub(lambda m: env.get(m.group(1), SENT), w)


def to_wsl(p):
    """Windows-style or Git-Bash-style path -> WSL path. Others unchanged."""
    q = p.replace("\\", "/")
    m = re.match(r"^([A-Za-z]):/?(.*)$", q)
    if m:
        return "/mnt/%s/%s" % (m.group(1).lower(), m.group(2)) if m.group(2) else "/mnt/%s" % m.group(1).lower()
    m = re.match(r"^//wsl(?:\.localhost|\$)/[^/]+(/.*)?$", q)
    if m:
        return m.group(1) or "/"
    m = re.match(r"^/([A-Za-z])(/.*)?$", q)
    if m and os.path.isdir("/mnt/%s" % m.group(1).lower()) and not os.path.isdir(q):
        return "/mnt/%s%s" % (m.group(1).lower(), m.group(2) or "")
    return q


def resolve(path, cwd):
    """Directory `path` (as typed) -> absolute WSL path, or None if unknown."""
    if dyn(path):
        return None
    home = os.environ.get("HOME", "")
    if path == "~" or path.startswith("~/"):
        if not home:
            return None
        path = home + path[1:]
    elif path.startswith("~"):
        return None
    path = to_wsl(path)
    if path.startswith("/"):
        return os.path.normpath(path)
    if cwd is None:
        return None
    return os.path.normpath(os.path.join(cwd, path))


# --------------------------------------------------------------------------
# Analysis
# --------------------------------------------------------------------------
class Ctx:
    def __init__(self):
        self.invs = []   # (kind, dir, note)
        self.sink = False
        self.errs = []


def base(w):
    b = os.path.basename(w.replace("\\", "/"))
    return b[:-4] if b.lower().endswith(".exe") else b


def parse_git(args, cwd, ctx, env_unknown):
    d = cwd
    note = "" if cwd is not None else "working directory unknown"
    if env_unknown:
        d, note = None, "GIT_DIR / GIT_WORK_TREE is set for this command"
    sub = None
    rest = []
    i = 0
    while i < len(args):
        a = args[i]
        if a == "-C":
            if i + 1 >= len(args):
                break
            d2 = resolve(args[i + 1], d)
            if d2 is None:
                note = "git -C target '%s' is a shell expansion or unresolvable" % args[i + 1].replace(SENT, "<expansion>")
            d = d2
            i += 2
        elif a in ("-c", "--exec-path", "--namespace", "--super-prefix", "--config-env"):
            i += 2
        elif a.startswith(("--git-dir", "--work-tree")):
            d, note = None, "--git-dir / --work-tree redirects git elsewhere"
            i += 1 if "=" in a else 2
        elif a.startswith("-"):
            i += 1
        else:
            sub, rest = a, args[i + 1:]
            break
    if sub is None:
        return
    if dyn(sub):
        ctx.sink = True
        return
    if d is None and not note:
        note = "directory unknown"
    if sub == "commit":
        ctx.invs.append(("commit", d, note))
    elif sub == "push":
        if any(r == "main" or r == "refs/heads/main" or r.endswith(":main") for r in rest):
            ctx.invs.append(("push-main", d, note))


def parse_gh(args, cwd, ctx):
    pos = []
    i = 0
    while i < len(args) and len(pos) < 2:
        a = args[i]
        if a in ("-R", "--repo"):
            i += 2
        elif a.startswith("-"):
            i += 1
        else:
            pos.append(a)
            i += 1
    if pos == ["pr", "create"]:
        ctx.invs.append(("pr-create", cwd, "" if cwd is not None else "working directory unknown"))


def run_wsl(args, cwd, cmd, env, ctx, depth):
    wcwd, cdv, ok = cwd, None, True
    i = 0
    while i < len(args):
        a = args[i]
        if a in ("-d", "--distribution"):
            want = args[i + 1] if i + 1 < len(args) else ""
            here = os.environ.get("WSL_DISTRO_NAME")
            if here and want != here:
                ok = False
            i += 2
        elif a.startswith("--distribution="):
            here = os.environ.get("WSL_DISTRO_NAME")
            if here and a.split("=", 1)[1] != here:
                ok = False
            i += 1
        elif a in ("-u", "--user", "--shell-type", "--distribution-id"):
            i += 2
        elif a == "--cd":
            cdv = args[i + 1] if i + 1 < len(args) else ""
            i += 2
        elif a.startswith("--cd="):
            cdv = a[5:]
            i += 1
        elif a in ("-e", "--exec", "--"):
            i += 1
            break
        elif a.startswith("-"):
            ok = False  # unknown wsl flag: we cannot tell what it does
            i += 1
        else:
            break
    if cdv is not None:
        wcwd = resolve(cdv, wcwd)
    if not ok:
        wcwd = None
    rest = args[i:]
    if rest:
        handle(rest, cmd, wcwd, env, ctx, depth + 1)


def handle(words, cmd, cwd, env, ctx, depth):
    """Process one simple command; return the cwd for the commands after it."""
    if depth > 8:
        ctx.sink = True
        return cwd
    while words and words[0] in SKIP_WORDS:
        words = words[1:]
    if not words:
        return cwd
    w0 = words[0]
    if dyn(w0):
        ctx.sink = True
        return cwd
    b = base(w0).lower()
    args = words[1:]
    if b in ("cd", "pushd"):
        a = [x for x in args if x not in ("-L", "-P", "-e", "-@", "--")]
        if not a:
            return resolve("~", cwd)
        return None if a[0] == "-" else resolve(a[0], cwd)
    if b == "popd":
        return None
    if b in ("export", "declare", "local", "typeset", "readonly"):
        for x in args:
            if ASSIGN.match(x):
                k, v = x.split("=", 1)
                env[k] = v
        return cwd
    if b == "wsl":
        run_wsl(args, cwd, cmd, env, ctx, depth)
        return cwd
    if b in SHELLS:
        script = None
        i = 0
        while i < len(args):
            a = args[i]
            if a == "--":
                break
            if a.startswith("-") and not a.startswith("--") and "c" in a[1:]:
                script = args[i + 1] if i + 1 < len(args) else None
                break
            i += 1
        if script is not None:
            if dyn(script):
                ctx.sink = True
            else:
                analyze(script, cwd, dict(env), ctx, depth + 1)
        for body in cmd["heredocs"]:
            analyze(body, cwd, dict(env), ctx, depth + 1)
        if script is None and not cmd["heredocs"]:
            # `bash`, `bash file`, `... | bash`: the code it runs is not in this
            # command line. Mention-only commands never reach a verdict on this.
            ctx.sink = True
        return cwd
    if b == "eval":
        joined = " ".join(args)
        if dyn(joined):
            ctx.sink = True
        else:
            analyze(joined, cwd, dict(env), ctx, depth + 1)
        return cwd
    if b in ("source", "."):
        ctx.sink = True
        return cwd
    env_unknown = any(ASSIGN.match(w) and w.split("=", 1)[0] in ("GIT_DIR", "GIT_WORK_TREE") for w in cmd.get("prefix", []))
    for idx, w in enumerate(words):
        wb = base(w).lower()
        if wb == "git" and not dyn(w):
            parse_git(words[idx + 1:], cwd, ctx, env_unknown)
            break
        if wb == "gh" and not dyn(w):
            parse_gh(words[idx + 1:], cwd, ctx)
            break
    return cwd


def analyze(script, cwd, env, ctx, depth=0):
    if depth > 8:
        ctx.sink = True
        return
    stack = []
    for item in lex(script, env):
        if item[0] == "open":
            stack.append(cwd)
            continue
        if item[0] == "close":
            if stack:
                cwd = stack.pop()
            continue
        cmd = item[1]
        for sub in cmd["subs"]:
            analyze(sub, cwd, dict(env), ctx, depth + 1)
        words = [expand(w, env) for w in cmd["words"]]
        cmd["words"] = words
        k = 0
        prefix = []
        while k < len(words) and ASSIGN.match(words[k]):
            prefix.append(words[k])
            k += 1
        if k == len(words):
            for p in prefix:
                key, val = p.split("=", 1)
                env[key] = val
            continue
        cmd["prefix"] = prefix
        cwd = handle(words[k:], cmd, cwd, env, ctx, depth)


def branch_of(d):
    try:
        r = subprocess.run(
            ["git", "-c", "safe.directory=*", "-C", d, "symbolic-ref", "--short", "-q", "HEAD"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
    except Exception:
        return "?"
    if r.returncode == 0:
        return r.stdout.decode("utf-8", "replace").strip()
    if r.returncode == 1:
        return ""  # detached HEAD
    return "?"


def main():
    command = sys.stdin.read()
    start = sys.argv[1] if len(sys.argv) > 1 and sys.argv[1] else os.getcwd()
    cwd = os.path.normpath(to_wsl(start))
    ctx = Ctx()
    env = {"HOME": os.environ.get("HOME", "")}
    err = ""
    try:
        analyze(command, cwd, env, ctx)
    except ParseError as e:
        err = "the command could not be parsed (%s)" % e
        ctx.invs = []
    kinds, dirs, branches, notes = [], [], [], []
    for kind, d, note in ctx.invs:
        kinds.append(kind)
        if d is None:
            dirs.append("")
            branches.append("")
            notes.append(note or "directory unknown")
        else:
            dirs.append(d)
            branches.append(branch_of(d))
            notes.append("")

    def arr(name, vals):
        return "%s=(%s)" % (name, " ".join(shlex.quote(v) for v in vals))

    # Shell code for the caller to `eval`, every value shell-quoted. A delimited
    # record format (fields joined by 0x1f) let a directory name containing the
    # delimiter corrupt a record; quoting cannot be confused by its own data.
    # The first line is a marker the caller checks before it evals anything.
    print("#REGISTRO_RESOLVER_OK")
    print(arr("INV_KIND", kinds))
    print(arr("INV_DIR", dirs))
    print(arr("INV_BRANCH", branches))
    print(arr("INV_NOTE", notes))
    print("SINK=%d" % (1 if ctx.sink else 0))
    print("RERR=%s" % shlex.quote(err))
    return 0


if __name__ == "__main__":
    sys.exit(main())
