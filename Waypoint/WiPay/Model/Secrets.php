<?php
/**
 * Resolves WiPay credentials from configuration.
 *
 * Secrets live in encrypted configuration values, never in code or logs.
 * This is the only class that reads them, so there is one place to audit and
 * one place to change if the storage approach changes.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Secrets
{
    private const PATH = 'payment/waypoint_wipay/';

    private EncryptorInterface $encryptor;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(EncryptorInterface $encryptor, ScopeConfigInterface $scopeConfig)
    {
        $this->encryptor = $encryptor;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Live response-verification key, used in md5(transaction_id + total + key).
     *
     * Sandbox uses WiPay's published key instead of this value.
     */
    public function liveApiKey(): string
    {
        return $this->read('live_api_key');
    }

    /**
     * Live merchant account number, digits only.
     *
     * Sandbox uses WiPay's published test account instead of this value.
     */
    public function liveAccountNumber(): string
    {
        return preg_replace('/\D+/', '', (string) $this->scopeConfig->getValue(self::PATH . 'live_account_number'));
    }

    /**
     * Environment-specific WAPI key, or an empty string when unset.
     */
    public function wapiKey(string $environment): string
    {
        $field = $environment === Platform::ENVIRONMENT_LIVE ? 'live_wapi_key' : 'sandbox_wapi_key';

        return $this->read($field);
    }

    public function isWapiConfigured(string $environment): bool
    {
        return $this->wapiKey($environment) !== '';
    }

    private function read(string $field): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::PATH . $field));

        if ($value === '') {
            return '';
        }

        try {
            $decrypted = $this->encryptor->decrypt($value);
        } catch (\Exception $e) {
            /*
             * Value could not be decrypted. Fall back to it verbatim so a
             * plaintext key saved before encryption was enabled keeps working
             * rather than silently failing verification.
             */
            return $value;
        }

        return trim((string) $decrypted);
    }
}
