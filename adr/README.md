# Architecture Decision Records

The rules for organising domains and working within them in this project.

**This file is the contract that makes the agentic loop possible.** `planner-backend` reads it in full before producing any plan and cites its section names in every step; `reviewer-adr` re-reads it on every run and checks the diff against it, citing the same section names back. That shared vocabulary is why a reviewer finding is actionable: "missing registration in `Invoicing/di.php`. ADR: DI configuration" tells the executor exactly what rule was broken and where it is written down.

Two consequences follow, and both matter more than they look:

- **If a rule is not written here, it is not a rule.** Reviewers are explicitly forbidden from inventing rules — an uncovered case is reported as "the ADRs do not cover this, recommend discussing", never as a finding. Unwritten conventions are invisible to the loop.
- **Section names are an API.** Renaming a section breaks every citation in every agent prompt that references it. Rename deliberately.

## Index

| ADR | Topic |
|---|---|
| [0001](0001-cqrs-command-handler.md) | CQRS: commands, queries, events and handlers |
| [0002](0002-testing-strategy.md) | Testing strategy — levels L2/L3/L4 |

## Sections a full ADR set should cover

The two ADRs in this template are examples of the shape. A real project's `adr/` covers roughly this ground — each section a stable, citable name:

- **Strict typing and the ban on raw arrays** — DTOs over associative arrays, `JsonResponseDto<TShape>` on HTTP boundaries
- **CI and static analysis** — which gates run, what a suppression requires, baseline policy
- **Message buses (CQRS + Events)** — CommandBus / QueryBus / EventBus and when each applies
- **Domain events** — structure, naming, aggregate identity
- **Domain structure** — `Domain/`, `Application/Command|Query`, `Application/EventSubscriber`, `Infrastructure/`
- **HTTP controllers** — `final readonly`, `__invoke`, route attributes, mapped request payloads
- **Error handling** — domain exceptions, HTTP status in the constructor, controllers never catch
- **Value objects and enums** — naming, private constructors, the VO-wraps-enum pattern
- **Identifiers (UUID)** — entities store `private string $id`; parsing happens only at the HTTP boundary
- **DI configuration** — every controller/handler/subscriber/repository explicitly registered
- **Authorization / ownership** — checked in the handler, not the controller
- **Webhook handling** — signature verification, response policy
- **Idempotency** — the patterns and where they are mandatory
- **Feature access** — how an endpoint is gated by plan or role
- **Cross-domain data access** — references, event-driven, and the one documented exception
- **Money handling** — a `Money` VO across domains, integer minor units in the database
- **Testing strategy** — see 0002
- **Async processes** — the registry of every cron, worker and console command

## Writing an ADR that agents can use

1. **Prescriptive, not descriptive.** "Handlers return a DTO, never a raw array" is usable. "We generally prefer DTOs" is not.
2. **Give every rule a stable heading.** That heading is what gets cited.
3. **Show the anti-pattern next to the pattern.** Agents pattern-match on examples far more strongly than on prose, so a wrong example must be unmistakably labelled as wrong.
4. **State where the rule is enforced.** A rule Psalm or Deptrac checks is worth more than one only a reviewer checks — say which it is.
5. **Record the reasoning for exceptions inline.** An unexplained exception reads as permission. See the comments in `tooling/psalm.xml` for the shape.
