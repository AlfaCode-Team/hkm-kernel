# Gateway Layer

Gateways are the exclusive bridge between your application and external services — payment processors, SMS providers, weather APIs, or any vendor SDK. A gateway is responsible for calling a vendor and translating between your domain objects and the vendor's API. Gateways **only talk to vendor SDKs** — they never access the database, call repositories, or bypass the HTTP client port.

## Gateway Rules

- **Implement ONE per vendor concern** — `StripePaymentGateway`, `TwilioSmsGateway`
- **Depend on `HttpClientPort` only** — for HTTP calls; pass vendor SDKs if necessary but via the port's abstractions
- **Inject `HttpClientPort::pending()` for outbound HTTP** — never use a concrete HTTP client
- **Translate EVERY vendor exception to `GatewayException`** — never let vendor errors escape
- **Handle all vendor error types explicitly** — catch every exception the vendor can throw
- **Return a public domain response type** — controllers and services depend on YOUR types, not vendor types

## Complete Example

```php
<?php declare(strict_types=1);
namespace Shop\Payment\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use Shop\Payment\API\Contracts\PaymentGatewayContract;
use Shop\Payment\Domain\ValueObjects\Money;

final class StripePaymentGateway implements PaymentGatewayContract
{
    private readonly string $baseUrl = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly HttpClientPort $http,
        private readonly string         $secretKey,
    ) {}

    /**
     * Create a payment intent with Stripe.
     */
    public function charge(ChargeDTO $dto): ChargeResult
    {
        try {
            $response = $this->http->pending()
                ->baseUrl($this->baseUrl)
                ->withBasicAuth($this->secretKey, '')  // Stripe expects secret as username, empty password
                ->asForm()
                ->post('/payment_intents', [
                    'amount'      => $dto->amount->amount(),  // in cents
                    'currency'    => strtolower($dto->amount->currency()),
                    'payment_method' => $dto->paymentMethodId,
                    'confirm'     => 'true',
                    'return_url'  => $dto->returnUrl,
                ]);

            $response->throw('gateway.stripe.charge');  // Throw on 4xx/5xx

            $data = $response->json();

            return match ($data['status'] ?? null) {
                'requires_action'   => ChargeResult::requiresAction(
                    $data['client_secret'],
                    $data['id'],
                ),
                'succeeded'         => ChargeResult::success($data['id']),
                'processing'        => ChargeResult::processing($data['id']),
                default             => ChargeResult::failed($data['status'] ?? 'unknown'),
            };

        } catch (GatewayException $e) {
            // Already translated; re-throw
            throw $e;
        } catch (\Throwable $e) {
            // Unexpected error; wrap it
            throw new GatewayException(
                'Stripe charge failed: ' . $e->getMessage(),
                layer:    'gateway.stripe.charge',
                context:  ['payment_method' => $dto->paymentMethodId],
                previous: $e,
            );
        }
    }

    /**
     * Retrieve a payment intent from Stripe.
     */
    public function retrieve(string $intentId): ?PaymentIntentDTO
    {
        try {
            $response = $this->http->pending()
                ->baseUrl($this->baseUrl)
                ->withBasicAuth($this->secretKey, '')
                ->get("/payment_intents/{$intentId}");

            $response->throw('gateway.stripe.retrieve');

            $data = $response->json();

            return new PaymentIntentDTO(
                id:     $data['id'],
                status: $data['status'],
                amount: Money::cents((int) $data['amount'], $data['currency']),
            );

        } catch (GatewayException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new GatewayException(
                'Failed to retrieve payment intent from Stripe.',
                layer:    'gateway.stripe.retrieve',
                context:  ['intent_id' => $intentId],
                previous: $e,
            );
        }
    }
}
```

## HttpClientPort and PendingRequestContract

The `HttpClientPort` provides a fluent, immutable request builder for making outbound HTTP calls. Every method returns a new instance, so you can build reusable templates.

### Creating a Pending Request

```php
$pending = $this->http->pending()
    ->baseUrl('https://api.example.com')
    ->withToken('sk_live_abc123', 'Bearer')
    ->asJson()
    ->timeout(10)
    ->retry(3);

// Now use it to make requests
$response = $pending->post('/invoices', ['amount' => 9999]);
```

