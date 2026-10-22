<?php

declare(strict_types=1);

namespace Esms\Resource;

use Esms\HttpClient;

/**
 * Recipient imports: CSV / Excel upload, paste, Google Sheets.
 * Methods return the API JSON as arrays (snake_case keys).
 */
class Imports
{
    /** @var HttpClient */
    private $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /** Upload a CSV / XLSX / XLS file: a path, or the file contents plus a filename. @return array<string,mixed> */
    public function upload(string $pathOrContents, ?string $filename = null, ?string $defaultCountry = null): array
    {
        if ($filename === null && is_file($pathOrContents)) {
            $filename = basename($pathOrContents);
            $data = (string) file_get_contents($pathOrContents);
        } else {
            $data = $pathOrContents;
        }
        $filename = str_replace('"', '', $filename ?: 'upload.csv');
        $boundary = 'esms' . bin2hex(random_bytes(12));
        $body = '';
        if ($defaultCountry) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"default_country\"\r\n\r\n{$defaultCountry}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $data . "\r\n--{$boundary}--\r\n";
        return $this->http->requestRaw('POST', '/imports', $body, 'multipart/form-data; boundary=' . $boundary);
    }

    /** @return array<string,mixed> */
    public function paste(string $text, ?string $defaultCountry = null): array
    {
        return $this->http->request('POST', '/imports/paste', null, ['text' => $text, 'default_country' => $defaultCountry]);
    }

    /** @return array<string,mixed> */
    public function gsheet(string $url, ?string $defaultCountry = null): array
    {
        return $this->http->request('POST', '/imports/gsheet', null, ['url' => $url, 'default_country' => $defaultCountry]);
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        return $this->http->request('GET', '/imports/' . rawurlencode((string) $id));
    }

    /** mapping = [phone => col, name => col, fields => [col => key]]; options: default_country, dedupe, skip_opted_out. @return array<string,mixed> */
    public function process(string $id, array $mapping, array $options = []): array
    {
        return $this->http->request('POST', '/imports/' . rawurlencode((string) $id) . '/process', null, ['mapping' => $mapping] + $options);
    }

    /** @return array<string,mixed> */
    public function errors(string $id, array $query = []): array
    {
        return $this->http->request('GET', '/imports/' . rawurlencode((string) $id) . '/errors', $query);
    }

    /** @return array<string,mixed> */
    public function recipients(string $id, array $query = []): array
    {
        return $this->http->request('GET', '/imports/' . rawurlencode((string) $id) . '/recipients', $query);
    }

    /** params: group_id or name, tags. @return array<string,mixed> */
    public function saveToGroup(string $id, array $params): array
    {
        return $this->http->request('POST', '/imports/' . rawurlencode((string) $id) . '/save-to-group', null, $params);
    }
}
