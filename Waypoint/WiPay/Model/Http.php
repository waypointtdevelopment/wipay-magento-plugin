<?php
/**
 * Minimal HTTP transport for WiPay calls.
 *
 * A dedicated client per request rather than a shared framework client, so no
 * header or cookie state can leak between a payment request and a WAPI call.
 * Uses cURL when available and falls back to stream wrappers.
 *
 * SSL peer verification is always on and redirects are never followed.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model;

class Http
{
    private const TIMEOUT = 25;
    private const CONNECT_TIMEOUT = 10;

    /**
     * Perform a request.
     *
     * @param string[] $headers
     * @param string|null $payload Raw request body.
     * @return array{0: int, 1: string} Status code (0 on transport failure) and raw body.
     */
    public function send(string $method, string $url, array $headers, ?string $payload = null): array
    {
        $method = strtoupper($method);

        if (function_exists('curl_init')) {
            return $this->sendWithCurl($method, $url, $headers, $payload);
        }

        return $this->sendWithStream($method, $url, $headers, $payload);
    }

    /**
     * POST a JSON body with the Accept and Content-Type headers WiPay expects.
     *
     * @param array<string, mixed> $body
     * @param string[] $headers
     * @return array{0: int, 1: string}
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        $encoded = json_encode($body);

        if ($encoded === false) {
            return [0, ''];
        }

        return $this->send(
            'POST',
            $url,
            array_merge(
                ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'],
                $headers
            ),
            $encoded
        );
    }

    /**
     * @param string[] $headers
     * @return array{0: int, 1: string}
     */
    private function sendWithCurl(string $method, string $url, array $headers, ?string $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $payload);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [0, ''];
        }

        return [$status, (string) $body];
    }

    /**
     * @param string[] $headers
     * @return array{0: int, 1: string}
     */
    private function sendWithStream(string $method, string $url, array $headers, ?string $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => (string) $payload,
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return [0, ''];
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [$status, (string) $body];
    }
}