### Available Methods

| Method | Purpose |
|--------|---------|
| `baseUrl(string)` | Set the base URL; endpoints are appended to it |
| `withHeaders(array)` | Add multiple headers at once |
| `withHeader(string, string)` | Add a single header |
| `withToken(string, string)` | Add `Authorization: <type> <token>` (e.g., Bearer, Basic) |
| `withBasicAuth(string, string)` | Add HTTP Basic authentication |
| `asJson()` | Send `Content-Type: application/json` |
| `asForm()` | Send `Content-Type: application/x-www-form-urlencoded` |
| `asMultipart()` | Switch to multipart/form-data (required before `attach()`) |
| `attach(string, string, ?string)` | Attach a file to a multipart request |
| `acceptJson()` | Add `Accept: application/json` header |
| `timeout(int)` | Set request timeout in seconds |
| `connectTimeout(int)` | Set connection timeout in seconds |
| `retry(int)` | Auto-retry failed requests this many times |
| `retryMethods(array)` | Override which HTTP methods are retried (default: idempotent) |
| `get(string, array)` | Send GET request |
| `post(string, array)` | Send POST request |
| `put(string, array)` | Send PUT request |
| `patch(string, array)` | Send PATCH request |
| `delete(string, array)` | Send DELETE request |
| `send(string, string, array)` | Send custom method |

### HttpClientResponse

Responses are immutable value objects:

```php
$response->status(): int              // HTTP status code
$response->body(): string             // Raw response body
$response->header(string): ?string    // Single header (case-insensitive)
$response->headers(): array           // All headers as [name => value]
$response->json()                     // Decode JSON body; null if invalid
$response->ok(): bool                 // Status 200-299?
$response->redirect(): bool           // Status 300-399?
$response->clientError(): bool        // Status 400-499?
$response->serverError(): bool        // Status 500+?
$response->failed(): bool             // Status 400+?
$response->throw(?string): self       // Throw GatewayException on failure
```

## Gateway Contracts

Gateways implement contracts that define what they promise to callers. A contract is a PHP interface that describes the gateway's public methods and return types.

```php
<?php declare(strict_types=1);
namespace Shop\Payment\API\Contracts;

use Shop\Payment\Domain\ValueObjects\Money;

interface PaymentGatewayContract
{
    public function charge(ChargeDTO $dto): ChargeResult;
    public function retrieve(string $intentId): ?PaymentIntentDTO;
}
```

## Result DTOs

Gateways return result DTOs (not vendor objects) so callers don't couple to vendor APIs.

```php
<?php declare(strict_types=1);
namespace Shop\Payment\Infrastructure\Gateways;

use Shop\Payment\Domain\ValueObjects\Money;

final class ChargeResult
{
    public function __construct(
        public readonly string $status,  // 'success', 'failed', 'requires_action', 'processing'
        public readonly string $vendorId,
        public readonly ?string $clientSecret = null,  // For 3D Secure flows
    ) {}

    public static function success(string $vendorId): self
    {
        return new self('success', $vendorId);
    }

    public static function failed(string $status): self
    {
        return new self('failed', '', clientSecret: $status);
    }

    public static function requiresAction(string $clientSecret, string $vendorId): self
    {
        return new self('requires_action', $vendorId, clientSecret: $clientSecret);
    }

    public static function processing(string $vendorId): self
    {
        return new self('processing', $vendorId);
    }

    public function isSuccessful(): bool { return $this->status === 'success'; }
    public function isPending(): bool { return $this->status === 'processing'; }
}
```

## Testing Gateways

Gateways depend on `HttpClientPort`, which is an interface. In tests, inject a fake client that returns canned responses. The kernel does not ship one; a minimal fake is a few lines:

