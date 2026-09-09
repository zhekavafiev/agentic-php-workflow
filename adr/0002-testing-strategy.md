# 0002 — Testing strategy

**Status:** Accepted

## Context

Tests are the executor's feedback loop. An agent that cannot tell whether its change works will report success on the basis that the code looks right. So the test levels must be explicit enough that a *planner* can state, before any code exists, which test belongs to which new class — which is exactly what lets the executor halt when the plan does not name one.

## Decision

Four levels, each with a defined cost and a defined trigger.

| Level | What | Database | Speed | Runs |
|---|---|---|---|---|
| **L1** | Pure unit — value objects, entity invariants, pure functions | no | instant | every commit |
| **L2** | Handler/subscriber unit tests with mocked dependencies | no | fast | every commit |
| **L3** | Integration — real database, real repositories, real bus | yes | slow | CI, and before merge |
| **L4** | E2E smoke — HTTP request through the real kernel to a response | yes | slowest | CI |

`make test-unit` runs L1 + L2. `make test-integration` runs L3 + L4.

### What must be covered — the planner's table

Every new public object gets a test, and the planner is required to name it in the plan:

| Created | Required test |
|---|---|
| Command/Query handler | L2, all branches (happy path + every edge case) |
| EventSubscriber | L2 with mocks, plus an optional L3 for the serious ones |
| Controller / endpoint | L4 smoke (request → status + response shape) |
| Repository | L3 against the real database |
| Domain Entity / VO | L1 on factory invariants and methods |
| Domain Event | covered by the test of whatever publishes or consumes it |
| Migration | up and down executed against the test database |

**This table is enforced, not advisory.** `planner-backend` must produce a test step for every new public object; if it cannot, it records a 🟡 gap with a justification. `executor-backend` halts with `LOGIC_CONCERN: missing test step for {ClassName}` rather than inventing a test itself — an incomplete plan goes back to the planner, because an executor that writes its own tests writes tests that pass.

The single exception: a thin infrastructure adapter where a unit test asserts nothing but the mock. Allowed **only** when the plan explicitly flagged it and named the integration test that covers it instead.

### Naming and layout

Tests mirror the source tree:

```
backend/src/Invoicing/Application/Command/ExportInvoice/ExportInvoiceHandler.php
backend/tests/Invoicing/Application/Command/ExportInvoice/ExportInvoiceHandlerTest.php
```

Test method names state the case, not the mechanics: `testThrowsWhenInvoiceBelongsToAnotherCustomer()`, not `testInvoke2()`.

### Rules

1. **A modified method needs its new behaviour covered.** If the existing test does not exercise it, the plan adds a case. "It was already tested" is not true of behaviour that did not exist yesterday.
2. **Never disable a test to go green.** Commenting out or skipping a failing test is an explicit halt condition for the executor.
3. **Flaky tests are a halt, not a retry.** A test that passes sometimes destroys the feedback loop the whole cycle depends on.
4. **Test the branches, not the line count.** Coverage percentage is not a gate here; the branch table above is.
5. **L3 tests own their fixtures** and clean up after themselves. Order-dependent tests are flaky tests by another name.

## Consequences

**Good:** the executor has a hard, mechanical definition of done; the planner is forced to think about edge cases before implementation, which is where the sanity check usually catches contradictions; a reviewer can check test adequacy against a written table rather than an opinion.

**Cost:** planning takes longer, and specs that "just" tweak behaviour still carry test steps. That is the intended trade.
