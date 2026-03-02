# Architecture Decision Records (ADRs)

This directory contains Architecture Decision Records (ADRs) that document important architectural decisions made during the development and deployment of ParaDocks.

## What is an ADR?

An Architecture Decision Record captures an important architectural decision made along with its context and consequences. It provides:

- **Context**: The situation that led to the decision
- **Decision**: What was decided
- **Status**: Current status of the decision
- **Consequences**: The results, both positive and negative

## Why ADRs?

- Preserve the reasoning behind decisions
- Help new team members understand why things are the way they are
- Prevent revisiting already-settled decisions
- Track the evolution of the architecture
- Document alternative options that were considered

## ADR Format

Each ADR follows this structure:

```markdown
# ADR-XXX: Title

**Status**: Accepted | Proposed | Deprecated | Superseded
**Date**: YYYY-MM-DD
**Decision Makers**: Team/Individual
**Technical Story**: Link to issue/ticket if applicable

## Context

What is the issue we're seeing that is motivating this decision or change?

## Decision

What is the change that we're proposing and/or doing?

## Alternatives Considered

What other options were considered?

### Option A
- Pros
- Cons

### Option B
- Pros
- Cons

## Consequences

What becomes easier or more difficult to do because of this change?

### Positive
- Benefit 1
- Benefit 2

### Negative
- Drawback 1
- Drawback 2

### Neutral
- Side effect 1
- Side effect 2

## Implementation

How was this implemented? Commands, code samples, configuration changes.

## References

- Links to related documentation
- External resources
- Related ADRs
```

## Index of ADRs

| ADR | Title | Status | Date |
|-----|-------|--------|------|
| [ADR-001](ADR-001-ufw-docker-security.md) | UFW-Docker Security Integration | Accepted | 2025-11-11 |
| [ADR-002](ADR-002-storage-volume-removal.md) | Storage Volume Removal | Accepted | 2025-11-11 |
| [ADR-003](ADR-003-vite-manifest-symlink.md) | Vite Manifest Symlink Solution | Accepted | 2025-11-11 |

## When to Create an ADR

Create an ADR when:

- Making a significant architectural decision
- Choosing between multiple technical approaches
- Implementing a workaround that affects architecture
- Changing a previously accepted decision
- Adopting a new technology or framework
- Making security-related decisions
- Choosing infrastructure components

Do NOT create an ADR for:

- Minor bug fixes
- Routine maintenance
- Cosmetic changes
- Implementation details of already-decided architecture

## Creating a New ADR

1. **Copy Template**:
   ```bash
   cp ADR-000-template.md ADR-XXX-title.md
   ```

2. **Update Number**:
   - Use next sequential number
   - Never reuse numbers

3. **Fill in Content**:
   - Context: Why is this decision needed?
   - Decision: What are we doing?
   - Alternatives: What else did we consider?
   - Consequences: What are the trade-offs?

4. **Update This Index**:
   - Add entry to table above
   - Keep chronological order

5. **Commit**:
   ```bash
   git add docs/architecture/decision_log/ADR-XXX-*.md
   git commit -m "docs: Add ADR-XXX for [title]"
   ```

## ADR Status Workflow

```
    Proposed
       │
       ├──→ Accepted ──→ Implemented
       │         │
       │         └──→ Deprecated ──→ Superseded by ADR-YYY
       │
       └──→ Rejected
```

**Status Definitions**:

- **Proposed**: Under discussion, not yet decided
- **Accepted**: Decision made, pending implementation
- **Implemented**: Decision made and implemented
- **Deprecated**: No longer recommended, but still in use
- **Superseded**: Replaced by a newer ADR
- **Rejected**: Proposal was not accepted

## Superseding an ADR

When an ADR is superseded:

1. Update the old ADR status to "Superseded by ADR-XXX"
2. Add link to the new ADR
3. Explain why it was superseded
4. Keep the old ADR for historical reference (never delete)

Example:
```markdown
**Status**: ~~Accepted~~ Superseded by [ADR-010](ADR-010-new-approach.md)
**Superseded On**: 2025-12-01
**Reason**: New technology provided better solution
```

## Changing a Decision

To change an existing decision:

1. **Create a new ADR** (preferred):
   - Document new decision
   - Reference old ADR
   - Explain why change was needed

2. **Update existing ADR** (for minor amendments):
   - Add amendment section
   - Update consequences
   - Keep original decision visible

## Related Documentation

- [Technology Stack](../technology-stack.md)
- [Deployment Log](../../environments/staging/01-DEPLOYMENT-LOG.md)
- [Issues & Workarounds](../../environments/staging/05-ISSUES-WORKAROUNDS.md)

---

**Document Maintainer**: Architecture Team
**Last Updated**: 2025-11-11
