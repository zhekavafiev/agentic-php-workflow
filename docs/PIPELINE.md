# Pipeline — from a task text to a merged PR

## Status legend

| Marker | Meaning |
|---|---|
| **BUILT** | Implemented and run on a real production project |
| **MANUAL GATE** | A human decision point — the loop stops here by design |
| **PLANNED** | Designed, not built |

## The concept

A closed development loop:

```
spec -> plan -> code -> tests -> review -> PR -> merge -> deploy -> monitoring -> feedback into the spec
```

Three things make it work without a human in the middle:

1. **A machine-readable source of truth** — specs, ADRs, tests, acceptance criteria
2. **A reliable feedback loop** — CI, types, tests, static analysis
3. **Isolation** — every iteration on its own feature branch, so a failure never touches `main`

The first one is the hard part, and it is the reason most teams cannot build this loop: a codebase without written architectural rules gives agents nothing to comply with, and nothing for reviewers to check against.

## Stages

### Stage 1 — Triage — **BUILT**
`spec-triage` (Haiku). Scans `spec-driven/`, drops indexes and manual research tasks, sorts by spec ID (oldest first), skips anything with an open or merged PR, and emits one line of JSON with the spec filename plus routing metadata (`component`, `zone`, `api_contract`). Read-only, no Task tool, at most 3–5 file reads.

### Stage 2 — Routing — **BUILT**
The orchestrator (`/process-spec`) reads the `## Components` section and picks the planner:

```
backend    -> planner-backend
frontend   -> planner-frontend (zone: app | public)
fullstack  -> contract fixed?  yes: BE || FE in parallel
                               no:  BE -> contract -> FE, sequentially
product / unknown -> STOP, explain why
```

### Stage 3 — Planner — **BUILT**
`planner-backend` (Opus). Reads the spec, reads the ADRs in full, scans the relevant existing code with Grep/Glob, and emits an ordered, file-by-file plan with ADR citations, a test plan, an acceptance-criteria mapping and a risk assessment.

Crucially it also runs a **sanity check**: does the spec contradict working code, miss side effects, or leave new public objects untested? A serious finding produces `BLOCKED_NEEDS_HUMAN` rather than a silent fix.

**No Bash tool.** A planner that cannot execute cannot accidentally change anything, and cannot substitute "I ran it and it worked" for reasoning.

Verdicts: `READY_TO_IMPLEMENT` · `BLOCKED_NEEDS_HUMAN` · `BLOCKED_NEEDS_REFINEMENT`.

### Stage 3.5 — Plan gate — **MANUAL GATE** (configurable)
Two supported policies: print the plan and ask before executing (recommended while adopting), or auto-run on a green plan and move human control to the PR diff. A blocked plan always stops for a human.

### Stage 4 — Executor — **BUILT**
`executor-backend` (Sonnet). Creates `agent/spec-{N}-{slug}`, implements the plan step by step, then runs `make fix-all` → `make test-unit` → `make psalm` → `make rector` → `make deptrac`.

**Self-correct: at most 3 iterations for the whole run.** Budget exhausted → halt with a structured report. A logical gap (a missing test, an uncovered edge case) is *not* self-correct territory: it halts as `LOGIC_CONCERN` and goes back to the planner, because patching around a bad plan is how agents produce plausible garbage.

The executor never opens a PR, never force-pushes, never touches `.env`, never uses `git stash` or `git reset --hard`.

### Stage 5 — Review fan-out — **BUILT**
Reviewers run **in parallel, in one message**, each read-only, each with a narrow mandate and an explicit "what you do NOT do" section so their findings do not overlap:

| Reviewer | Mandate | Model |
|---|---|---|
| `reviewer-adr` | Structure, DI registration, layer boundaries, naming, response contracts | Sonnet |
| `reviewer-spec` | Acceptance criteria actually met | Opus |
| `reviewer-security` | Ownership checks, secrets, injection, OWASP basics | Sonnet |
| `reviewer-perf` | N+1 queries, missing indexes, unbounded result sets | Haiku |

Aggregation: any `BLOCK` → BLOCK gate. Otherwise `PASS` / `PASS_WITH_CONCERNS` → the auditor.

### Stage 6 — Final auditor — **BUILT**
Opus, holistic. It receives the plan, the executor report and every reviewer report, and looks for what narrow reviewers structurally cannot see: the change is individually correct in every file and wrong as a whole. Verdicts: `READY_FOR_PR` · `CONCERNS_NOTED` · `BLOCKED_HOLISTIC`.

### Stage 7 — PR composer — **BUILT**
Sonnet. Composes the PR body from the accumulated context — what changed, why, the acceptance-criteria table, the review results, the remaining concerns, and a testing checklist for the human reviewer — then runs `gh pr create`.

### Stage 8 — Human review and merge — **MANUAL GATE**
A person reads the diff and merges. **Nothing in this pipeline merges to `main` on its own.** Target time for this gate: 5–10 minutes, which is the whole point of the PR body being pre-composed.

### Stage 9 — BLOCK gate — **MANUAL GATE**
On `BLOCK_BY_REVIEW` or `BLOCKED_HOLISTIC`, the orchestrator presents three options: (A) fix the blockers now and auto re-review, (B) re-plan the spec and restart, (C) halt and take it manually.

Option A is capped at **3 fix iterations**, counted from a canonical commit-message marker via `git log`, not from an in-memory counter that a context reset would lose. Three failed fix rounds is treated as evidence that the plan is wrong, not that the fix needs another try.

### Stage 10 — Janitor — **BUILT**
`janitor` (Haiku). After a merge, sweeps `spec-driven/` for specs with merged PRs, marks them done and `git mv`s them into `spec-driven-completed/`, so triage stops re-picking finished work. Its only permitted paths are those two directories.

### Stage 11 — QA handoff — **BUILT**
On merge, a QA card is generated from the spec's business impact — "what to test", in user language, no class names — following `QA/README.md`.

### Stage 12 — E2E smoke on staging — **PLANNED**
### Stage 13 — Production monitoring, 24h post-deploy — **PLANNED**
### Stage 14 — Git worktree isolation per run — **PLANNED**
### Stage 15 — Cron trigger for the whole loop — **PLANNED**
Today the loop is started by hand. Nothing prevents scheduling it; the reason it is not scheduled is that the manual gates are where the value is verified.

## Permission strategy

How to run an autonomous loop without drowning in permission prompts, in increasing order of trust:

0. **Allowlist** in `.claude/settings.json` — read-only commands, `make` targets and ordinary git verbs are pre-approved; destructive and network-mutating actions are not. See the shipped example.
1. **Hooks** — pre/post-tool checks that reject a command class outright rather than asking.
2. **Skip-permissions inside a disposable worktree** — acceptable only when the blast radius is a throwaway directory.
3. **A container with a network allowlist** — the strongest option, and the right one for an unattended cron trigger.

This template ships level 0. Levels 2 and 3 are what you add before removing the manual gates.