```php
<?php declare(strict_types=1);
namespace Tests\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{HttpClientPort, HttpClientResponse, PendingRequestContract};

final class FakeHttpClient implements HttpClientPort
{
    /** @var array<string, HttpClientResponse> "METHOD url-suffix" => response */
    private array $responses = [];
    /** @var list<array{string, string, array}> */
    public array $sent = [];

    public function expect(string $method, string $urlSuffix, HttpClientResponse $response): void
    {
        $this->responses[strtoupper($method) . ' ' . $urlSuffix] = $response;
    }

    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        $this->sent[] = [strtoupper($method), $url, $options];
        foreach ($this->responses as $key => $response) {
            [$m, $suffix] = explode(' ', $key, 2);
            if ($m === strtoupper($method) && str_ends_with($url, $suffix)) {
                return $response;
            }
        }
        return new HttpClientResponse(404, '');
    }

    public function get(string $url, array $query = []): HttpClientResponse   { return $this->request('GET', $url, ['query' => $query]); }
    public function post(string $url, array $data = []): HttpClientResponse   { return $this->request('POST', $url, ['json' => $data]); }
    public function put(string $url, array $data = []): HttpClientResponse    { return $this->request('PUT', $url, ['json' => $data]); }
    public function patch(string $url, array $data = []): HttpClientResponse  { return $this->request('PATCH', $url, ['json' => $data]); }
    public function delete(string $url, array $data = []): HttpClientResponse { return $this->request('DELETE', $url, ['json' => $data]); }

    public function pending(): PendingRequestContract
    {
        throw new \LogicException('This fake does not support pending(); fake PendingRequestContract too if the gateway uses it.');
    }
}
```

Then:

```php
<?php declare(strict_types=1);
namespace Tests\Unit\Payment\Gateways;

use PHPUnit\Framework\TestCase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use Shop\Payment\Infrastructure\Gateways\StripePaymentGateway;
use Tests\Fakes\FakeHttpClient;

final class StripePaymentGatewayTest extends TestCase
{
    public function testChargingSucceeds(): void
    {
        $fakeHttp = new FakeHttpClient();
        $fakeHttp->expect('post', '/payment_intents', new HttpClientResponse(
            status: 200,
            body: json_encode(['id' => 'pi_123', 'status' => 'succeeded']),
        ));

        $gateway = new StripePaymentGateway($fakeHttp, 'sk_test_abc');
        $result = $gateway->charge(new ChargeDTO(
            amount: Money::of(99.99, 'USD'),
            paymentMethodId: 'pm_test',
            returnUrl: 'https://example.test/success',
        ));

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('pi_123', $result->vendorId);
    }
}
```

## Common Mistakes

### ✗ Returning Vendor Objects

```php
// WRONG — callers now depend on Stripe's API
public function charge(ChargeDTO $dto): \Stripe\PaymentIntent
{
    return $this->stripe->paymentIntents->create([...]);
}
```

### ✓ Return Domain Result DTOs

```php
// RIGHT — callers depend only on your contract
public function charge(ChargeDTO $dto): ChargeResult
{
    $intent = $this->http->post(...);
    return ChargeResult::success($intent['id']);
}
```

### ✗ Direct Vendor SDK Without HttpClientPort

```php
// WRONG — tightly coupled to Stripe; untestable
final class StripePaymentGateway
{
    public function __construct(private \Stripe\StripeClient $stripe) {}
    
    public function charge(ChargeDTO $dto)
    {
        // Cannot inject a fake Stripe client for tests
    }
}
```

### ✓ Use HttpClientPort

```php
// RIGHT — loosely coupled; testable with a fake port
final class StripePaymentGateway
{
    public function __construct(private HttpClientPort $http) {}
    
    public function charge(ChargeDTO $dto)
    {
        $response = $this->http->pending()->post(...);
        // Can inject FakeHttpClient in tests
    }
}
```

### ✗ Vendor Exceptions Escape

```php
// WRONG — caller catches vendor-specific exceptions
try {
    $gateway->charge($dto);
} catch (\Stripe\Exception\CardException $e) {
    // Bound to Stripe implementation
}
```

### ✓ Translate to GatewayException

```php
// RIGHT — uniform exception type
try {
    $gateway->charge($dto);
} catch (GatewayException $e) {
    // Works regardless of vendor
}
```

## Source

- [src/Kernel/Ports/HttpClientPort.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/HttpClientPort.php)
- [src/Kernel/Ports/PendingRequestContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/PendingRequestContract.php)
- [src/Kernel/Ports/HttpClientResponse.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/HttpClientResponse.php)
- [src/Kernel/Exceptions/GatewayException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Exceptions/GatewayException.php)
- [docs/guides/06_GATEWAY.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/06_GATEWAY.md) (internal reference)
