# Task guidelines — how specs are written

> **TL;DR:** write a spec in the template below, put it in `spec-driven/`, then run
> `/process-spec <filename>` to send it through planner → executor → reviewers → PR.
>
> The full pipeline and its philosophy live in `docs/PIPELINE.md`.

## Naming task files

Every task/spec file follows a strict pattern:
`[number]-[component]-[short-description].md`

**Allowed components:** `backend`, `frontend`, `fullstack`, `product`.

> The component in the filename must match the **Component** field in the mandatory `## Components` section (below).

**Examples:**
- `001-backend-invoice-export.md`
- `002-frontend-orders-dashboard-filters.md`
- `003-product-pricing-research.md`

## Where task files live

- By default → `spec-driven/`
- Explicitly backlogged → `spec-driven-backlog/`
- Completed (after merge) → `spec-driven-completed/`

## Completing a task

1. **Mark the title** — append `✅ DONE` to the end of the first line:
   ```
   # 001 — Invoicing: invoice export ✅ DONE
   ```
2. **Move the file** from `spec-driven/` to `spec-driven-completed/`:
   ```bash
   git mv spec-driven/001-backend-invoice-export.md spec-driven-completed/
   ```

The `janitor` agent does both automatically once the PR is merged.

## The `## Components` section (MANDATORY)

Every spec **must** carry a `## Components` section, immediately after the `# {N} — ...` title and before the problem description. It tells the pipeline (triage → orchestrator → planner) which layer the task belongs to and which planner should receive it.

The format is exactly these lines — machine-readable, parsed by triage:

```markdown
## Components

- **Component:** backend
- **Zone:** —
- **API contract:** —
```

**Allowed values:**

| Field | Values | When to fill it |
|---|---|---|
| **Component** | `backend` \| `frontend` \| `fullstack` \| `product` | always |
| **Zone** | `app` \| `public` \| `—` | only when the component is `frontend` or `fullstack`; otherwise `—` |
| **API contract** | `fixed` \| `unfixed` \| `—` | only when the component is `fullstack`; otherwise `—` |

**Semantics:**
- `backend` — changes only under `backend/` (domains, handlers, endpoints, migrations). Routed to `planner-backend`.
- `frontend` — changes only under `frontend/` (UI, pages, components). Routed to `planner-frontend`.
- `fullstack` — both. The orchestrator then looks at **API contract**.
- `product` — product research/audit/marketing (not code). Done by a human; code agents skip it.

**Zone** (frontend/fullstack) — which prescriptive frontend guide the planner should read:
- `app` — the authenticated application area
- `public` — the public site, landing pages, auth screens

**API contract** (fullstack only) — a hint to the orchestrator:
- `fixed` — endpoints and request/response shapes are already described in the spec. Backend and frontend are planned **in parallel**.
- `unfixed` — no contract yet. Planning is **sequential**: `planner-backend` first, and its API contract is fed to `planner-frontend`.
- empty / `—` for a fullstack spec → the orchestrator defaults to the **sequential** (safe) path.

## File structure

Every spec contains these sections, in this order:

```markdown
# {N} — {Domain}: {short title}

## Components

- **Component:** backend
- **Zone:** —
- **API contract:** —

## Problem statement

What is wrong or missing right now. Concretely — with code snippets, class names, grep output.

### What we see today

The current state: what exists, how it works, why it is a problem.

### Why this is bad for the business

The link to business metrics, user experience, or technical debt.

---

## Scope

**In scope:**
1. [Concrete deliverable #1]
2. [Concrete deliverable #2]

**Out of scope:**
- [What is explicitly excluded and why]

---

## Blockers

Preconditions before work can start. If none — write "None".

---

## Do not parallelize with

Files or tasks where a merge conflict is inevitable. If none — "None".

---

## Implementation plan

### Step 1 — [title]

**File(s):** `path/to/File.php` (new | modify)

[What to change: concretely, with class/method/field names.]

### Step 2 — ...

---

## Test plan / Acceptance criteria

- `phpunit tests/{Domain}` — green
- `make psalm` — clean
- `make deptrac` — clean
- [Other concrete checks]

### Regressions

- [What must not break]

---

## Pitfalls

1. **[Name]** — [description and how to avoid it]

---

## PR artifacts

- [List: new classes, migrations, tests, ADR updates]
```

## Quality rules

- **Every claim about the state of the code must be verified** (grep/read), never assumed. This is the **single most important rule** — a spec containing false claims will be rejected by the planner.
- Every new handler/subscriber/controller/entity → a mandatory test step in the plan.
- Concrete file paths, never "create a handler".
- `## Do not parallelize with` is filled in after checking: the other specs in `spec-driven/` (do they touch the same files?) and `gh pr list --state open` (any overlapping open PRs?).
- Scope = one developer, one PR. If the task is huge, narrow it and record the limits under **Out of scope**.
