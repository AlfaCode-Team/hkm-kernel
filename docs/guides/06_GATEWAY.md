# HKM Kernel — Gateway Layer

> The Gateway layer wraps **third-party vendor SDKs**. It translates between HKM Kernel's
> domain types and vendor-specific APIs. Vendor exceptions never escape this layer.

---

## Gateway Rules (ABSOLUTE)

| Rule | Detail |
|---|---|
| ONLY layer using vendor SDKs | No service, repository, or controller imports a vendor SDK |
| Catches ALL vendor exceptions | Translates to `GatewayException` before they escape |
| Zero business logic | No authorization, no domain rules, no event dispatch |
| Accepts domain types as input | `ChargeDTO` with `Money`, not raw `float $amount` |
| Returns domain-friendly result types | `ChargeResult`, not a raw Stripe object |
| Never calls DatabasePort | Gateways are for external APIs, not databases |
| Implements a declared contract | Each gateway implements an interface from `API/Contracts/` |

---

## Canonical Gateway Implementation

```php
<?php
declare(strict_types=1);

namespace InvoiceModule\Infrastructure\Gateways;

use InvoiceModule\Application\DTO\{ChargeDTO, ChargeResult};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

// Contract defined in API/Contracts/ — Service injects this interface
use InvoiceModule\API\Contracts\PaymentGatewayContract;

final class StripePaymentGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly \Stripe\StripeClient $stripe,
        private readonly string               $currency,
    ) {}

    public function charge(ChargeDTO $dto): ChargeResult
    {
        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount'         => $dto->amount()->amount(),   // cents
                'currency'       => $this->currency,
                'payment_method' => $dto->paymentMethodId(),
                'confirm'        => true,
                'metadata'       => ['invoice_id' => $dto->invoiceId()],
            ]);

            return match($intent->status) {
                'succeeded'       => ChargeResult::success($intent->id),
                'requires_action' => ChargeResult::requiresAction($intent->client_secret),
                default           => ChargeResult::failed($intent->status),
            };

        } catch (\Stripe\Exception\CardException $e) {
            // Translate — never let Stripe exception escape
            throw new GatewayException(
                'Card declined: ' . $e->getError()->message,
                layer:    'gateway.stripe.charge',
                context:  [
                    'decline_code' => $e->getError()->decline_code,
                    'charge_code'  => $e->getError()->code,
                ],
                previous: $e,
            );

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            throw new GatewayException(
                'Invalid payment request: ' . $e->getMessage(),
                layer:    'gateway.stripe.charge',
                context:  ['stripe_message' => $e->getMessage()],
                previous: $e,
            );

        } catch (\Stripe\Exception\ApiConnectionException $e) {
            throw new GatewayException(
                'Stripe API unreachable — payment could not be processed',
                layer:    'gateway.stripe.connection',
                context:  ['endpoint' => 'payment_intents'],
                previous: $e,
            );

        } catch (\Stripe\Exception\RateLimitException $e) {
            throw new GatewayException(
                'Stripe rate limit exceeded — retry after delay',
                layer:    'gateway.stripe.rate_limit',
                context:  [],
                previous: $e,
            );
        }
    }

    public function refund(string $transactionId, Money $amount): RefundResult
    {
        try {
            $refund = $this->stripe->refunds->create([
                'payment_intent' => $transactionId,
                'amount'         => $amount->amount(),
            ]);
            return RefundResult::success($refund->id);

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            throw new GatewayException(
                'Refund failed: ' . $e->getMessage(),
                layer:    'gateway.stripe.refund',
                context:  ['transactionId' => $transactionId],
                previous: $e,
            );
        }
    }
}
```

---

## Gateway Contract Pattern

```php
// Always define a contract in API/Contracts/ — Service injects the interface, not the class
interface PaymentGatewayContract
{
    public function charge(ChargeDTO $dto): ChargeResult;
    public function refund(string $transactionId, Money $amount): RefundResult;
}

// Result types — domain-friendly, no vendor types
final readonly class ChargeResult
{
    private function __construct(
        private bool    $success,
        private ?string $transactionId,
        private ?string $clientSecret,   // for 3D Secure redirect
        private ?string $failureReason,
    ) {}

    public static function success(string $transactionId): self
    {
        return new self(true, $transactionId, null, null);
    }

    public static function requiresAction(string $clientSecret): self
    {
        return new self(false, null, $clientSecret, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, null, $reason);
    }

    public function isSuccess(): bool          { return $this->success; }
    public function transactionId(): ?string   { return $this->transactionId; }
    public function clientSecret(): ?string    { return $this->clientSecret; }
    public function failureReason(): ?string   { return $this->failureReason; }
    public function requiresAction(): bool     { return $this->clientSecret !== null; }
}
```

---

## Circuit Breaker Integration

```php
// Wrap a gateway with a circuit breaker to prevent cascade failures
final class ResilientPdfGateway implements PdfGatewayContract
{
    public function __construct(
        private readonly PdfGatewayContract $inner,
        private readonly CircuitBreaker     $breaker,
    ) {}

    public function generate(Invoice $invoice): string
    {
        return $this->breaker->call(
            fn() => $this->inner->generate($invoice)
        );
        // CircuitBreaker throws CircuitOpenException if circuit is OPEN
        // Service layer catches this and returns a degraded response
    }
}
```

---

## Gateway Naming Conventions

```
Infrastructure/Gateways/
├── StripePaymentGateway.php      → implements PaymentGatewayContract
├── SendGridMailGateway.php       → implements MailGatewayContract
├── TwilioSmsGateway.php          → implements SmsGatewayContract
├── CloudinaryStorageGateway.php  → implements StorageGatewayContract
├── WkhtmltopdfGateway.php        → implements PdfGatewayContract
└── GoogleMapsGateway.php         → implements GeocoderContract
```

Pattern: `{VendorName}{Domain}Gateway` — vendor first, domain second.

---

## Fake Gateway for Testing

```php
// In tests/Fixtures/ — implements the same contract as the real gateway
final class FakePdfGateway implements PdfGatewayContract
{
    private array $generated = [];
    private bool  $shouldFail = false;

    public function generate(Invoice $invoice): string
    {
        if ($this->shouldFail) {
            throw new GatewayException('PDF generation failed (fake)', layer: 'gateway.pdf.fake');
        }
        $path = 'fake-pdfs/' . $invoice->id()->value() . '.pdf';
        $this->generated[$invoice->id()->value()] = $path;
        return $path;
    }

    // Test helpers
    public function failOnNextCall(): void  { $this->shouldFail = true; }
    public function wasCalledFor(string $invoiceId): bool { return isset($this->generated[$invoiceId]); }
    public function generatedPaths(): array { return $this->generated; }
}
```

---

## Rules for Gateway Code

When writing or reviewing gateway code:

- **DO** catch every possible vendor exception type explicitly
- **DO** translate ALL vendor exceptions to `GatewayException` before they escape
- **DO** accept domain types as parameters (`Money`, `InvoiceId`) — not raw primitives
- **DO** return domain-friendly result types — never raw vendor response objects
- **DO** implement a contract interface from `API/Contracts/`
- **DO** include `layer:` context like `'gateway.stripe.charge'` in every `GatewayException`
- **DON'T** import the vendor SDK anywhere except in the Gateway class
- **DON'T** put business logic in gateways — no authorization, no domain rules
- **DON'T** call `DatabasePort` from a gateway
- **DON'T** let `\Exception`, `\RuntimeException`, or any vendor exception propagate out
- **DON'T** return `null` on failure — throw `GatewayException` or return a failure result type
