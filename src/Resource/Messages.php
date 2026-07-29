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
     * @param array{
     *   to:string,
     *   text:string,
     *   sender_id?:string,
     *   route?:string,
     *   schedule_mode?:string,
     *   scheduled_at?:string|DateTimeInterface
     * } $params
     * @return array<string,mixed>
     */
    public function send(array $params): array
    {
        $scheduledAt = $params['scheduled_at'] ?? null;
        if ($scheduledAt instanceof DateTimeInterface) {
            $scheduledAt = $scheduledAt->format(DateTimeInterface::ATOM);
        }

        return $this->http->request('POST', '/messages/send', null, [
            'to' => $params['to'],
            'text' => $params['text'],
            'sender_id' => $params['sender_id'] ?? null,
            'route' => $params['route'] ?? null,
            'schedule_mode' => $params['schedule_mode'] ?? null,
            'scheduled_at' => $scheduledAt,
        ]);
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
     * List messages, most recent first.
     *
     * @return array<string,mixed>
     */
    public function list(int $page = 0, int $limit = 20, ?string $status = null): array
    {
        return $this->http->request('GET', '/messages', [
            'page' => $page,
            'limit' => $limit,
            'status' => $status,
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
     * Retry a failed message.
     *
     * @return array<string,mixed>
     */
    public function retry(string $messageId): array
    {
        return $this->http->request('POST', '/messages/' . rawurlencode($messageId) . '/retry');
    }

    /**
     * Send one message to every contact in the given contact lists.
     *
     * @param int[] $contactListIds
     * @return array<string,mixed>
     */
    public function sendBulk(array $contactListIds, string $text, ?string $senderId = null, ?string $route = null): array
    {
        return $this->http->request('POST', '/messages/send-bulk', null, [
            'contact_list_ids' => $contactListIds,
            'text' => $text,
            'sender_id' => $senderId,
            'route' => $route,
        ]);
    }
}
