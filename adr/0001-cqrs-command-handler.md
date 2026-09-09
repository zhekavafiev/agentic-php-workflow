# 0001 — CQRS: commands, queries, events and handlers

**Status:** Accepted

## Context

Business logic scattered across fat controllers and service classes has no natural unit of review. For an agentic loop this is fatal: a planner cannot say "add one handler and one test", and a reviewer cannot say "this handler does two things". We need a shape where one change equals one named, testable unit.

## Decision

All application logic goes through three buses.

| Bus | Purpose | Returns |
|---|---|---|
| **CommandBus** | State mutations | `void` |
| **QueryBus** | Reads | a DTO or a read model |
| **EventBus** | Fire-and-forget notification of something that happened | `void` |

### Structure

```
backend/src/{Domain}/
├── Domain/
│   ├── Entity/
│   ├── Event/
│   ├── Exception/
│   ├── ReadModel/
│   ├── Repository/          # interfaces only
│   └── ValueObject/
├── Application/
│   ├── Command/{Action}/    # {Action}Command.php + {Action}Handler.php
│   ├── Query/{Action}/      # {Action}Query.php + {Action}Handler.php + {Action}Dto.php
│   └── EventSubscriber/
├── Infrastructure/
│   ├── Http/Controller/{Action}/
│   └── Persistence/
└── di.php
```

### Rules

1. A handler is `final readonly` and has exactly one public method, `__invoke()`.
2. A command is a `final readonly` DTO of primitives and value objects. No entities, no services.
3. A query handler returns a **DTO or read model** — never a raw associative array. A DTO crossing an HTTP boundary implements `JsonResponseDto<TShape>`.
4. **Authorization and ownership are checked in the handler**, never in the controller. The controller is a delivery mechanism; the handler is where the rule lives, and it is the handler that is unit-tested.
5. A controller maps a request into a Command or Query, dispatches it, and maps the result into a response. Nothing else. It never catches domain exceptions — the exception subscriber converts them into HTTP responses.
6. **Every handler, subscriber, controller and repository implementation is explicitly registered in `src/{Domain}/di.php`**, with interface→implementation bindings. Nothing is auto-wired implicitly.
7. Domain events are declared in `Domain/Event/`, published by the aggregate or the command handler, and consumed by subscribers in `Application/EventSubscriber/`.
8. **Cross-domain access does not happen through entities.** A domain reaches another one through an application service, a domain event, or an identifier plus its own repository. Enforced by `tooling/deptrac.yaml`.
9. A subscriber that sends email or calls an external service accounts for idempotency and cooldown. Domain events can fire in bursts.

## Example

```php
// backend/src/Invoicing/Application/Command/ExportInvoice/ExportInvoiceCommand.php
final readonly class ExportInvoiceCommand
{
    public function __construct(
        public string $invoiceId,
        public string $requestedByCustomerId,
    ) {
    }
}
```

```php
// backend/src/Invoicing/Application/Command/ExportInvoice/ExportInvoiceHandler.php
#[AsMessageHandler]
final readonly class ExportInvoiceHandler
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private InvoiceExporterInterface $exporter,
        private EventBusInterface $eventBus,
    ) {
    }

    /**
     * @throws InvoiceNotFoundException
     * @throws InvoiceAccessDeniedException
     */
    public function __invoke(ExportInvoiceCommand $command): void
    {
        $invoice = $this->invoices->findById($command->invoiceId)
            ?? throw new InvoiceNotFoundException($command->invoiceId);

        // Ownership is checked here, not in the controller.
        if ($invoice->customerId() !== $command->requestedByCustomerId) {
            throw new InvoiceAccessDeniedException($command->invoiceId);
        }

        $this->exporter->export($invoice);
        $this->eventBus->publish(new InvoiceExported($invoice->id()));
    }
}
```

### Anti-patterns — do NOT do this

```php
// ❌ Ownership checked in the controller
if ($invoice->customerId() !== $user->id()) {
    throw new AccessDeniedHttpException();
}

// ❌ Controller catching domain exceptions
try {
    $this->commandBus->dispatch($command);
} catch (InvoiceNotFoundException $e) {
    return new JsonResponse(['error' => $e->getMessage()], 404);
}

// ❌ Handler returning a raw array
public function __invoke(GetInvoiceQuery $query): array
{
    return ['id' => $id, 'total' => $total];
}

// ❌ Reaching into another domain's entity
use App\Billing\Domain\Entity\Subscription;
```

## Consequences

**Good:** one change is one reviewable unit; ownership rules are unit-testable without HTTP; layer violations are caught mechanically by Deptrac; a planner can produce a file-by-file plan because the file layout is derivable from the change.

**Cost:** more files per feature, and a genuine learning curve for newcomers. Both are acceptable — the file count is what makes the change reviewable in the first place.
