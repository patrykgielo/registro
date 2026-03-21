---
name: deep-research
description: Deep research using web-research-specialist agent with Firecrawl. Use when you need current information, documentation, or best practices.
argument-hint: "<research topic>"
disable-model-invocation: true
allowed-tools: Agent, Read, Grep, Glob
context: fork
agent: web-research-specialist
---

# /deep-research — Thorough Web Research

**Topic:** $ARGUMENTS

## Instructions

You are the `web-research-specialist` agent. Perform thorough research on the given topic.

### Sources to Search (in order):
1. **Official docs** — Firecrawl scrape of official documentation sites
2. **GitHub** — repos, issues, discussions related to the topic
3. **Community** — dev.to, medium.com, reddit.com, stackoverflow.com
4. **Social** — twitter.com/x.com for practitioner insights

### Research Process:
1. Search for the topic using multiple query variations
2. Scrape the most relevant 3-5 sources for detailed content
3. Cross-reference findings across sources
4. Identify consensus vs minority opinions
5. Extract actionable recommendations

### Output Format:

```
## Research: [Topic]

### Key Findings
1. [Most important finding with source]
2. [Second finding with source]
3. ...

### Recommendations for Registro
- [Actionable recommendation 1]
- [Actionable recommendation 2]

### Sources
- [URL] — [what was found]
```
