<?php

declare(strict_types=1);

namespace Esms\Resource;

use DateTimeInterface;
use Esms\HttpClient;

/**
 * Operations on SMS messages.
 *
 * Responses are returned as associative arrays mirroring the API JSON.
 */
class Messages
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Send a single SMS.
     *
     * A random Idempotency-Key is attached to every call so a retried request
     * can never send or charge twice; pass `idempotency_key` to set your own.
     *
     * @param array{
     *   to:string,
     *   text:string,
     *   sender_id?:string,
     *   route?:string,
     *   schedule_mode?:string,
     *   scheduled_at?:string|DateTimeInterface,
     *   international?:bool,
     *   idempotency_key?:string
     * } $params
     * @return array<string,mixed>
     */
    public function send(array $params): array
    {
        return $this->http->request('POST', '/messages/send', null, [
            'to' => $params['to'],
            'text' => $params['text'],
            'sender_id' => $params['sender_id'] ?? null,
            'route' => $params['route'] ?? null,
            'schedule_mode' => $params['schedule_mode'] ?? null,
            'scheduled_at' => self::iso($params['scheduled_at'] ?? null),
            'international' => $params['international'] ?? null,
        ], ['Idempotency-Key' => $params['idempotency_key'] ?? HttpClient::newIdempotencyKey()]);
    }

    /**
     * Schedule an SMS for later delivery (5 minutes to 7 days out).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function schedule(array $params): array
    {
        $params['schedule_mode'] = 'scheduled';
        return $this->send($params);
    }

    /**
     * List messages, most recent first. Listed `text` is truncated to 100
     * characters; use get() for the full body.
     *
     * @param array{to?:string,batch_id?:string,date_from?:string|DateTimeInterface,date_to?:string|DateTimeInterface,environment?:string} $filters
     *   environment is "live" (default), "test" or "all".
     * @return array<string,mixed>
     */
    public function list(int $page = 0, int $limit = 20, ?string $status = null, array $filters = []): array
    {
        return $this->http->request('GET', '/messages', [
            'page' => $page,
            'limit' => $limit,
            'status' => $status,
            'to' => $filters['to'] ?? null,
            'batch_id' => $filters['batch_id'] ?? null,
            'date_from' => self::iso($filters['date_from'] ?? null),
            'date_to' => self::iso($filters['date_to'] ?? null),
            'environment' => $filters['environment'] ?? null,
        ]);
    }

    /**
     * Fetch a single message with its full delivery timeline.
     *
     * @return array<string,mixed>
     */
    public function get(string $messageId): array
    {
        return $this->http->request('GET', '/messages/' . rawurlencode($messageId));
    }

    /**
     * Retry a failed, undelivered, rejected, expired or unknown message.
     *
     * @return array{id:string,status:string,retry_count:int}
     */
    public function retry(string $messageId): array
    {
        return $this->http->request('POST', '/messages/' . rawurlencode($messageId) . '/retry');
    }

    /**
     * Send one message to many recipients: contact lists and/or inline recipients.
     *
     * @param int[] $contactListIds Contact list IDs (may be empty when $options['recipients'] is set).
     * @param array{
     *   recipients?:array<int,array{to:string,name?:string,vars?:array<string,mixed>}>,
     *   schedule_mode?:string,
     *   scheduled_at?:string|DateTimeInterface,
     *   drip_rate?:int,
     *   international?:bool
     * } $options schedule_mode is "now" (default), "scheduled" (contact lists only) or "drip".
     * @return array{batch_id:string,total_recipients:int,estimated_cost:float,status:string}
     */
    public function sendBulk(array $contactListIds, string $text, ?string $senderId = null, ?string $route = null, array $options = []): array
    {
        return $this->http->request('POST', '/messages/send-bulk', null, [
            'contact_list_ids' => $contactListIds ?: null,
            'recipients' => $options['recipients'] ?? null,
            'text' => $text,
            'sender_id' => $senderId,
            'route' => $route,
            'schedule_mode' => $options['schedule_mode'] ?? null,
            'scheduled_at' => self::iso($options['scheduled_at'] ?? null),
            'drip_rate' => $options['drip_rate'] ?? null,
            'international' => $options['international'] ?? null,
        ]);
    }

    /**
     * Aggregate status of a bulk batch plus a page of its messages.
     *
     * @return array<string,mixed>
     */
    public function getBatch(string $batchId, int $page = 0, int $limit = 50): array
    {
        return $this->http->request('GET', '/messages/batch/' . rawurlencode($batchId), [
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * Delivery status for up to 100 messages in one call (`['messages' => [...]]`).
     *
     * @param string[] $messageIds
     * @return array<string,mixed>
     */
    public function statuses(array $messageIds): array
    {
        return $this->http->request('GET', '/messages/status', ['ids' => implode(',', $messageIds)]);
    }

    /**
     * Price a message before sending - no charge, no delivery.
     *
     * @param string|string[] $to
     * @return array<string,mixed>
     */
    public function rate($to, string $text, ?string $route = null, ?bool $international = null): array
    {
        return $this->http->request('POST', '/messages/rate', null, [
            'to' => $to,
            'text' => $text,
            'route' => $route,
            'international' => $international,
        ]);
    }

    /**
     * Validate numbers offline (format, line type, carrier) - no charge.
     * A single number returns one result; a list returns
     * `['count', 'valid', 'invalid', 'mobile', 'results']`.
     *
     * @param string|string[] $phone
     * @return array<string,mixed>
     */
    public function validate($phone): array
    {
        return $this->http->request('POST', '/messages/validate', null, ['phone' => $phone]);
    }

    /**
     * @param mixed $value
     */
    private static function iso($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        return $value === null ? null : (string) $value;
    }
}
