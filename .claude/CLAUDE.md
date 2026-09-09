# Project conventions

> Generic PHP (Symfony / Laravel) conventions for an agent-driven codebase.
> Replace the domain names with your own; keep the structure.
>
> The example domain used throughout this template is a small **orders & invoicing SaaS**:
> `Orders`, `Invoicing`, `Billing`, `Identity`, `Notification`, `Shared`.

## Agent usage

Launch an exploration subagent **only if** more than five files need investigating or the code area is unfamiliar. For pinpoint work (1–3 files, a well-understood area), read and fix it yourself. Delegating simple tasks burns tokens for nothing.

## Backend development

Before any backend task, refactor, or design change under `backend/`, read `adr/README.md` **in full**. It carries the project's fundamental architectural rules, which are non-negotiable. In particular:

- **CQRS and buses** — CommandBus / QueryBus / EventBus, and when to use which
- **Error handling** — domain exceptions extend `DomainException`; the HTTP status is set in the constructor; 4xx messages reach the client, 5xx are hidden; controllers never catch
- **Domain structure** — Domain / Application / Infrastructure, and the naming rules
- **Authorization / ownership** — checked in the handler, not the controller
- **Webhook pattern**, **idempotency**, **feature access**, **cross-domain access**

You do not need to read infrastructure code to learn the rules — everything is documented in the ADRs.

## Static analysis is not optional

Psalm, Rector, Deptrac and PHPUnit are mandatory for humans and agents alike.

```bash
make psalm        # full run
make psalm-diff   # only files changed against origin/main
make rector       # dry run
make deptrac      # layer boundaries
make test-unit
make check        # everything
```

**After completing any task — even a one-line fix — run `make check`.** If it is red, the task is not done. Fix it with types, not suppressions. A targeted `@psalm-suppress IssueType` is acceptable only with a comment explaining why. See `docs/STATIC_ANALYSIS.md`.

Write strictly typed code as you go, not "we'll fix it later". Checklist while writing:

- Raw DBAL fetches (`fetchAllAssociative`, `fetchAssociative`, `fetchOne`) — annotate the shape immediately (`@var array{col: type, ...}`). Without a shape, every key is `mixed`.
- Repository methods returning collections — declare `@return list<Entity>` on the interface (not `Entity[]`, not bare `array`).
- JSON decoded from an external API — type it with an `array{...}` shape right after `json_decode` / `$response->toArray()`.
- `@throws` on every public application/domain method that throws a domain exception or a DBAL exception.
- No `mixed` in public signatures. In private methods, only for a generic wrapper with `@template`.

### Raw associative arrays

An `@return array{...}` shape is allowed **only** in three cases:

1. **Locally inside one method** as a transitional form after `fetchAssociative()` / `json_decode()`, converted into an Entity / VO / DTO within a few lines. It never leaves the method.
2. **A private helper called by exactly one method of the same class**, which immediately constructs a DTO/Entity.
3. **A boundary infra endpoint with no business logic** (a liveness probe payload, say), with an explicit comment on why a DTO would be overkill.

**Forbidden:**
- Public methods returning an `array{...}` shape outward.
- Repository / Service / Projection / Store interfaces declaring `@return array{...}` or a structured `@return array<...>`.
- A raw associative array crossing a cross-domain, cross-layer or cross-class boundary. Between classes travel: Entity, `list<Entity>`, VO, Read Model or DTO.
- Public methods returning tuples (`array{X, Y}`). Two- and three-element tuples are private internals at best.

**Where things go:**

| What you need | Where it lives | Naming |
|---|---|---|
| Read model for your own domain | `{Domain}/Domain/ReadModel/` | no suffix (`InvoiceStats`) |
| Reference data read from another domain / a catalog | `{Domain}/Domain/References/` | `*ReadView` cross-domain; no suffix for catalogs (`PlanCatalogEntry`) |
| A domain VO (a meaningful value with invariants) | `{Domain}/Domain/ValueObject/` | `Money`, `Email` |
| Application response DTO for a controller | `{Domain}/Application/Query/.../*Dto.php` | `*Dto`, implements `JsonResponseDto` |
| Internal helper DTO next to a handler | beside the `*Handler.php` | by meaning |
| An external vendor payload | `{Domain}/Infrastructure/<vendor>/Dto/` | `*Request` / `*Response` |

## Task management

If asked to create, plan, describe or format a new task, read `.claude/TASK_GUIDELINES.md` FIRST and follow its structure, naming and directory rules strictly.

## QA scenarios

If asked to describe test scenarios for a flow or feature, read `QA/README.md` in full first. It defines the file structure, scenario naming, how to write SQL with comments, and what every section must contain. Files live in `QA/`, named after the flow (`invoice-export-flow.md`).

## Environment variables

Adding a new environment variable means updating **four places**:

1. `backend/.env` — local development value
2. `backend/.env.example` — documented placeholder (never a real secret)
3. the production compose/deployment manifest — pass-through into the container
4. the deployment workflow — the env list, the script block, and the `env:` block under the step

After that, add the secret manually in your CI provider's secret store. **Agents never write secrets** — an agent that needs a new variable reports the instruction and stops.

## Async processes and cron jobs

Keep a registry of all background processes at `adr/async-processes.md`.

**Mandatory:** whenever you implement a new cron, console command or worker, add an entry with the command or endpoint, what it does and why, and how often it runs (cron expression or daemon type).

## Frontend note

If your project has a frontend, mirror this file's structure for it: a prescriptive ADR set (`frontend/adr/`), a design system document, and explicit conventions per zone (authenticated app vs public site). The pipeline routes on those zones — see `.claude/TASK_GUIDELINES.md`.
