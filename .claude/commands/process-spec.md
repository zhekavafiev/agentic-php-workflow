---
description: Autonomous cycle — triage → routing by component → plan(s) → executor → parallel reviewers → final auditor → PR. The full pipeline.
---

# process-spec — orchestrator of the autonomous cycle

You are running the **autonomous development pipeline**: triage → **routing by component** → plan(s) → executor → parallel reviewers → final auditor → PR.

**Arguments**: `$ARGUMENTS` (optional — a specific spec filename, to skip triage).

No side effects: do not move tracker cards, do not open PRs by hand outside `pr-composer`.

> **Template note.** This repository ships five example agents (`spec-triage`, `planner-backend`, `executor-backend`, `reviewer-adr`, `janitor`). The orchestrator below describes the full shape of the loop, including the reviewer fan-out and the auditor/PR stages. Wire the extra reviewers (`reviewer-spec`, `reviewer-security`, `reviewer-perf`, `final-auditor`, `pr-composer`) to your own project's concerns, or trim the fan-out to the reviewers you have. The routing, verdict handling, human gate and iteration-cap logic are what matter and should be kept.

## Pipeline map

```
Step 1 — Triage (emits component + zone + api_contract)
   |
Step 2 — Route by component -> planning
   |
   +- backend ---> planner-backend --> Step 3 (show plan / gate) --> Step 4 (executor-backend)
   |                                                                  | SUCCESS
   |                                                              Steps 5-7 (review -> PR)
   |
   +- frontend --> planner-frontend --> Step 3 --> Step 4 (executor-frontend)
   |
   +- fullstack -> fixed: BE || FE  |  unfixed: BE -> FE --> Step 3F: STOP (plans ready)
   |
   +- product / unknown -----------------------------------> STOP with an explanation
```

(Tail of both executors: Step 5 — reviewers in parallel (set chosen by component) → Step 6 — final auditor → Step 7 — pr-composer → Step 8 — output; any BLOCK → Step 9 — BLOCK gate.)

---

## Step 1 — Triage (if no argument was passed)

**If `$ARGUMENTS` is empty:**

Call the `spec-triage` subagent via the Task tool:
> "Run the triage procedure as described in your system prompt. Output JSON on the last line."

Parse the JSON on the last line:

- `{"halt": "no_work", ...}` → print and finish:
  > **🟢 Queue empty.** Every spec in `spec-driven/` is blocked, has an open PR, or is waiting on a dependency.
  > Reason: `<reason>`

- `{"error": "...", ...}` → print the error verbatim and finish.

- `{"spec": "...", "id": "...", "component": "...", "zone": ..., "api_contract": ..., ...}` → remember `component`, `zone`, `api_contract` and go to Step 2.

**If `$ARGUMENTS` is non-empty (triage skipped):**

Use it as the spec filename. Confirm via `Bash` that the file exists in `spec-driven/`. If not, error out to the user.

Since triage was skipped, **extract the routing fields yourself**: `Read` the spec's `## Components` section and determine `component`, `zone`, `api_contract`. If the section is missing, warn and infer `component` from the paths in the spec (only `backend/` → backend; only `frontend/` → frontend; both → fullstack). If that fails — `unknown`.

---

## Step 2 — Route by component → planning

### `component = product` or `unknown`

Do not plan. Print and finish:
> **🟡 This spec is not eligible for the automatic cycle.**
> Component: `{component}`. `product` is manual (research/audit); `unknown` means there is no valid `## Components` section.
> **Next:** for `unknown` — add the `## Components` section (see `.claude/TASK_GUIDELINES.md`) and re-run. For `product` — this is a manual task.

### `component = backend`

Call the `planner-backend` subagent via the Task tool:
> "Plan implementation of spec `{filename}` according to your system prompt. Output the markdown plan document."

Handle the `## Verdict` (see "Handling the planner verdict" below).
If `READY_TO_IMPLEMENT` → go to **Step 3**.

### `component = frontend`

Call `planner-frontend`, passing the zone:
> "Plan implementation of spec `{filename}` according to your system prompt. Zone: `{zone}`. No backend API contract (pure frontend). Output the markdown plan document."

Handle the `## Verdict`. If `READY_TO_IMPLEMENT` → go to **Step 3**.

### `component = fullstack`

Look at `api_contract`:

