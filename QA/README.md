# QA — how test scenarios are written

## Principle

Scenarios are written from the point of view of a user or a business process, not a developer. A manual tester must understand **why** a step is performed, not only **what** to click.

Technical details (containers, consoles, SQL updates for setup) are removed from the QA actions. SQL stays only where it verifies a **result**, and every query carries a comment saying what it does.

Every file must be executable end to end by a manual tester: without console access to staging, without knowledge of the architecture, without write access to the database. Anything that needs a console or write access goes into a separate section, **What the developer prepares**.

> **Where this fits the loop.** The QA card is generated on merge from the spec's business-impact section — the planner drafts it, the final auditor reconciles it against the real diff. That is deliberate: by merge time nobody remembers what a change looks like from the outside, and a card written from the diff describes classes instead of behaviour.

---

## File structure

One file covers one connected flow — a chain of events from start to finish. A file may cover several related specs if they form one product chain (specs 012 + 013 both being about invoice export, say).

```
QA/
└── invoice-export-flow.md   # named after the flow, not the task and not the feature
```

### Mandatory sections

**1. What we are verifying** — three to five sentences on the business meaning. Explains why this flow exists. Ends with a line "If this flow is broken — …" naming concrete consequences, which is what tells the tester how much this matters.

**2. What the developer prepares before testing starts** — everything that must exist before handing over to QA:
- Test accounts (by convention `qa-{area}-{plan}@example.test`, with a shared staging password).
- Starting conditions (existing orders, invoice history, active promo codes, and so on).
- Access (read-only database credentials, admin channels, any test integrations).
- A developer contact for running console commands and making targeted data fixes during testing.

Without this section, scenarios stall on "UPDATE customers SET …", which a manual tester cannot perform.

**3. Scenarios** — numbered, each following this template:

```
## Scenario N — Title (describes the situation, not the action)

**Situation:** what is happening in the system, and why this case matters.

### Developer preparation   <-- only when needed; call it out explicitly
<what the developer must do beforehand or during:
SQL data fixes, console command runs, bulk fake records>

### QA action
<what the tester does: clicks in the UI, form input, a request copied into DevTools Console>

### Expected result
<what must happen — in the UI, in the API response in the Network tab, in a read-only SQL query>
```

**4. Pre-release checklist** — a flat list of every check from the scenarios, one per line.

---

## Rules

### Who does what

- **QA performs:** clicks in the UI, filling in forms, checking API responses through DevTools → Network, reading the database through a SQL client in read-only mode (SELECT only, for verification).
- **The developer performs** (always marked under "Developer preparation"):
  - Any `UPDATE` / `INSERT` / `DELETE` in the database.
  - Running console commands.
  - Shifting dates into the past (`due_at`, `created_at`, `grace_until`) to accelerate a scenario.
  - Bulk-seeding fake data (200 orders to test a limit, say).

If a scenario needs a customer's plan changed, QA does it **through the UI**, not through SQL. That is the real user path and it publishes the correct domain events — a SQL update silently skips them and tests a state the product cannot actually reach.

### QA tooling

- **Browser + DevTools** — the primary tool. Network for status codes and response DTO contents. Console when a fetch with an arbitrary query parameter is needed.
- **A SQL client** with read-only staging credentials — for verifying results via SELECT.
- Real accounts on any integrated channel — for verifying outbound messages. If a scenario needs a webhook simulated with curl, that moves into developer preparation.

### Style

- **Scenario title** describes the situation: _"Insufficient balance at charge time"_, not _"Top-up test"_.
- **Situation is mandatory.** Without it, nobody knows why the case exists.
- **SQL with comments** — every query opens with `-- what this query does`. SQL in QA scenarios is for verification (SELECT) only; write queries are marked "performed by the developer".
- **Expected result must be concrete**: a field value, an HTTP status, a UI state, the text of a message. Not _"everything works"_.
- **Happy path plus edge cases** — every flow needs at least one scenario where something goes wrong (insufficient funds, an expired period, a blocked account, a limit exceeded, a race).
- **Order scenarios simple to complex.** A scenario may depend on the previous one's result, stated explicitly: _"Precondition: account blocked from Scenario 3"_.

### What NOT to describe

- The architecture underneath (DDD, CQRS, event subscribers, which commands are dispatched) — it does not help anyone test.
- Internal class and handler names. The public contract is enough: URL, HTTP status, a JSON field, the text in the UI.
- Justification of business numbers (why 3 / 12 / 24 months of history). The tester verifies the fact, not the reasoning.

---

## Template

```markdown
# Invoice export flow

## What we are verifying

Customers export invoices for their accounting. The flow covers requesting an
export, generating the file asynchronously, and delivering the download link.
If this flow is broken, customers cannot close their books and will contact
support during the first week of every month — the highest-volume support driver.

## What the developer prepares before testing starts

- Accounts: `qa-export-free@example.test`, `qa-export-pro@example.test` (shared staging password)
- Each account has at least 3 paid and 1 cancelled invoice in the last 12 months
- Read-only database access
- A developer contact for running the export worker on demand

## Scenario 1 — A customer exports a period with existing invoices

**Situation:** the ordinary happy path, and the most frequent action in the flow.

### QA action
1. Log in as `qa-export-pro@example.test`
2. Go to Billing → Invoices
3. Pick the period "last 12 months", press Export

### Expected result
- A "preparing" state appears within 1 second
- Within 30 seconds the state becomes "ready" with a download link
- The downloaded file opens and contains exactly the invoices visible in the list

```sql
-- Confirms the export was recorded and completed for this customer
SELECT status, file_name, created_at FROM invoice_exports
WHERE customer_id = '<id>' ORDER BY created_at DESC LIMIT 1;
```

## Scenario 2 — A customer exports a period with no invoices

**Situation:** an empty result must be a clear message, not a broken file. ...

## Pre-release checklist

- [ ] Export with existing invoices produces a file matching the list
- [ ] Export of an empty period shows a clear empty-state message
- [ ] A free-plan account sees the upgrade prompt instead of the export button
```
