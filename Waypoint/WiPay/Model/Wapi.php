<?php
/**
 * WiPay WAPI client: refund requests and transaction retrieval.
 *
 * WAPI is the authenticated surface, separate from the Payments API request
 * endpoint which carries no authentication header. Calls authenticate with an
 * X-WAPI-Key header whose value is environment-specific (sandbox keys are
 * prefixed wapi_test_ and cannot authenticate against live).
 *
 * WiPay documents the refund call as creating a *refund request* and exposes
 * no amount parameter, so only full-order refunds are attempted here. A
 * successful response means a request was created, not that funds returned.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model;

use Magento\Framework\Exception\LocalizedException;

class Wapi
{
    private Platform $platform;
    private Secrets $secrets;
    private Http $http;

    public function __construct(Platform $platform, Secrets $secrets, Http $http)
    {
        $this->platform = $platform;
        $this->secrets = $secrets;
        $this->http = $http;
    }

    /**
     * Whether WAPI is usable for an environment.
     */
    public function isConfigured(string $environment): bool
    {
        return $this->secrets->isWapiConfigured($environment);
    }

    /**
     * Submit a full refund request for a transaction.
     *
     * @return array<string, mixed> WiPay's response body.
     * @throws LocalizedException When the transaction is unusable or WiPay rejects the call.
     */
    public function refund(string $transactionId, string $country, string $environment): array
    {
        return $this->send(
            'POST',
            '/wapi/transactions/' . rawurlencode($this->assertTransactionId($transactionId)) . '/refund',
            $country,
            $environment
        );
    }

    /**
     * Retrieve a transaction's current state for reconciliation.
     *
     * @return array<string, mixed> The transaction's data payload.
     * @throws LocalizedException When the transaction is unusable or WiPay rejects the call.
     */
    public function transaction(string $transactionId, string $country, string $environment): array
    {
        $response = $this->send(
            'GET',
            '/wapi/transactions/' . rawurlencode($this->assertTransactionId($transactionId)),
            $country,
            $environment
        );

        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return $response;
    }

    /**
     * Transaction ids are interpolated into a URL path, so only the
     * characters WiPay itself issues are accepted.
     *
     * @throws LocalizedException
     */
    private function assertTransactionId(string $transactionId): string
    {
        $transactionId = trim($transactionId);

        if ($transactionId === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $transactionId)) {
            throw new LocalizedException(
                __('This order has no usable WiPay transaction id.')
            );
        }

        return $transactionId;
    }

    /**
     * Perform a WAPI request.
     *
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function send(string $method, string $path, string $country, string $environment): array
    {
        $base = $this->platform->wapiBase($country, $environment);

        if ($base === '') {
            throw new LocalizedException(__('WiPay is not configured for this platform.'));
        }

        $key = $this->secrets->wapiKey($environment);

        if ($key === '') {
            throw new LocalizedException(
                __(
                    'Set the WiPay WAPI key for this environment under Stores > Configuration > Sales > Payment Methods.'
                )
            );
        }

        $headers = [
            'Accept: application/json',
            'X-WAPI-Key: ' . $key,
        ];
        $payload = null;

        if (strtoupper($method) === 'POST') {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
            $payload = '{}';
        }

        [$status, $body] = $this->http->send(strtoupper($method), $base . $path, $headers, $payload);

        $decoded = json_decode((string) $body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['message']) && is_scalar($decoded['message'])
                ? (string) $decoded['message']
                : '';

            throw new LocalizedException(
                $message !== ''
                    ? __('WiPay rejected the request (%1): %2', $status, $message)
                    : __('WiPay rejected the request (HTTP %1).', $status)
            );
        }

        return $decoded;
    }
}