**`api_contract = fixed`** (the contract is settled in the spec) — plan **in parallel**. Issue two Task calls in one message:
1. `planner-backend`: "Plan the backend part of spec `{filename}`. The API contract is fixed in the spec. Output the markdown plan."
2. `planner-frontend`: "Plan the frontend part of spec `{filename}`. Zone: `{zone}`. The API contract is fixed in the spec — extract it from the spec body. Output the markdown plan."

**`api_contract = unfixed` or `null`** — plan **sequentially**:
1. First `planner-backend`: "Plan the backend part of spec `{filename}`. Output the markdown plan, including the API contract (endpoints + request/response shapes) the frontend will consume."
2. Wait for the result. Extract the **API contract** from the backend plan.
3. Then `planner-frontend`, passing the contract: "Plan the frontend part of spec `{filename}`. Zone: `{zone}`. Backend API contract (use as source of truth, do not invent): \n\n`<extracted contract>`\n\n Output the markdown plan."

Handle the `## Verdict` of **both** plans (any BLOCKED → block). If both are `READY_TO_IMPLEMENT` → go to **Step 3F**.

> 💸 **Cost note.** In the parallel branch, both Opus planners run **before** verdicts are aggregated: if one returns `BLOCKED` and the other `READY`, the second one's work is sunk cost. The sequential branch avoids this because the backend verdict gates the frontend. Planning is not on the hot path, so the parallel branch buys ~1–2 minutes at the price of a possible double spend. When tokens become the bottleneck, make fullstack always sequential.

### Handling the planner verdict

Read the first `## Verdict` section of each planner's markdown:

- `BLOCKED_NEEDS_HUMAN` or `BLOCKED_NEEDS_REFINEMENT` (any planner, for fullstack) → print and finish:
  > **🛑 Plan blocked — a human decision is needed.**
  >
  > **Spec:** `{filename}` (component: `{component}`)
  >
  > `<the full plan markdown>`
  >
  > **Next:** fix the spec/ADR and re-run `/process-spec {filename}`.

- `READY_TO_IMPLEMENT` → continue down your branch.

---

## Step 3F — Fullstack STOP (plans ready, execution is the next phase)

Fires for `component = fullstack` after both plans return `READY_TO_IMPLEMENT`. Print both plans in full and finish **without invoking an executor**: the coordinated BE+FE execution path is not built. Do not move the spec out of `spec-driven/`.

---

## Step 3 — Show the plan → human gate → executor

Fires for `component ∈ {backend, frontend}`.

There are two supported gate policies. Pick one for your project and state it here:

**Policy A — supervised (recommended when adopting).** Print the plan and ask via `AskUserQuestion` whether to run the executor. Stop on "no".

**Policy B — auto-run on green (what a mature loop converges to).** If the planner returned `READY_TO_IMPLEMENT`, do **not** ask. The plan already passed the planner's sanity check and ADR validation; asking is friction. Print the plan for the record and go straight to Step 4. **Human control moves to the PR stage** — reviewing the diff before merge — with the reviewers, the final auditor and the BLOCK gate still ahead.

Either way, print:

> **✅ Plan ready (component: `{component}`).**
>
> **Spec:** `{filename}`
>
> **Summary:** domains touched · steps · tests · open risks
>
> `<the planner's full markdown output, as-is>`

**The human gate that is never optional is the one before merge** (Step 8): a person reads the diff and merges. Nothing in this pipeline merges to `main` on its own.

---

## Step 4 — Executor (implementation in a feature branch)

Pick the executor by `component` (`backend` → `executor-backend`, `frontend` → `executor-frontend`). **The executor↔component mapping is strict: a backend plan only ever goes to `executor-backend`.**

Call it via the Task tool, passing the spec filename and the full plan markdown:
> "Implement the following plan according to your system prompt. Spec filename: `{filename}`. Plan markdown:
>
> ---
> `<the full plan markdown>`
> ---
>
> Follow your procedure: create a feature branch, implement step by step, run tests with self-correct (max 3 iterations), commit and push. Output the final report at the end."

Read the Status in the report:
- `✅ SUCCESS` → go to **Step 5**
- `🟡 PARTIAL` / `❌ FAILED` → go to **Step 8** (halt section). Do not run reviewers on partial work.

---

## Step 5 — Review fan-out (reviewers in parallel)

Only when the executor Status is `✅ SUCCESS`. The reviewer set depends on `component`. All of them go out in **one message**, in parallel.

