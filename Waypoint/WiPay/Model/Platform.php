<?php
/**
 * WiPay processing platforms and host mapping.
 *
 * The key is WiPay's country_code, which selects the processing platform. It
 * is deliberately not the shopper's billing country.
 *
 * Deliberately free of Magento dependencies so it can be unit tested directly.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Waypoint\WiPay\Model;

class Platform
{
    /** Published sandbox test account number. */
    public const SANDBOX_ACCOUNT = '1234567890';

    public const ENVIRONMENT_LIVE = 'live';
    public const ENVIRONMENT_SANDBOX = 'sandbox';

    /**
     * Platform code => human label, in display order.
     */
    private const LABELS = [
        'BB' => 'Barbados',
        'GD' => 'Grenada',
        'GY' => 'Guyana',
        'JM' => 'Jamaica',
        'TT' => 'Trinidad and Tobago',
    ];

    /**
     * Platform => environment => hostname.
     *
     * The TT pair is published on WiPay's Platforms and Environments page. The
     * others follow the documented host layout and were carried over from the
     * sandbox-tested WordPress gateway; re-confirm them before release.
     */
    private const HOSTS = [
        'BB' => [
            self::ENVIRONMENT_LIVE => 'bb.wipayfinancial.com',
            self::ENVIRONMENT_SANDBOX => 'bbsb.wipayfinancial.com',
        ],
        'GD' => [
            self::ENVIRONMENT_LIVE => 'gd.wipayfinancial.com',
            self::ENVIRONMENT_SANDBOX => 'gdsb.wipayfinancial.com',
        ],
        'GY' => [
            self::ENVIRONMENT_LIVE => 'gy.wipayfinancial.com',
            self::ENVIRONMENT_SANDBOX => 'gysb.wipayfinancial.com',
        ],
        'JM' => [
            self::ENVIRONMENT_LIVE => 'jm.wipayfinancial.com',
            self::ENVIRONMENT_SANDBOX => 'jmsb.wipayfinancial.com',
        ],
        'TT' => [
            self::ENVIRONMENT_LIVE => 'tt.wipayfinancial.com',
            self::ENVIRONMENT_SANDBOX => 'ttsb.wipayfinancial.com',
        ],
    ];

    /**
     * Supported platform codes.
     *
     * @return string[]
     */
    public function codes(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Platform code => label.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return self::LABELS;
    }

    public function isSupported(string $country): bool
    {
        $country = strtoupper(trim($country));

        return isset(self::HOSTS[$country]);
    }

    /**
     * Hostname for a platform and environment.
     *
     * Never guesses: an unrecognised combination reports "not configured"
     * rather than silently routing a payment to another territory.
     */
    public function host(string $country, string $environment): string
    {
        $country = strtoupper(trim($country));
        $environment = trim($environment);

        return isset(self::HOSTS[$country][$environment])
            ? self::HOSTS[$country][$environment]
            : '';
    }

    /**
     * Full Payments API request endpoint, or an empty string when unknown.
     */
    public function paymentsEndpoint(string $country, string $environment): string
    {
        $host = $this->host($country, $environment);

        return $host === '' ? '' : 'https://' . $host . '/plugins/payments/request';
    }

    /**
     * Base URL for WAPI calls. WAPI reuses the country host; callers append
     * the /wapi/... path themselves.
     */
    public function wapiBase(string $country, string $environment): string
    {
        $host = $this->host($country, $environment);

        return $host === '' ? '' : 'https://' . $host;
    }

    /**
     * Whether a checkout URL may be trusted as a WiPay redirect target.
     *
     * Requires https on a wipayfinancial.com host, including its subdomains,
     * and rejects lookalike hosts such as wipayfinancial.com.evil.example.
     */
    public function isValidCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        return (bool) preg_match('/(^|\.)wipayfinancial\.com$/i', $parts['host']);
    }
}
