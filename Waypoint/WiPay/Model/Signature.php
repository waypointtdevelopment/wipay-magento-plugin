<?php
/**
 * WiPay response signature verification.
 *
 * Documented by WiPay as: hash = md5(transaction_id + original_total + api_key)
 *
 * The original total is the amount sent in the payment request, formatted to
 * exactly two decimals. The customer-facing total returned on the redirect may
 * include fees and must never be used here.
 *
 * Deliberately free of Magento dependencies so it can be unit tested directly.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model;

class Signature
{
    /** Sandbox response-verification key published by WiPay. */
    public const SANDBOX_KEY = '123';

    /**
     * Build the expected hash for a transaction.
     */
    public function compute(string $transactionId, $originalTotal, string $apiKey): string
    {
        return md5(
            $transactionId
            . $this->formatTotal($originalTotal)
            . $apiKey
        );
    }

    /**
     * Compare a supplied hash against the expected one in constant time.
     *
     * A malformed, empty or short-circuited input always fails closed.
     */
    public function verify(string $transactionId, $originalTotal, string $apiKey, string $provided): bool
    {
        $provided = strtolower(trim($provided));

        if ($provided === '' || !preg_match('/^[a-f0-9]{32}$/', $provided)) {
            return false;
        }

        if ($apiKey === '' || $transactionId === '') {
            return false;
        }

        return hash_equals(
            $this->compute($transactionId, $originalTotal, $apiKey),
            $provided
        );
    }

    /**
     * Normalise an amount to the two-decimal form WiPay signs against.
     *
     * Accepts int, float or string so callers never have to pre-format.
     */
    public function formatTotal($total): string
    {
        return number_format((float) $total, 2, '.', '');
    }
}