### `component = backend`
1. `reviewer-adr` — structure / ADR compliance
2. `reviewer-spec` — acceptance criteria
3. `reviewer-security` — OWASP / ownership / secrets
4. `reviewer-perf` — N+1 / indexes / unbounded queries

### `component = frontend`
1. `reviewer-spec` — acceptance criteria (same agent, reused)
2. `reviewer-frontend` — design system + responsive + client-side safety (pass the zone)
3. `reviewer-i18n` — localisation completeness
4. `reviewer-a11y` — baseline accessibility

Prompt shape (one per reviewer):
> "Review the diff on branch `agent/spec-{id}-{slug}`. Spec file: `{filename}`. Follow your system prompt. Output a structured markdown report."

**All reviewers are read-only.** They see a git diff and the spec; they never check out, edit or push.

### Aggregation

Wait for all of them. Extract each `## Verdict`:
- `PASS` — no blockers
- `PASS_WITH_CONCERNS` — minor notes, not blocking
- `BLOCK` — serious violations

**Aggregated reviewer verdict:**
- Any `BLOCK` → `🔴 BLOCK_BY_REVIEW` → go to **Step 9** (BLOCK gate)
- Any `PASS_WITH_CONCERNS` (no BLOCK) → `🟡 PASS_WITH_CONCERNS` → go to Step 6
- All `PASS` → `🟢 PASS` → go to Step 6

---

## Step 6 — Final auditor (holistic view)

Runs **only** when the aggregated verdict ∈ {🟢 PASS, 🟡 PASS_WITH_CONCERNS}.

Call `final-auditor`, passing the branch name, the spec filename, the plan summary, the executor report and **all reviewer reports in full**:
> "Holistic final review of branch `agent/spec-{id}-{slug}` for spec `{filename}`. Plan, executor report and all reviewer reports are provided. Follow your system prompt — find what the individual narrow reviewers missed. Output structured markdown."

Read the `## Verdict`:
- `READY_FOR_PR` → Step 7
- `CONCERNS_NOTED` → Step 7 (the concerns go into the PR description)
- `BLOCKED_HOLISTIC` → **Step 9** (BLOCK gate)

---

## Step 7 — PR composer

Runs **only** when the final auditor verdict ∈ {READY_FOR_PR, CONCERNS_NOTED}.

Call `pr-composer` with **all accumulated context** (branch, spec, plan summary, executor report, all reviewer reports, the auditor report, the aggregated verdict):
> "Compose and create a GitHub PR for branch `agent/spec-{id}-{slug}` based on spec `{filename}` and the context below. Follow your system prompt. Create the PR via `gh pr create`. Return the PR URL."

- Status `✅ PR_CREATED` → Step 8 with the PR URL
- halt → print the halt reason and stop

---

## Step 8 — Final output

### SUCCESS, all verdicts green, PR created

