# Guardrails — the patterns that keep an autonomous loop safe

An autonomous loop fails in a small number of predictable ways: an agent quietly widens its scope, an agent "fixes" a problem it should have escalated, an agent destroys uncommitted work, or a review stage rubber-stamps its own output. Each pattern below exists to close one of those.

## 1. Capability is granted, not assumed

Every agent's `tools:` frontmatter is the narrowest set that lets it do its job.

| Agent | Tools | What that prevents |
|---|---|---|
| `spec-triage` | `Read, Glob, Bash` | Cannot write. Bash exists only for `gh pr list`. |
| `planner-backend` | `Read, Glob, Grep` | **No Bash at all.** Cannot execute, cannot mutate, cannot substitute "I ran it" for reasoning. |
| `executor-backend` | `Read, Write, Edit, Glob, Grep, Bash` | The only agent that writes code — and the only one under a self-correct cap. |
| `reviewer-*` | `Read, Glob, Grep, Bash` | Bash is for `git diff` only. A reviewer that could edit would review its own fix. |
| `janitor` | `Read, Glob, Edit, Bash` | Edit is restricted by a path guard (below). |

**The planner having no Bash is the single highest-value line in this template.** A planner that can execute will, sooner or later, "verify" its plan by running something, and then reason from the outcome instead of from the code.

## 2. Reviewers are read-only and see only a diff

Every reviewer receives a branch name and reads `git diff main..{branch}`. They never check out, never edit, never run the suite.

Two reasons. First, an agent that can fix what it finds stops reporting and starts patching, and the human loses the finding. Second, a review is only meaningful if it is independent of the thing being reviewed — the executor already ran the tests, and the reviewer's job is to look at what the tests do not cover.

Each reviewer prompt also carries an explicit **"What you do NOT do"** section:

```markdown
## What you do NOT do
- ❌ You do not review business logic (that is `reviewer-spec`)
- ❌ You do not review security (that is `reviewer-security`)
- ❌ You do not write or edit code
```

Without it, every reviewer drifts into a general code review, findings triplicate, and the aggregate verdict becomes noise.

## 3. The janitor path guard

The janitor's job is three file operations. Its hard rules pin it to two directories:

> **🛑 Your only territory is `spec-driven/` and `spec-driven-completed/`.** Never read, edit or `ls` outside them: not `.claude/`, not `backend/`, not configs. If you feel like looking at something else — don't; it is not your job.

And, closing the other end:

> Nothing to sweep → just report "Nothing to sweep" and **finish**. Do not go looking for work, do not wander the repository.

The second half matters as much as the first. A cleanup agent with nothing to clean will otherwise find something to tidy.

## 4. Halt beats silent repair

Both the planner and the executor are explicitly told that catching a bad instruction is part of the job:

> **Common sense beats the letter of the plan.** If you realise the plan will break an existing flow or contradicts business logic — **HALT**, do not patch it in your head. The plan is not more sacred than working code.

Halts are structured, not prose: a reason code (`ADR_VIOLATION_IN_PLAN`, `SELF_CORRECT_BUDGET_EXHAUSTED`, `LOGIC_CONCERN`, `SANITY_CHECK_FAILED_AT_EXECUTOR`, …), what was done before the halt, the test state, and a suggested next action. A halt is a handoff, so it has to carry enough for the human to act in one read.

**A halt never rolls back.** The branch is left exactly as it was, so a human can inspect it.

## 5. Distinguish a typo from a logical gap

Self-correct is capped at **3 iterations for a whole executor run**, not per step — and it applies only to typos and small bugs.

A missing test, an uncovered edge case, a new public class with no test step in the plan: those halt as `LOGIC_CONCERN` and go back to the planner. Letting a self-correct loop chew on a logical gap is how an agent produces code that passes and is wrong.

## 6. Cap the fix spiral, and count it in git

The BLOCK gate's "orchestrator fixes it" path counts prior attempts from the commit history, not from memory:

```bash
git log main..{branch} --grep='^fix: address review blockers' --oneline | wc -l
```

The commit-message marker is canonical and must not be reworded. Three iterations reached means the fix is not helping: the loop refuses to start a fourth and demands a re-plan.

Counting in git rather than in the conversation means a context reset, a resumed session or a second orchestrator cannot restart the count at zero.

## 7. Destructive git is forbidden by name

Not "be careful with git" — an enumerated list:

- Never `git push --force`
- Never `git stash` (it hides work, and hidden work gets lost)
- Never `git reset --hard`, `git checkout -- <file>`, `git clean -f`
- Never `--no-verify` or any other hook bypass
- Never `sudo` or any privilege escalation; a permission error is a halt, not a problem to route around
- Never touch `.env`, secrets or keys — report the required variable and stop

Unexpected repository state is a halt condition, not something to normalise.

## 8. Scope is sacred

Stated positively and negatively, because agents are agreeable and will happily improve things nobody asked about:

> **What does NOT count as good execution:** "I added one more test just in case" · "I refactored the neighbouring class while I was there" · "I noticed the ADR has a better way and did it differently" · "I commented out the failing test so the suite passes"

## 9. Native tools over shell pipes

Agents are told to use `Glob`/`Grep`/`Read` rather than `ls | grep`, `find -exec` and inline `python3 -c`/`node -e` scripts. This is partly permission hygiene — each ad-hoc pipe is a fresh prompt — and partly that a shell one-liner is an unreviewable escape hatch out of the tool restrictions the agent was given.

## 10. The human gate is structural

Two gates are non-negotiable:

- **A blocked plan stops.** `BLOCKED_NEEDS_HUMAN` never proceeds to an executor.
- **Nothing merges to `main` automatically.** The PR is composed, the checklist is written, the reviews are attached — and a person clicks merge.

The plan-approval gate before execution is configurable: supervised while adopting, auto-run on a green plan once the loop has earned trust. The merge gate is not.
