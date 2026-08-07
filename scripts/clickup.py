#!/usr/bin/env python3
"""
ClickUp REST v2 client.

Exists alongside the official MCP server (https://mcp.clickup.com/mcp) because
the two have wildly different budgets: MCP is capped at 50 calls per rolling
24h on Free Forever (300 on Unlimited+), while a personal token against the
REST API gets 100 requests per minute. Anything bulk -- building an epic with
subtasks out of an estimation, sweeping statuses, backfilling comments --
has to come through here or it exhausts the daily MCP allowance in one run.

Auth is the personal token in $CLICKUP_API_TOKEN. It is never echoed, not even
in error output.

Descriptions go through `markdown_content`. Verified against the live API:
markdown tables are converted into native ClickUp table widgets, but markdown
checkboxes (`- [ ] item`) are flattened into plain bullets -- a task
description cannot hold them, so anything that needs ticking belongs in a
ClickUp checklist instead. Reads come back as `markdown_description`.

This project is managed from the Registro space, so commands that would
otherwise sweep the whole workspace default to it and leave the ParaDocks
client work alone. Override with CLICKUP_SPACE_ID.

Usage:
    scripts/clickup.py whoami
    scripts/clickup.py tree
    scripts/clickup.py tasks <list_id>
    scripts/clickup.py task 86c7ynhf5
    scripts/clickup.py create <list_id> "Task name" --md wycena.md
    scripts/clickup.py create <list_id> "Subtask" --parent 86c7ynhf5
    scripts/clickup.py update <task_id> --md wycena.md --status "in progress"
    scripts/clickup.py comment <task_id> --file notes.md
    scripts/clickup.py subtasks <task_id>
    scripts/clickup.py search "fakturownia"
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

API = "https://api.clickup.com/api/v2"
TIMEOUT = 30
MAX_RETRIES = 4

# The space Registro is run from. Anything workspace-wide narrows to this by
# default; the same workspace also holds unrelated client work.
REGISTRO_SPACE = "901511693860"


class ClickUpError(Exception):
    pass


def token() -> str:
    tok = os.environ.get("CLICKUP_API_TOKEN", "").strip()
    if not tok:
        raise ClickUpError(
            "CLICKUP_API_TOKEN is not set.\n"
            "It lives in ~/.bashrc; a non-login shell will not have sourced it.\n"
            "Generate one at ClickUp > Settings > Apps > API Token."
        )
    return tok


def request(method: str, path: str, body: dict | None = None, query: dict | None = None) -> dict:
    url = f"{API}{path}"
    if query:
        # doseq: ClickUp takes repeated params such as space_ids[]= for filters
        url += "?" + urllib.parse.urlencode(query, doseq=True)

    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", token())
    req.add_header("Content-Type", "application/json")

    for attempt in range(MAX_RETRIES):
        try:
            with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
                raw = resp.read().decode()
                # A near-empty budget costs nothing to wait out here, and is far
                # cheaper than a half-finished bulk run.
                remaining = resp.headers.get("x-ratelimit-remaining")
                if remaining is not None and int(remaining) <= 2:
                    reset = int(resp.headers.get("x-ratelimit-reset", 0))
                    pause = max(0, reset - int(time.time())) + 1
                    if 0 < pause <= 70:
                        print(f"  rate limit nearly spent, pausing {pause}s", file=sys.stderr)
                        time.sleep(pause)
                return json.loads(raw) if raw else {}

        except urllib.error.HTTPError as e:
            payload = e.read().decode(errors="replace")

            if e.code == 429:
                reset = int(e.headers.get("x-ratelimit-reset", 0))
                pause = max(0, reset - int(time.time())) + 1
                pause = min(pause, 70) if pause > 0 else 2 ** attempt
                if attempt == MAX_RETRIES - 1:
                    raise ClickUpError("rate limited (429) and out of retries") from None
                print(f"  429, retrying in {pause}s", file=sys.stderr)
                time.sleep(pause)
                continue

            if e.code in (500, 502, 503, 504) and attempt < MAX_RETRIES - 1:
                time.sleep(2 ** attempt)
                continue

            # ClickUp reports failures as {"err": "...", "ECODE": "..."}; surface
            # that rather than a bare status line.
            try:
                err = json.loads(payload)
                detail = f"{err.get('err', payload)} [{err.get('ECODE', '-')}]"
            except json.JSONDecodeError:
                detail = payload[:400]
            raise ClickUpError(f"HTTP {e.code} on {method} {path}: {detail}") from None

        except urllib.error.URLError as e:
            if attempt < MAX_RETRIES - 1:
                time.sleep(2 ** attempt)
                continue
            raise ClickUpError(f"network error on {method} {path}: {e.reason}") from None

    raise ClickUpError(f"exhausted retries on {method} {path}")


def read_markdown(path: str) -> str:
    if path == "-":
        return sys.stdin.read()
    try:
        with open(path, encoding="utf-8") as fh:
            return fh.read()
    except OSError as e:
        raise ClickUpError(f"cannot read {path}: {e.strerror}") from None


def workspace_id() -> str:
    if env := os.environ.get("CLICKUP_TEAM_ID", "").strip():
        return env
    teams = request("GET", "/team").get("teams", [])
    if not teams:
        raise ClickUpError("token has access to no workspace")
    if len(teams) > 1:
        names = ", ".join(f"{t['id']} ({t['name']})" for t in teams)
        raise ClickUpError(f"several workspaces; set CLICKUP_TEAM_ID to one of: {names}")
    return teams[0]["id"]


# --------------------------------------------------------------------------
# commands
# --------------------------------------------------------------------------

def cmd_whoami(_args) -> None:
    user = request("GET", "/user")["user"]
    print(f"{user['username']} <{user['email']}>  id={user['id']}")
    for team in request("GET", "/team").get("teams", []):
        plan = request("GET", f"/team/{team['id']}/plan").get("plan_name", "?")
        print(f"  workspace {team['id']}  {team['name']}  [{plan}]")


def cmd_tree(_args) -> None:
    """Whole hierarchy in one shot -- id lookups are the usual reason to reach
    for this, and guessing them from the web UI is error-prone."""
    for team in request("GET", "/team").get("teams", []):
        print(f"workspace {team['id']}  {team['name']}")
        spaces = request("GET", f"/team/{team['id']}/space", query={"archived": "false"})
        for space in spaces.get("spaces", []):
            print(f"  space {space['id']}  {space['name']}")

            folders = request("GET", f"/space/{space['id']}/folder", query={"archived": "false"})
            for folder in folders.get("folders", []):
                print(f"    folder {folder['id']}  {folder['name']}")
                for lst in folder.get("lists", []):
                    print(f"      list {lst['id']}  {lst['name']}  ({lst.get('task_count', 0)} tasks)")

            loose = request("GET", f"/space/{space['id']}/list", query={"archived": "false"})
            for lst in loose.get("lists", []):
                print(f"    list {lst['id']}  {lst['name']}  ({lst.get('task_count', 0)} tasks)")


def cmd_task(args) -> None:
    # include_markdown_description is not the default, and without it the API
    # hands back `description` -- the same text flattened, with tables collapsed
    # into [table-embed:...] and formatting stripped. Reading that and
    # concluding the markdown was lost is an easy mistake to make.
    task = request(
        "GET",
        f"/task/{args.task_id}",
        query={"include_subtasks": "true", "include_markdown_description": "true"},
    )
    if args.json:
        print(json.dumps(task, indent=2, ensure_ascii=False))
        return

    print(f"{task['name']}")
    print(f"  id       {task['id']}")
    print(f"  url      {task['url']}")
    print(f"  status   {task['status']['status']}")
    print(f"  list     {task['list']['name']} ({task['list']['id']})")
    if task.get("assignees"):
        print(f"  assigned {', '.join(a['username'] for a in task['assignees'])}")
    if task.get("parent"):
        print(f"  parent   {task['parent']}")
    for sub in task.get("subtasks", []):
        print(f"    - {sub['id']}  {sub['name']}  [{sub['status']['status']}]")
    if body := task.get("markdown_description") or task.get("description"):
        print("\n" + "-" * 60)
        print(body if args.full else body[:1500] + ("\n[...]" if len(body) > 1500 else ""))


def cmd_create(args) -> None:
    body: dict = {"name": args.name}

    if args.md:
        body["markdown_content"] = read_markdown(args.md)
    elif args.description:
        body["markdown_content"] = args.description

    if args.parent:
        body["parent"] = args.parent
    if args.status:
        body["status"] = args.status
    if args.priority:
        body["priority"] = args.priority
    if args.tags:
        body["tags"] = [t.strip() for t in args.tags.split(",") if t.strip()]
    if args.assignee:
        body["assignees"] = [int(a) for a in args.assignee.split(",")]

    task = request("POST", f"/list/{args.list_id}/task", body=body)
    print(f"created {task['id']}  {task['url']}")


def cmd_update(args) -> None:
    body: dict = {}
    if args.md:
        body["markdown_content"] = read_markdown(args.md)
    if args.name:
        body["name"] = args.name
    if args.status:
        body["status"] = args.status
    if args.priority:
        body["priority"] = args.priority
    if not body:
        raise ClickUpError("nothing to update -- pass at least one of --md/--name/--status/--priority")

    task = request("PUT", f"/task/{args.task_id}", body=body)
    print(f"updated {task['id']}  {task['url']}")


def cmd_comment(args) -> None:
    text = read_markdown(args.file) if args.file else args.text
    if not text:
        raise ClickUpError("pass either --file or a text argument")
    request("POST", f"/task/{args.task_id}/comment", body={"comment_text": text, "notify_all": False})
    print(f"commented on {args.task_id}")


def cmd_tag(args) -> None:
    """Tags are space-level. Naming one that does not exist yet creates it, so a
    typo silently becomes a new tag rather than an error -- `tags` lists what is
    already there."""
    for name in (t.strip() for t in args.tags.split(",") if t.strip()):
        verb = "DELETE" if args.remove else "POST"
        request(verb, f"/task/{args.task_id}/tag/{urllib.parse.quote(name)}")
        print(f"{'removed' if args.remove else 'added'} {name}")


def cmd_tags(args) -> None:
    space = args.space or os.environ.get("CLICKUP_SPACE_ID", REGISTRO_SPACE)
    tags = request("GET", f"/space/{space}/tag").get("tags", [])
    for tag in sorted(tags, key=lambda t: t["name"]):
        print(tag["name"])


def cmd_subtasks(args) -> None:
    task = request("GET", f"/task/{args.task_id}", query={"include_subtasks": "true"})
    subs = task.get("subtasks", [])
    if not subs:
        print("(no subtasks)")
        return
    for sub in subs:
        print(f"{sub['id']}  [{sub['status']['status']:<12}]  {sub['name']}")


def cmd_tasks(args) -> None:
    query = {"archived": "false", "include_closed": str(args.closed).lower(), "subtasks": "true"}
    result = request("GET", f"/list/{args.list_id}/task", query=query)
    tasks = result.get("tasks", [])
    if not tasks:
        print("(no tasks)")
        return
    for task in tasks:
        marker = "  └ " if task.get("parent") else ""
        print(f"{task['id']}  [{task['status']['status']:<12}]  {marker}{task['name']}")


def cmd_search(args) -> None:
    """Filtered task list. ClickUp has no full-text task search on v2, so this
    matches names client-side over the tasks it can page through. Scoped to the
    Registro space unless --all-spaces, so client work stays out of the way."""
    team = workspace_id()
    needle = args.query.lower()
    found = 0
    space = os.environ.get("CLICKUP_SPACE_ID", REGISTRO_SPACE)

    for page in range(args.pages):
        query: dict = {"page": page, "subtasks": "true", "include_closed": str(args.closed).lower()}
        if not args.all_spaces:
            query["space_ids[]"] = [space]
        result = request("GET", f"/team/{team}/task", query=query)
        tasks = result.get("tasks", [])
        if not tasks:
            break
        for task in tasks:
            if needle in task["name"].lower():
                print(f"{task['id']}  [{task['status']['status']:<12}]  {task['name']}")
                print(f"          {task['url']}")
                found += 1
        if result.get("last_page"):
            break

    if not found:
        print(f"no open task matches {args.query!r} (searched {args.pages} page(s))")


def cmd_delete(args) -> None:
    task = request("GET", f"/task/{args.task_id}")
    if not args.yes:
        raise ClickUpError(
            f"would delete {task['id']} ({task['name']}) -- deletion is not reversible "
            f"through the API; re-run with --yes if that is what you want"
        )
    request("DELETE", f"/task/{args.task_id}")
    print(f"deleted {args.task_id}")


def main() -> int:
    parser = argparse.ArgumentParser(prog="clickup.py", description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="cmd", required=True)

    sub.add_parser("whoami", help="token identity, workspaces and plan").set_defaults(fn=cmd_whoami)
    sub.add_parser("tree", help="workspace > space > folder > list ids").set_defaults(fn=cmd_tree)

    p = sub.add_parser("task", help="show one task")
    p.add_argument("task_id")
    p.add_argument("--json", action="store_true", help="raw API payload")
    p.add_argument("--full", action="store_true", help="do not truncate the description")
    p.set_defaults(fn=cmd_task)

    p = sub.add_parser("create", help="create a task or subtask")
    p.add_argument("list_id")
    p.add_argument("name")
    p.add_argument("--md", help="markdown file for the description, or - for stdin")
    p.add_argument("--description", help="inline description instead of --md")
    p.add_argument("--parent", help="parent task id; makes this a subtask")
    p.add_argument("--status")
    p.add_argument("--priority", type=int, choices=[1, 2, 3, 4], help="1 urgent .. 4 low")
    p.add_argument("--tags", help="comma separated")
    p.add_argument("--assignee", help="comma separated user ids")
    p.set_defaults(fn=cmd_create)

    p = sub.add_parser("update", help="update a task")
    p.add_argument("task_id")
    p.add_argument("--md", help="markdown file for the description, or - for stdin")
    p.add_argument("--name")
    p.add_argument("--status")
    p.add_argument("--priority", type=int, choices=[1, 2, 3, 4])
    p.set_defaults(fn=cmd_update)

    p = sub.add_parser("comment", help="add a comment")
    p.add_argument("task_id")
    p.add_argument("text", nargs="?")
    p.add_argument("--file", help="read the comment from a file, or - for stdin")
    p.set_defaults(fn=cmd_comment)

    p = sub.add_parser("tag", help="add or remove tags on an existing task")
    p.add_argument("task_id")
    p.add_argument("tags", help="comma separated")
    p.add_argument("--remove", action="store_true")
    p.set_defaults(fn=cmd_tag)

    p = sub.add_parser("tags", help="list the tags defined in the space")
    p.add_argument("--space")
    p.set_defaults(fn=cmd_tags)

    p = sub.add_parser("subtasks", help="list subtasks")
    p.add_argument("task_id")
    p.set_defaults(fn=cmd_subtasks)

    p = sub.add_parser("tasks", help="list the tasks in a list")
    p.add_argument("list_id")
    p.add_argument("--closed", action="store_true", help="include closed tasks")
    p.set_defaults(fn=cmd_tasks)

    p = sub.add_parser("search", help="match tasks by name, within the Registro space")
    p.add_argument("query")
    p.add_argument("--pages", type=int, default=3)
    p.add_argument("--closed", action="store_true", help="include closed tasks")
    p.add_argument("--all-spaces", action="store_true", help="search the whole workspace")
    p.set_defaults(fn=cmd_search)

    p = sub.add_parser("delete", help="delete a task")
    p.add_argument("task_id")
    p.add_argument("--yes", action="store_true")
    p.set_defaults(fn=cmd_delete)

    args = parser.parse_args()
    try:
        args.fn(args)
    except ClickUpError as e:
        print(f"error: {e}", file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        return 130
    return 0


if __name__ == "__main__":
    sys.exit(main())