> **🟢 Spec implemented, passed every review layer, PR open.**
>
> **PR:** [{title}]({url}) (#{N}) · **Branch:** `agent/spec-{id}-{slug}`
> **Verification:** `make test-unit` green · `make psalm` clean · `make deptrac` clean
> **Self-correct iterations:** X/3
>
> `<all reviewer reports + the final auditor report>`
>
> **🛑 Next (human gate):**
> 1. Open the PR
> 2. Read the diff
> 3. Merge → run `/janitor`, which archives the spec into `spec-driven-completed/` so triage stops picking it up

### SUCCESS with concerns

Same, but list the concerns above the reports and say plainly that they must be read before merge.

### PARTIAL / FAILED (executor halted)

> **🟡 The executor stopped before finishing.** Reviewers were NOT run — there is no point reviewing partial work.
>
> `<the executor's full report, including the HALT reason and the suggested next action>`
>
> The branch still exists with partial changes (it was not deleted). Read the halt reason and decide: continue manually / re-plan / drop.

---

## Step 9 — BLOCK gate (three options)

Fires when the aggregated reviewer verdict is `🔴 BLOCK_BY_REVIEW`, or the final auditor returned `BLOCKED_HOLISTIC`.

Print a summary of the blockers (finding title + source reviewer), then the full reports, then ask via `AskUserQuestion`:

| Option | Description |
|---|---|
| **A. I (the orchestrator) fix it now** | Read the blockers, edit the feature branch, commit, push, re-review. For 1–3 small, pinpoint findings. |
| **B. Re-plan and restart the pipeline** | Halt. You fix the spec against the blocker feedback and re-run `/process-spec {filename}`. For architectural or scope findings. |
| **C. Halt — I'll take it from here** | The cycle stops. The branch stays as-is. |

### Option A — fix + auto re-review with a cap of 3 iterations

> **Who fixes:** in Option A the **orchestrator** makes the edits. This is the single exception to "the orchestrator does not edit code" (hard rule 9). The executor is NOT re-invoked. Option A is only for small pinpoint fixes; architectural changes → Option B.

**Canonical fix-commit marker** (the iteration counter depends on it — the format is fixed, do not change it):
```
fix: address review blockers (iteration N)
```

**Spiral protection:** before starting a fix, count how many fix commits are already on the branch:
```bash
git log main..{branch_name} --grep='^fix: address review blockers' --oneline | wc -l
```
That number is `prev_count`. The current iteration is `N = prev_count + 1`.

- If `prev_count` ≥ 3 → **do NOT start another iteration.** Print:
  > **🔴 Reached the 3-iteration fix cap and blockers persist.** That is a signal that patching is not helping and the problem needs a re-plan.
  >
  > Automatic fixing is now disabled. Options: rewrite the spec, fix it manually in your IDE, or drop the branch and rethink the scope.

  And halt.

- If `< 3` → continue.

**Fix algorithm:**

1. Read every blocker from the reviewer and auditor reports
2. Make the edits via `Edit` (do not create new files outside the blockers' scope)
3. Run the verification for the component:
   ```bash
   make test-unit && make psalm && make deptrac
   ```
4. If verification is **red** → halt and print the error. **Do not fix again automatically** — that is a signal your fix broke something.
5. If **green** → commit + push using the canonical marker exactly (otherwise the counter desynchronises):
   ```bash
   git add -A backend/
   git commit -m "fix: address review blockers (iteration N)"
   git push
   ```
6. **Auto re-review** — run the same reviewer set in parallel again, then the final auditor if they all pass, then aggregate.
7. Branch on the re-review result:
   - **🟢 PASS + READY_FOR_PR** → the branch is ready for a PR. **Do not open the PR automatically.** Ask via `AskUserQuestion` whether to run `pr-composer`.
   - **🟡 PASS_WITH_CONCERNS + CONCERNS_NOTED** → same, with a warning.
   - **🔴 BLOCK again** → return to the top of Option A with the new blockers. `prev_count` is recomputed from git log and will stop the loop at 3 on its own. Do not keep a separate counter — the single source of truth is the git log of the canonical marker.

---

## Orchestrator hard rules

1. **Task tool budget**: spec-triage (if needed) + planner(s) + one executor + the reviewer fan-out in parallel + final-auditor + pr-composer.
2. **Routing (Step 2) determines the path.** The executor↔component mapping is strict. Never mix a backend plan into a frontend executor.
3. **Reviewers (Step 5) run ONLY when the executor Status is ✅ SUCCESS.** On PARTIAL/FAILED, skip review and go to Step 8.
4. **Reviewers always run in parallel** (one message, N Task calls). Never sequentially.
5. **The final auditor (Step 6) runs ONLY when the aggregated reviewer verdict ∈ {PASS, PASS_WITH_CONCERNS}.**
6. **pr-composer (Step 7) runs ONLY when the auditor verdict ∈ {READY_FOR_PR, CONCERNS_NOTED}.**
7. **On BLOCK_BY_REVIEW or BLOCKED_HOLISTIC, always show the three-option gate (Step 9).** Do not skip straight to halt.
8. **No side effects** — do not edit files yourself (exception: Step 9 Option A), do not touch external trackers, do not write to git outside Step 9.A.
9. **Never re-interpret the plan aggressively** — hand it to the executor as-is.
10. **Never run the executor when the Verdict ≠ `READY_TO_IMPLEMENT`.**
11. **Never "continue" automatically after an executor HALT.** A halt hands control to a human.
12. **Fix-iteration cap = 3**, counted from the canonical commit marker via git log. At 3+, halt: "patching is not helping, re-plan."
13. **If the test suite or static analysis is red after a fix — halt.** Do not "fix the fix" automatically. That is a regression signal and needs a human.
14. **Nothing in this pipeline merges to `main`.** The merge is always a human action.
