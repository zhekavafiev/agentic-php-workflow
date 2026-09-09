# 001 — Invoicing: notify the customer when an invoice export fails

## Components

- **Component:** backend
- **Zone:** —
- **API contract:** —

## Problem statement

Invoice exports run asynchronously in a worker. When an export fails, the failure is written to the database and logged, and nothing else happens. The customer sees the export stuck in the `preparing` state indefinitely.

### What we see today

`ExportInvoiceHandler` (`backend/src/Invoicing/Application/Command/ExportInvoice/ExportInvoiceHandler.php`) catches `ExporterFailedException`, sets `InvoiceExport::markFailed($reason)` and returns:

```php
} catch (ExporterFailedException $e) {
    $export->markFailed($e->getMessage());
    $this->exports->save($export);
    $this->logger->error('Invoice export failed', ['export_id' => $export->id()]);
}
```

`grep -rn "InvoiceExportFailed" backend/src` returns nothing — the domain event does not exist, so nothing can subscribe to it. The entity already carries the failure reason: `private ?string $failureReason` at `InvoiceExport.php:41`, added in migration `Version20260410120000`.

The UI polls `GET /api/invoicing/exports/{id}` and renders `status`, but the `failed` status has no copy, so it falls through to the "preparing" spinner.

### Why this is bad for the business

Invoice export is used during the first week of the month to close books. A silent failure means the customer waits, then contacts support. Support tickets tagged `export` are the largest single driver of first-week volume, and every one of them is a failure that the customer could have been told about immediately.

---

## Scope

**In scope:**
1. A domain event `InvoiceExportFailed` published when an export fails.
2. An event subscriber that emails the customer once, with a one-hour cooldown per customer.
3. A cooldown store (interface + Doctrine implementation + migration) mirroring the existing `QuotaWarningStore` pattern in the Billing domain.
4. Tests as listed under Test plan.

**Out of scope:**
- Any UI change. The frontend rendering of the `failed` status is spec 002.
- Automatic retry of a failed export. Deliberately excluded: retry needs an idempotency decision on the exporter, which is not settled.
- Notifying anyone other than the invoice owner.

---

## Blockers

- `InvoiceExport` entity must expose `customerId()` and `failureReason()` as public methods. Verified: both exist at `InvoiceExport.php:88` and `:96`.
- The transactional mail transport must be configured in the worker environment. Verified in `backend/config/packages/mailer.yaml`.

---

## Do not parallelize with

- Spec 004 (`004-backend-invoice-export-retry.md`) — it touches the same `catch` block in `ExportInvoiceHandler`.

---

## Implementation plan

### Step 1 — Cooldown store

**File(s):**
- `backend/src/Invoicing/Domain/Repository/ExportFailureWarningStoreInterface.php` (new)
- `backend/src/Invoicing/Infrastructure/Persistence/DoctrineExportFailureWarningStore.php` (new)
- `backend/migrations/VersionYYYYMMDDHHMMSS.php` (new — table `export_failure_warnings`)

Mirror `Billing/Domain/Repository/QuotaWarningStoreInterface.php`. Two methods: `wasNotifiedRecently(string $customerId, \DateInterval $within): bool` and `markNotified(string $customerId): void`. The table has `customer_id` as primary key and `last_notified_at timestamptz NOT NULL`. The migration must implement `down()`.

### Step 2 — Domain event

**File(s):** `backend/src/Invoicing/Domain/Event/InvoiceExportFailed.php` (new)

`final readonly`, carrying `exportId`, `customerId`, `failureReason`, `occurredAt`.

### Step 3 — Publish the event

**File(s):** `backend/src/Invoicing/Application/Command/ExportInvoice/ExportInvoiceHandler.php` (modify)

Publish `InvoiceExportFailed` on the event bus inside the existing catch block, after `save()`. Do not change the catch semantics — the export still fails, it is not retried.

### Step 4 — Subscriber

**File(s):** `backend/src/Invoicing/Application/EventSubscriber/NotifyCustomerOnExportFailed.php` (new)

`final readonly`, `#[AsMessageHandler]`. Looks up the customer, checks the cooldown store, sends the email, calls `markNotified()`. Marks before sending — see Pitfall 2.

### Step 5 — DI registration

**File(s):** `backend/src/Invoicing/di.php` (modify)

Bind `ExportFailureWarningStoreInterface` → `DoctrineExportFailureWarningStore`. Register `NotifyCustomerOnExportFailed`.

### Step 6 — Tests

Files as listed in the Test plan below.

---

## Test plan / Acceptance criteria

- `phpunit tests/Invoicing` — green
- `make psalm` — clean
- `make deptrac` — clean
- `NotifyCustomerOnExportFailedTest` (L2) covers 5 cases: happy path, customer not found, cooldown active, cooldown expired, mail transport throws
- `DoctrineExportFailureWarningStoreTest` (L3) covers the store against a real database
- `ExportInvoiceHandlerTest` (L2) gains one case asserting the event is published on failure
- The migration runs up and down cleanly against the test database
- Manual smoke: force an export failure on staging, confirm one email arrives and a second failure within the hour sends nothing

### Regressions

- A successful export must not publish `InvoiceExportFailed` and must not touch the cooldown store.
- The existing `ExportInvoiceHandlerTest` happy path must remain green untouched.

---

## Pitfalls

1. **Cooldown hides subsequent failures.** With a one-hour window, a customer whose exports fail 100 times in a minute is told about one. Accepted: the email body says "there may be other failed exports in this period". A per-export notification would be worse.
2. **Mail failure after marking.** `markNotified()` is called *before* sending. If the transport then throws, the customer is never told. Accepted for now: the alternative (mark after send) risks a duplicate storm if the transport is slow, which is the worse failure. Revisit when the outbox pattern lands.
3. **Cooldown is per customer, not per export.** Intentional — the point is to avoid flooding a person, not to track exports.

---

## PR artifacts

- New: `InvoiceExportFailed` event, `ExportFailureWarningStoreInterface`, `DoctrineExportFailureWarningStore`, `NotifyCustomerOnExportFailed`, one migration
- Modified: `ExportInvoiceHandler`, `Invoicing/di.php`
- Tests: 3 new test classes, 1 new case in an existing class
- ADR updates: none — this follows the existing subscriber-with-cooldown pattern
