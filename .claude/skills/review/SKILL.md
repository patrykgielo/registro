---
name: review
description: Run code review on recent changes. Checks architecture, security, test coverage, and documentation gaps.
argument-hint: "[specific files or scope to review]"
disable-model-invocation: true
allowed-tools: Read, Grep, Glob, Bash, Agent
---

# /review — Code Review

## Step 1: Identify Scope

If `$ARGUMENTS` provided — review those files/scope.
Otherwise — review uncommitted changes:
```bash
git diff --name-only HEAD
git diff --cached --name-only
```

## Step 2: Architecture Review

For each changed PHP file, check:
- Does it follow existing patterns? (check sibling files for conventions)
- Single Responsibility — is the class/method doing too much?
- Are there hardcoded values that should be config/enum?
- Filament v4: correct namespace (`Schema` not `Form`, `Filament\Actions` not `Tables\Actions`)?

## Step 3: Security Review

For each changed file:
- Mass assignment: are fillable fields appropriate?
- SQL injection: raw queries with user input?
- XSS: unescaped output in Blade?
- Authorization: are policies/gates used where needed?
- FILESYSTEM_DISK: never `local`, always `public`

## Step 4: Test Coverage

- Are new features covered by tests?
- Are edge cases tested?
- Do factories exist for new models?

## Step 5: Documentation Check

- Were rules updated if patterns changed?
- Were docs updated if features/architecture changed?
- Was memory updated if significant for future sessions?

## Output Format

```
## Code Review Results

### Critical (must fix before merge)
- [issue + file:line + fix suggestion]

### Warnings (should fix)
- [issue + file:line]

### Suggestions (consider)
- [improvement idea]

### Documentation gaps
- [missing doc/rule/memory updates]
```
