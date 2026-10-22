# esmsafrica/sms

Official PHP SDK for the [eSMS Africa](https://esmsafrica.io) SMS API.

Send SMS across Africa, track delivery, schedule messages, run managed OTP verification, and check your balance. PHP 7.4+, PSR-4, no framework required.

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

// Price a message before sending (no charge, no delivery).
$quote = $esms->messages->rate('+256700000000', 'Hi');

// Send to many numbers at once (inline recipients and/or contact lists).
$batch = $esms->messages->sendBulk([], 'Hello from eSMS', null, null, [
    'recipients' => [['to' => '+256700000000'], ['to' => '+254711000000']],
]);
$summary = $esms->messages->getBatch($batch['batch_id']);
```

`messages->send()` attaches a random `Idempotency-Key` to every call so a retried request can never send or charge twice. Pass your own `'idempotency_key'` to make retries safe across process restarts too.

Advanced options: `'flash' => true` (class-0 SMS shown on screen), `'validity_period'` (minutes, 1-4320), `'priority'` (0-3) and `'binary' => ['data' => $hex, 'udh' => $hex]` (pass `'text' => ''`). `sendBulk()` options also take `flash`, `validity_period`, `priority` and `'skip_unavailable' => true` (drop unreachable recipients, uncharged, instead of rejecting the whole batch).

## Personalised bulk + campaigns

Requires SDK 1.2.0 or later.

```php
// One call: a template with per-recipient merge fields. Invalid, duplicate and
// opted-out numbers come back in "rejected" and are never charged.
$batch = $esms->messages->sendBatch([
    'template' => 'Hi {first_name|there}, your balance is UGX {balance}. Pay by {due_date}.',
    'recipients' => [
        ['to' => '+256772123456', 'name' => 'Ada Obi', 'vars' => ['balance' => '1,500', 'due_date' => '1 Nov']],
        ['to' => '0701234567', 'vars' => ['balance' => '200', 'due_date' => '1 Nov']],
    ],
    'default_country' => 'UG',
    'sender_id' => 'ACMEBANK',
    'schedule' => ['mode' => 'scheduled', 'at' => '2026-11-01T09:00', 'timezone' => 'Africa/Kampala'],
]);
$status = $esms->messages->getBatch($batch['batch_id']);

// Big lists: upload a CSV / Excel file, map columns, then run a campaign.
$imp = $esms->imports->upload('debtors.xlsx', null, 'UG');
$job = $esms->imports->process($imp['id'], ['phone' => 'Mobile', 'name' => 'Name', 'fields' => ['Amount Due' => 'amount']]);
// poll $esms->jobs->get($job['job_id']) until status is "done"
$c = $esms->campaigns->create(['name' => 'October dues', 'text' => 'Hi {first_name}, you owe {amount|0}.',
    'audience' => [['type' => 'import', 'id' => $imp['id']]], 'idempotency_key' => 'october-dues']);
$esms->campaigns->prepare($c['id']);
if ($esms->campaigns->review($c['id'])['review']['can_launch']) {
    $esms->campaigns->launch($c['id']);
}
$report = $esms->campaigns->report($c['id']);
```

Also: `campaigns->pause/resume/cancel/retryFailed/duplicate/recipients/runs`, `contacts`, `groups`, `templates` (with `starterPack()` and `useStarter($key)`).
The signed `campaign.completed` webhook uses the same `Client::verifyWebhook()` check.

## Delivery status

```php
$msg = $esms->messages->get($res['id']);
echo $msg['status'];            // queued | submitted | delivered | failed | ...
foreach ($msg['timeline'] as $event) {
    echo $event['at'], ' ', $event['event'], PHP_EOL;
}

// List recent messages (4th arg filters: to, batch_id, date_from, date_to, environment)
$page = $esms->messages->list(0, 20, 'delivered');
echo $page['total'];

// Status of up to 100 messages in one call
$statuses = $esms->messages->statuses([$res['id']])['messages'];

// Retry a failed one -> ['id' => ..., 'status' => ..., 'retry_count' => ...]
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

## Verify (managed OTP)

```php
$v = $esms->verify->start('+256700000000'); // or start($to, ['app_id' => '...'])
// ...user types the code...
$check = $esms->verify->check('123456', ['verification_id' => $v['verification_id']]);
if ($check['status'] === 'approved') {
    // verified
}
```

Also available: `verify->get`, `resend`, `cancel`, `list`, and Verify Apps (`listApps`, `createApp`, `getApp`, `updateApp`, `deleteApp`, `appStats`).

## Opt-outs

```php
$esms->optOuts->add('+256700000000');
$optedOut = $esms->optOuts->list();
$esms->optOuts->remove('+256700000000');
```

## Webhooks

Delivery-report webhooks are signed with HMAC-SHA256 in the `X-Webhook-Signature` header (`sha256=<hex>`). Verify against the exact raw body:

```php
$ok = \Esms\Client::verifyWebhook(file_get_contents('php://input'), $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null, $secret);
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
| `InvalidRequestException` | 400 / 409 / 413 / 422 - bad request |
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

Requests authenticate with `Authorization: Bearer <key>`. Non-idempotent POSTs (bulk sends, retries, OTP resends) are only retried on 429, never after a 5xx or network error, so they cannot run twice.

## License

MIT © eSMS Africa
