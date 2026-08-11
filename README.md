# esmsafrica/sms

Official PHP SDK for the [eSMS Africa](https://esmsafrica.io) SMS API.

Send SMS across 14+ African countries, track delivery, schedule messages, and check your balance. PHP 7.4+, PSR-4, no framework required.

## Install

```bash
composer require esmsafrica/sms
```

## Quick start

```php
require 'vendor/autoload.php';

$esms = new \Esms\Client('esms_live_...');

$res = $esms->messages->send([
    'to'        => '+256700000000',
    'text'      => 'Your verification code is 123456',
    'sender_id' => 'eSMSAfrica', // optional - falls back to the route default
]);

echo $res['id'], ' ', $res['status']; // "...", "submitted"
```

Get an API key from the eSMS dashboard under **Developers → API Keys**. Live keys look like `esms_live_…`; test keys look like `esms_test_…`.

Responses are returned as associative arrays that mirror the API JSON.

## Sending

```php
// Auto-detects the route (country) from the number.
$esms->messages->send(['to' => '+254711000000', 'text' => 'Hi from Kenya']);

// Or pin a route explicitly.
$esms->messages->send(['to' => '+256700000000', 'text' => 'Hi', 'route' => 'ESMS_UG']);

// Schedule for later (5 minutes to 7 days out).
$esms->messages->schedule([
    'to'           => '+256700000000',
    'text'         => 'Reminder',
    'scheduled_at' => (new DateTime('+1 hour')),  // or an ISO-8601 string
]);
```

## Delivery status

```php
$msg = $esms->messages->get($res['id']);
echo $msg['status'];            // queued | submitted | delivered | failed | ...
foreach ($msg['timeline'] as $event) {
    echo $event['at'], ' ', $event['event'], PHP_EOL;
}

// List recent messages
$page = $esms->messages->list(0, 20, 'delivered');
echo $page['total'];

// Retry a failed one
$esms->messages->retry($res['id']);
```

## Balance & routes

```php
$bal = $esms->balance->get();
echo "{$bal['currency']} {$bal['balance']}";

foreach ($esms->routes->list() as $r) {
    echo $r['code'], ' ', $r['country_name'], PHP_EOL;
}
```

## Errors

Every failure is an `\Esms\Exception\EsmsException`. Catch specific subclasses to branch:

```php
use Esms\Exception\InsufficientBalanceException;
use Esms\Exception\AuthenticationException;
use Esms\Exception\EsmsException;

try {
    $esms->messages->send(['to' => '+256700000000', 'text' => 'Hi']);
} catch (InsufficientBalanceException $e) {
    echo "Top up needed: have {$e->getBalance()}, need {$e->getCost()} {$e->getCurrency()}";
} catch (AuthenticationException $e) {
    echo "Check your API key.";
} catch (EsmsException $e) {
    echo $e->getStatus(), ' ', $e->getApiCode(), ': ', $e->getMessage();
}
```

| Exception | When |
|-----------|------|
| `AuthenticationException` | 401 - key missing or invalid |
| `PermissionException` | 403 - not allowed |
| `NotFoundException` | 404 - no such message |
| `InvalidRequestException` | 400 / 422 - bad request |
| `InsufficientBalanceException` | 402 - not enough credit (`getBalance()`, `getCost()`, `getCurrency()`) |
| `RateLimitException` | 429 - slow down |
| `ApiException` | 5xx - server error |
| `ConnectionException` | network failure or timeout |

## Configuration

```php
new \Esms\Client('esms_live_...', [
    'base_url'    => 'https://sms.esmsafrica.io/api', // default
    'timeout'     => 30,   // seconds
    'max_retries' => 2,    // transient failures (network, 429, 5xx) with backoff
]);
```

## License

MIT © eSMS Africa
