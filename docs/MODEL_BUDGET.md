# Model budget — which model does which job, and why

## The governing principle

**Expensive models where judgement is required. Cheap models where execution is required.**

Judgement means deciding whether something *should* be done, whether a spec is coherent, whether a change is correct as a whole. Execution means producing text that must satisfy rules someone else already wrote down. These are different jobs, and paying Opus rates for the second one is waste.

## Assignment

| Role | Model | Why |
|---|---|---|
| `spec-triage` | **Haiku** | Pure mechanics: list files, sort by prefix, one `gh` call, emit JSON. No judgement. Getting this wrong is cheap and immediately visible. |
| `planner-backend` | **Opus** | The highest-leverage step in the loop. It decides *what* gets built and whether the spec is even coherent. A bad plan costs an executor run, four reviewer runs, an audit and a human's attention — far more than the Opus delta. |
| `executor-backend` | **Sonnet** | Writing code against an explicit, ADR-cited plan is constrained work. The plan removes the ambiguity that would justify a stronger model, and the static-analysis gate catches what slips. |
| `reviewer-adr` | **Sonnet** | Checklist compliance against a written document. Mechanical, but needs enough capability to read a diff in context. |
| `reviewer-spec` | **Opus** | "Does this actually satisfy the acceptance criteria?" is a judgement call about intent, not a checklist. |
| `reviewer-security` | **Sonnet** | Pattern-shaped: ownership checks, secrets, injection, unvalidated input. |
| `reviewer-perf` | **Haiku** | Narrow and pattern-shaped: N+1 loops, missing indexes, unbounded queries. |
| `final-auditor` | **Opus** | Holistic. It exists to catch the failure mode where every file is individually fine and the change is collectively wrong — which is exactly what narrow reviewers cannot see. |
| `pr-composer` | **Sonnet** | Summarisation from supplied context. |
| `janitor` | **Haiku** | Three file operations behind a hard path guard. |

## Rough distribution

| Model | Share of runs | Share of spend |
|---|---|---|
| Haiku | ~30% | ~5% |
| Sonnet | ~50% | ~50% |
| Opus | ~20% | ~45% |

The shape that matters: **most of the work is cheap, most of the money is spent on deciding and on judging.**

## Cost controls that actually matter

1. **Gate the expensive model behind the cheap one.** Triage (Haiku) runs first and can end the whole cycle with `no_work` before a single Opus token is spent.
2. **Prefer sequential planning to parallel planning when either planner can block.** For a fullstack spec with an unsettled API contract, planning backend and frontend in parallel means that if the backend planner returns `BLOCKED`, the frontend planner's Opus run is sunk cost. Sequential planning gates the second run on the first verdict and costs ~1–2 minutes of latency — and planning is not on the hot path, since a human reads the plan afterwards anyway.
3. **Cap the fix loop at 3 iterations.** Unbounded re-review is the single easiest way to burn a budget with nothing to show. Three failed rounds means the plan is wrong; re-plan instead of re-fixing.
4. **Halt on logical gaps rather than self-correcting.** A self-correct loop applied to a bad plan produces plausible code and consumes the budget twice.
5. **Read-only agents get read-only tools.** Beyond the safety benefit, a planner without Bash cannot burn tokens running the test suite "just to check".

## Re-tuning for your project

Move a role up a tier when its findings are consistently wrong or shallow; move it down when its findings are consistently mechanical. The assignment above is a starting point calibrated on a strict-typing PHP codebase with a large written ADR set — the stronger your written rules, the further down the tiers you can push execution roles.
