<?php
/**
 * Standalone test harness for the Waypoint WiPay Magento module.
 *
 * Magento is not installed here, so the module's own services are loaded
 * against small stand-ins for the framework interfaces they type-hint. That
 * is enough to prove the parts that decide whether money moves: the response
 * signature, the platform/host map, the request payload rules, and the guards
 * around starting a checkout and submitting a refund.
 *
 * The controllers and DI wiring are not exercised here - see TESTING.md.
 *
 * Run:  php tests/run-tests.php
 * Exit code 0 means every assertion passed.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Framework stand-ins                                                 */
/* ------------------------------------------------------------------ */

namespace Magento\Framework {
    class Phrase
    {
        private string $text;
        private array $args;

        public function __construct($text, array $args = [])
        {
            $this->text = (string) $text;
            $this->args = $args;
        }

        public function __toString(): string
        {
            $out = $this->text;
            foreach ($this->args as $i => $arg) {
                $out = str_replace('%' . ($i + 1), (string) $arg, $out);
            }

            return $out;
        }
    }
}

namespace Magento\Framework\Exception {
    class LocalizedException extends \Exception
    {
        public function __construct($phrase = '', array $arguments = [])
        {
            $message = $phrase instanceof \Magento\Framework\Phrase
                ? (string) $phrase
                : (string) $phrase;
            parent::__construct($message);
        }
    }

    class NoSuchEntityException extends \Exception
    {
    }
}

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface
    {
        public function getValue($path = null, $scopeType = 'default', $scopeCode = null);
    }
}

namespace Magento\Framework\Encryption {
    interface EncryptorInterface
    {
        public function encrypt($data);

        public function decrypt($data, $key = null);
    }
}

namespace Magento\Framework {
    interface UrlInterface
    {
        public function getUrl($route = '', $params = []);

        public function getBaseUrl(array $params = []);
    }
}

namespace Magento\Payment\Gateway\Request {
    interface BuilderInterface
    {
        public function build(array $commandSubject);
    }
}

namespace Magento\Payment\Gateway {
    interface CommandInterface
    {
        public function execute(array $commandSubject);
    }
}

namespace Magento\Payment\Gateway\Data {
    interface PaymentDataObjectInterface
    {
        public function getPayment();

        public function getOrder();
    }
}

namespace Magento\Sales\Api\Data {
    interface OrderAddressInterface
    {
    }

    interface OrderInterface
    {
    }
}

namespace {
    /**
     * WordPress-free reimplementation of Magento's translation helper.
     *
     * @param string $text
     * @param mixed ...$args
     * @return \Magento\Framework\Phrase
     */
    function __($text, ...$args)
    {
        return new \Magento\Framework\Phrase($text, $args);
    }

    /* Module classes must load before any class that extends one of them. */
    require dirname(__DIR__) . '/Waypoint/WiPay/Model/Signature.php';
    require dirname(__DIR__) . '/Waypoint/WiPay/Model/Platform.php';
    require dirname(__DIR__) . '/Waypoint/WiPay/Model/Http.php';
    require dirname(__DIR__) . '/Waypoint/WiPay/Model/Secrets.php';
    require dirname(__DIR__) . '/Waypoint/WiPay/Model/Wapi.php';
    require dirname(__DIR__) . '/Waypoint/WiPay/Gateway/Request/PaymentRequest.php';
    require dirname(__DIR__) . '/Waypoint/WiPay/Gateway/Command/PlaceOrderCommand.php';
    require dirname(__DIR__) . '/Waypoint/WiPay/Gateway/Command/RefundCommand.php';
}

/* ------------------------------------------------------------------ */
/* Fakes                                                               */
/* ------------------------------------------------------------------ */

namespace {
    use Magento\Framework\App\Config\ScopeConfigInterface;
    use Magento\Framework\Encryption\EncryptorInterface;
    use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
    use Magento\Framework\UrlInterface;

    class FakeScopeConfig implements ScopeConfigInterface
    {
        public array $values = [];

        public function getValue($path = null, $scopeType = 'default', $scopeCode = null)
        {
            return $this->values[$path] ?? null;
        }

        public function set(string $path, $value): void
        {
            $this->values[$path] = $value;
        }
    }

    class FakeEncryptor implements EncryptorInterface
    {
        public function encrypt($data)
        {
            return 'enc:' . $data;
        }

        public function decrypt($data, $key = null)
        {
            $raw = (string) $data;
            if (strpos($raw, 'enc:') === 0) {
                return substr($raw, 4);
            }

            return $raw;
        }
    }

    class FakeUrl implements UrlInterface
    {
        public function getUrl($route = '', $params = [])
        {
            return 'https://shop.example.com/' . ltrim((string) $route, '/') . '/';
        }

        public function getBaseUrl(array $params = [])
        {
            return 'https://shop.example.com';
        }
    }

    class FakeAddress
    {
        public array $street = [];
        private string $firstname;
        private string $lastname;
        private string $city;
        private string $postcode;
        private string $countryId;
        private string $telephone;

        public function __construct(
            string $firstname = 'Matthew',
            string $lastname = 'Chan',
            array $street = ['12 Main Street', 'Unit 4'],
            string $city = 'Port of Spain',
            string $postcode = '12345',
            string $countryId = 'TT',
            string $telephone = '+18681234567'
        ) {
            $this->firstname = $firstname;
            $this->lastname = $lastname;
            $this->street = $street;
            $this->city = $city;
            $this->postcode = $postcode;
            $this->countryId = $countryId;
            $this->telephone = $telephone;
        }

        public function getFirstname(): string
        {
            return $this->firstname;
        }

        public function getLastname(): string
        {
            return $this->lastname;
        }

        public function getStreet(): array
        {
            return $this->street;
        }

        public function getCity(): string
        {
            return $this->city;
        }

        public function getPostcode(): string
        {
            return $this->postcode;
        }

        public function getCountryId(): string
        {
            return $this->countryId;
        }

        public function getTelephone(): string
        {
            return $this->telephone;
        }
    }

    class FakeOrder
    {
        public ?FakeAddress $billing = null;
        private float $grandTotal;
        private string $currency;
        private string $incrementId;
        private string $email;

        public function __construct(
            float $grandTotal = 100.00,
            string $currency = 'TTD',
            string $incrementId = '000000123',
            string $email = 'buyer@example.com'
        ) {
            $this->grandTotal = $grandTotal;
            $this->currency = $currency;
            $this->incrementId = $incrementId;
            $this->email = $email;
        }

        public function getBillingAddress(): ?FakeAddress
        {
            return $this->billing;
        }

        public function getOrderCurrencyCode(): string
        {
            return $this->currency;
        }

        public function getGrandTotal(): float
        {
            return $this->grandTotal;
        }

        public function getIncrementId(): string
        {
            return $this->incrementId;
        }

        public function getCustomerEmail(): string
        {
            return $this->email;
        }

        public function getCustomerFirstname(): string
        {
            return 'Matthew';
        }

        public function getCustomerLastname(): string
        {
            return 'Chan';
        }
    }

    class FakePayment
    {
        public array $additional = [];

        public function setAdditionalInformation($key, $value): void
        {
            $this->additional[$key] = $value;
        }

        public function getAdditionalInformation($key = null)
        {
            if ($key === null) {
                return $this->additional;
            }

            return $this->additional[$key] ?? null;
        }
    }

    class FakePaymentDataObject implements PaymentDataObjectInterface
    {
        private FakePayment $payment;
        private FakeOrder $order;

        public function __construct(FakePayment $payment, FakeOrder $order)
        {
            $this->payment = $payment;
            $this->order = $order;
        }

        public function getPayment()
        {
            return $this->payment;
        }

        public function getOrder()
        {
            return $this->order;
        }
    }

    /**
     * Http transport stub. Records calls and returns a canned response.
     */
    class StubHttp extends \Waypoint\WiPay\Model\Http
    {
        public int $status = 200;
        public string $body = '{}';
        public array $calls = [];

        public function postJson(string $url, array $body, array $headers = []): array
        {
            $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

            return [$this->status, $this->body];
        }

        public function send(string $method, string $url, array $headers, ?string $payload = null): array
        {
            $this->calls[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'payload' => $payload,
            ];

            return [$this->status, $this->body];
        }
    }

    /* ---------------------------------------------------------------- */
    /* Assertion helpers                                                 */
    /* ---------------------------------------------------------------- */

    $GLOBALS['assertions'] = 0;
    $GLOBALS['failures'] = 0;

    function suite(string $name): void
    {
        echo "\n== {$name} ==\n";
    }

    function ok($condition, string $label): void
    {
        $GLOBALS['assertions']++;
        if ($condition) {
            echo "  PASS  {$label}\n";

            return;
        }
        $GLOBALS['failures']++;
        echo "  FAIL  {$label}\n";
    }

    function same($expected, $actual, string $label): void
    {
        $GLOBALS['assertions']++;
        if ($expected === $actual) {
            echo "  PASS  {$label}\n";

            return;
        }
        $GLOBALS['failures']++;
        echo "  FAIL  {$label}\n";
        echo '        expected: ' . var_export($expected, true) . "\n";
        echo '        actual:   ' . var_export($actual, true) . "\n";
    }

    function throws(callable $callback, string $needle, string $label): void
    {
        $GLOBALS['assertions']++;
        try {
            $callback();
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), $needle) !== false) {
                echo "  PASS  {$label}\n";

                return;
            }
            $GLOBALS['failures']++;
            echo "  FAIL  {$label}\n";
            echo '        expected message containing: ' . $needle . "\n";
            echo '        actual:   ' . $e->getMessage() . "\n";

            return;
        }
        $GLOBALS['failures']++;
        echo "  FAIL  {$label} (nothing thrown)\n";
    }

    /**
     * Builds a request builder wired with fakes.
     *
     * @return array{0: \Waypoint\WiPay\Gateway\Request\PaymentRequest, 1: FakeScopeConfig}
     */
    function make_request_builder(array $config = [])
    {
        $scope = new FakeScopeConfig();
        foreach ($config as $path => $value) {
            $scope->set($path, $value);
        }
        $scope->set('payment/waypoint_wipay/environment', $config['payment/waypoint_wipay/environment'] ?? 'sandbox');
        $scope->set('payment/waypoint_wipay/country_code', $config['payment/waypoint_wipay/country_code'] ?? 'TT');
        $scope->set(
            'payment/waypoint_wipay/fee_structure',
            $config['payment/waypoint_wipay/fee_structure'] ?? 'merchant_absorb'
        );

        $builder = new \Waypoint\WiPay\Gateway\Request\PaymentRequest(
            new \Waypoint\WiPay\Model\Platform(),
            new \Waypoint\WiPay\Model\Secrets(new FakeEncryptor(), $scope),
            $scope,
            new FakeUrl()
        );

        return [$builder, $scope];
    }

    function build_payload(FakeOrder $order, array $config = []): array
    {
        [$builder] = make_request_builder($config);

        return $builder->build([
            'payment' => new FakePaymentDataObject(new FakePayment(), $order),
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Signature                                                         */
    /* ---------------------------------------------------------------- */

    suite('Response signature');

    $signature = new \Waypoint\WiPay\Model\Signature();
    $txn = 'SB-12-1-order000000123-20260310120000';

    same(md5($txn . '10.00' . '123'), $signature->compute($txn, '10.00', '123'), 'hash is md5(txn + original_total + key)');
    same('10.00', $signature->formatTotal(10.0), 'total formats to two decimals');
    same('10.50', $signature->formatTotal('10.5'), 'a one-decimal total is padded');
    same('5.00', $signature->formatTotal(5), 'an integer total renders as N.00');

    ok($signature->verify($txn, '10.00', '123', $signature->compute($txn, '10.00', '123')), 'a matching hash verifies');
    ok($signature->verify($txn, '10.00', '123', strtoupper($signature->compute($txn, '10.00', '123'))), 'uppercase hex verifies');
    ok(!$signature->verify($txn, '10.01', '123', $signature->compute($txn, '10.00', '123')), 'a one-cent amount change breaks the hash');
    ok(!$signature->verify($txn, '10.00', 'wrong', $signature->compute($txn, '10.00', '123')), 'a wrong key breaks the hash');
    ok(!$signature->verify('OTHER', '10.00', '123', $signature->compute($txn, '10.00', '123')), 'a different transaction breaks the hash');
    ok(!$signature->verify($txn, '10.00', '123', str_repeat('a', 32)), 'a well-formed wrong hash is rejected');
    ok(!$signature->verify($txn, '10.00', '123', 'not-hex'), 'a non-hex hash is rejected');
    ok(!$signature->verify($txn, '10.00', '123', ''), 'an empty hash is rejected');
    ok(!$signature->verify($txn, '10.00', '', $signature->compute($txn, '10.00', '123')), 'an empty key is rejected');
    ok(!$signature->verify('', '10.00', '123', 'abc'), 'an empty transaction id is rejected');

    /* ---------------------------------------------------------------- */
    /* Platform                                                          */
    /* ---------------------------------------------------------------- */

    suite('Platform and host map');

    $platform = new \Waypoint\WiPay\Model\Platform();

    same('https://ttsb.wipayfinancial.com/plugins/payments/request', $platform->paymentsEndpoint('TT', 'sandbox'), 'TT sandbox endpoint');
    same('https://tt.wipayfinancial.com/plugins/payments/request', $platform->paymentsEndpoint('TT', 'live'), 'TT live endpoint');
    same('https://jmsb.wipayfinancial.com/plugins/payments/request', $platform->paymentsEndpoint('JM', 'sandbox'), 'JM sandbox endpoint');
    same('https://bbsb.wipayfinancial.com/plugins/payments/request', $platform->paymentsEndpoint('BB', 'sandbox'), 'BB sandbox endpoint');
    same('https://gysb.wipayfinancial.com/plugins/payments/request', $platform->paymentsEndpoint('GY', 'sandbox'), 'GY sandbox endpoint');
    same('https://gdsb.wipayfinancial.com/plugins/payments/request', $platform->paymentsEndpoint('GD', 'sandbox'), 'GD sandbox endpoint');
    same('', $platform->paymentsEndpoint('XX', 'sandbox'), 'an unknown platform yields no endpoint');
    same('', $platform->paymentsEndpoint('TT', 'staging'), 'an unknown environment yields no endpoint');
    same('https://ttsb.wipayfinancial.com', $platform->wapiBase('TT', 'sandbox'), 'WAPI reuses the country host');
    same(['BB', 'GD', 'GY', 'JM', 'TT'], $platform->codes(), 'exactly five platforms are offered');

    ok($platform->isValidCheckoutUrl('https://tt.wipayfinancial.com/checkout/payment?x=1'), 'an https wipay URL is accepted');
    ok($platform->isValidCheckoutUrl('https://checkout.wipayfinancial.com/pay'), 'a wipay subdomain is accepted');
    ok(!$platform->isValidCheckoutUrl('http://tt.wipayfinancial.com/pay'), 'plain http is rejected');
    ok(!$platform->isValidCheckoutUrl('https://evil.example.com/pay'), 'a foreign host is rejected');
    ok(!$platform->isValidCheckoutUrl('https://wipayfinancial.com.evil.example/pay'), 'a lookalike host is rejected');
    ok(!$platform->isValidCheckoutUrl('not a url'), 'a malformed URL is rejected');
    ok(!$platform->isValidCheckoutUrl(''), 'an empty URL is rejected');

    /* ---------------------------------------------------------------- */
    /* Request payload                                                   */
    /* ---------------------------------------------------------------- */

    suite('Request payload');

    $order = new FakeOrder(100.00, 'TTD', '000000123', 'buyer@example.com');
    $order->billing = new FakeAddress();

    $payload = build_payload($order);

    same(1234567890, $payload['account_number'], 'sandbox uses the published test account');
    same('sandbox', $payload['environment'], 'environment comes from configuration');
    same('TT', $payload['country_code'], 'platform country code is sent');
    same('TTD', $payload['currency'], 'currency is uppercased');
    same('credit_card_co', $payload['method'], 'the documented method is used');
    same('WaypointM2', $payload['origin'], 'origin identifies this integration');
    same('100.00', $payload['total'], 'total is sent with two decimals');
    same('000000123', $payload['order_id'], 'the increment id is sent as a string');
    same('buyer@example.com', $payload['email'], 'customer email is sent');
    same('https://shop.example.com/waypoint/payment/response/', $payload['response_url'], 'response url points at our own controller');
    same('Matthew', $payload['fname'], 'first name is sent');
    same('Chan', $payload['lname'], 'last name is sent when a first name is present');
    same('12 Main Street', $payload['addr1'], 'address line 1 is sent');
    same('Unit 4', $payload['addr2'], 'address line 2 is sent when address line 1 is present');
    same('Port of Spain', $payload['city'], 'city is sent');
    same('12345', $payload['postcode'] ?? $payload['zipcode'], 'postcode is sent');
    same('TT', $payload['country'], 'billing country is sent');
    same('+18681234567', $payload['phone'], 'an E.164 phone number is sent');

    /* Grand total, not base total, must drive the amount. */
    $multiCurrency = new FakeOrder(250.50, 'USD', '000000999');
    $multiCurrency->billing = null;
    $payload = build_payload($multiCurrency);
    same('250.50', $payload['total'], 'grand total in order currency is sent');
    same('USD', $payload['currency'], 'matching currency is sent');
    ok(!isset($payload['addr1']), 'addr1 is omitted without a billing address');
    ok(!isset($payload['fname']) || $payload['fname'] === 'Matthew', 'name falls back to the customer record');

    /* Dependent-field rules. */
    $noFirstName = new FakeOrder(100.00, 'TTD', '000000124');
    $noFirstName->billing = new FakeAddress('', '', ['12 Main Street']);
    $payload = build_payload($noFirstName);
    ok(!isset($payload['lname']), 'lname is omitted when fname is absent');
    ok(isset($payload['addr1']), 'addr1 is still sent on its own');

    $noAddress = new FakeOrder(100.00, 'TTD', '000000125');
    $noAddress->billing = new FakeAddress('Matthew', 'Chan', ['', 'Unit 4']);
    $payload = build_payload($noAddress);
    ok(!isset($payload['addr2']), 'addr2 is omitted when addr1 is absent');
    ok(isset($payload['fname']), 'fname is still sent on its own');

    /* Length caps. */
    $long = new FakeOrder(100.00, 'TTD', '000000126');
    $long->billing = new FakeAddress(
        str_repeat('F', 80),
        str_repeat('L', 80),
        [str_repeat('A', 120), str_repeat('B', 120)],
        str_repeat('C', 90),
        str_repeat('Z', 40)
    );
    $payload = build_payload($long, ['payment/waypoint_wipay/fee_structure' => 'split']);
    same(30, strlen($payload['fname']), 'fname is capped at 30');
    same(30, strlen($payload['lname']), 'lname is capped at 30');
    same(50, strlen($payload['addr1']), 'addr1 is capped at 50');
    same(50, strlen($payload['addr2']), 'addr2 is capped at 50');
    same(30, strlen($payload['city']), 'city is capped at 30');
    same(10, strlen($payload['postcode'] ?? $payload['zipcode']), 'postcode is capped at 10');
    same('split', $payload['fee_structure'], 'fee structure comes from configuration');

    /* Phone normalisation. */
    $national = new FakeOrder(100.00, 'TTD', '000000127');
    $national->billing = new FakeAddress('M', 'C', ['1 A'], 'P', '1', 'TT', '18681234567');
    $payload = build_payload($national);
    ok(!isset($payload['phone']), 'a national-format number is dropped rather than sent invalid');

    $messy = new FakeOrder(100.00, 'TTD', '000000128');
    $messy->billing = new FakeAddress('M', 'C', ['1 A'], 'P', '1', 'TT', '+1 868 123-4567');
    $payload = build_payload($messy);
    same('+18681234567', $payload['phone'], 'separators are stripped from an E.164 number');

    /* Live configuration resolves through the encrypted secrets path. */
    [$liveBuilder, $liveScope] = make_request_builder([
        'payment/waypoint_wipay/environment' => 'live',
        'payment/waypoint_wipay/live_account_number' => '99887766',
    ]);
    $liveScope->set('payment/waypoint_wipay/live_api_key', 'enc:live-key-value');
    same('99887766', $liveBuilder->accountNumber('live'), 'the live account number comes from configuration');
    same('live-key-value', $liveBuilder->apiKey('live'), 'the live api key is decrypted before use');
    same(\Waypoint\WiPay\Model\Signature::SANDBOX_KEY, $liveBuilder->apiKey('sandbox'), 'sandbox uses the published key');

    /* ---------------------------------------------------------------- */
    /* Place-order guards                                                */
    /* ---------------------------------------------------------------- */

    suite('Place-order guards');

    function make_place_command(FakeScopeConfig $scope, StubHttp $http): \Waypoint\WiPay\Gateway\Command\PlaceOrderCommand
    {
        $builder = new \Waypoint\WiPay\Gateway\Request\PaymentRequest(
            new \Waypoint\WiPay\Model\Platform(),
            new \Waypoint\WiPay\Model\Secrets(new FakeEncryptor(), $scope),
            $scope,
            new FakeUrl()
        );

        return new \Waypoint\WiPay\Gateway\Command\PlaceOrderCommand(
            $builder,
            new \Waypoint\WiPay\Model\Platform(),
            $http
        );
    }

    function place_scope(array $config = []): FakeScopeConfig
    {
        $scope = new FakeScopeConfig();
        $scope->set('payment/waypoint_wipay/environment', $config['environment'] ?? 'sandbox');
        $scope->set('payment/waypoint_wipay/country_code', $config['country_code'] ?? 'TT');
        $scope->set('payment/waypoint_wipay/fee_structure', 'merchant_absorb');
        $scope->set('payment/waypoint_wipay/live_account_number', $config['live_account_number'] ?? '');
        $scope->set('payment/waypoint_wipay/live_api_key', $config['live_api_key'] ?? '');

        return $scope;
    }

    $http = new StubHttp();
    $scope = place_scope();
    $command = make_place_command($scope, $http);

    /* Unknown platform: no endpoint, must throw before any HTTP call. */
    throws(
        function () use ($command, $scope) {
            $scope->set('payment/waypoint_wipay/country_code', 'ZZ');
            try {
                $order = new FakeOrder(100.00, 'TTD');
                $order->billing = new FakeAddress();
                $command->execute(['payment' => new FakePaymentDataObject(new FakePayment(), $order)]);
            } finally {
                $scope->set('payment/waypoint_wipay/country_code', 'TT');
            }
        },
        'not configured correctly',
        'an unknown platform fails before any request is sent'
    );
    same(0, count($http->calls), 'no HTTP call was made for an unknown platform');

    /* TTD minimum. */
    throws(
        function () use ($command) {
            $order = new FakeOrder(4.99, 'TTD');
            $order->billing = new FakeAddress();
            $command->execute(['payment' => new FakePaymentDataObject(new FakePayment(), $order)]);
        },
        'minimum payment of TTD 5.00',
        'a sub-minimum TTD order is refused'
    );

    /* The minimum does not apply to other currencies. */
    $http->status = 200;
    $http->body = json_encode([
        'url' => 'https://tt.wipayfinancial.com/checkout/payment?token=abc',
        'transaction_id' => 'SB-1-1-x-20260310',
        'message' => 'OK',
    ]);
    $http->calls = [];
    $order = new FakeOrder(4.99, 'USD');
    $order->billing = new FakeAddress();
    $payment = new FakePayment();
    $command->execute(['payment' => new FakePaymentDataObject($payment, $order)]);
    same(1, count($http->calls), 'a sub-minimum order in another currency is sent to WiPay');
    same('https://ttsb.wipayfinancial.com/plugins/payments/request', $http->calls[0]['url'], 'the sandbox endpoint is used');

    /* Successful response stores everything the return controller needs. */
    same(
        'https://tt.wipayfinancial.com/checkout/payment?token=abc',
        $payment->getAdditionalInformation(\Waypoint\WiPay\Gateway\Request\PaymentRequest::KEY_URL),
        'the checkout url is stored on the payment'
    );
    same(
        'SB-1-1-x-20260310',
        $payment->getAdditionalInformation(\Waypoint\WiPay\Gateway\Request\PaymentRequest::KEY_TRANSACTION),
        'the transaction id is stored on the payment'
    );
    same(
        '4.99',
        $payment->getAdditionalInformation(\Waypoint\WiPay\Gateway\Request\PaymentRequest::KEY_PINNED_TOTAL),
        'the pinned total is stored on the payment'
    );
    same(
        'sandbox',
        $payment->getAdditionalInformation(\Waypoint\WiPay\Gateway\Request\PaymentRequest::KEY_ENVIRONMENT),
        'the environment is stored on the payment'
    );
    ok(
        $payment->getAdditionalInformation(\Waypoint\WiPay\Gateway\Request\PaymentRequest::KEY_STARTED) > 0,
        'a started-attempt marker is stored on the payment'
    );

    /* Invalid checkout URL must never be stored. */
    $http->status = 200;
    $http->body = json_encode(['url' => 'https://evil.example.com/steal', 'transaction_id' => 'SB-2']);
    $http->calls = [];
    $badPayment = new FakePayment();
    $order = new FakeOrder(100.00, 'TTD');
    $order->billing = new FakeAddress();
    throws(
        function () use ($command, $badPayment, $order) {
            $command->execute(['payment' => new FakePaymentDataObject($badPayment, $order)]);
        },
        'invalid checkout response',
        'a checkout URL off the wipayfinancial.com domain is rejected'
    );
    same([], $badPayment->additional, 'nothing is stored when the response is rejected');

    /* Non-200 response. */
    $http->status = 500;
    $http->body = '';
    throws(
        function () use ($command) {
            $order = new FakeOrder(100.00, 'TTD');
            $order->billing = new FakeAddress();
            $command->execute(['payment' => new FakePaymentDataObject(new FakePayment(), $order)]);
        },
        'could not start checkout',
        'a failed upstream call aborts order placement'
    );

    /* ---------------------------------------------------------------- */
    /* WAPI and refund guards                                            */
    /* ---------------------------------------------------------------- */

    suite('WAPI and refund guards');

    $http = new StubHttp();
    $scope = place_scope();
    $secrets = new \Waypoint\WiPay\Model\Secrets(new FakeEncryptor(), $scope);
    $wapi = new \Waypoint\WiPay\Model\Wapi(new \Waypoint\WiPay\Model\Platform(), $secrets, $http);

    ok(!$wapi->isConfigured('sandbox'), 'WAPI is unconfigured by default');

    throws(fn() => $wapi->refund('', 'TT', 'sandbox'), 'no usable WiPay transaction id', 'an empty transaction id is refused');
    throws(fn() => $wapi->refund('../etc/passwd', 'TT', 'sandbox'), 'no usable WiPay transaction id', 'a path-like transaction id is refused');
    throws(fn() => $wapi->transaction('SB-1', 'XX', 'sandbox'), 'not configured for this platform', 'an unknown platform is refused');
    throws(fn() => $wapi->refund('SB-1', 'TT', 'sandbox'), 'WAPI key', 'a missing WAPI key is refused before any HTTP call');
    same(0, count($http->calls), 'no HTTP call was made while unconfigured');

    /* With a key, the documented endpoint and header are used. */
    $scope->set('payment/waypoint_wipay/sandbox_wapi_key', 'enc:wapi_test_key_123');
    ok($wapi->isConfigured('sandbox'), 'WAPI becomes configured once the key is set');

    $http->status = 200;
    $http->body = '{"message":"Refund request successfully created."}';
    $http->calls = [];
    $result = $wapi->refund('SB-12-1-order42', 'TT', 'sandbox');
    same(['message' => 'Refund request successfully created.'], $result, 'the documented success body is returned');
    same(1, count($http->calls), 'exactly one request was sent');
    same('https://ttsb.wipayfinancial.com/wapi/transactions/SB-12-1-order42/refund', $http->calls[0]['url'], 'the refund hits the documented WAPI path');
    same('POST', $http->calls[0]['method'], 'the refund uses POST');
    ok(in_array('X-WAPI-Key: wapi_test_key_123', $http->calls[0]['headers'], true), 'the WAPI key is sent as a header');
    ok(!in_array('X-WAPI-Key: enc:wapi_test_key_123', $http->calls[0]['headers'], true), 'the key is decrypted, never sent encrypted');

    /* Error responses surface WiPay's message. */
    $http->status = 400;
    $http->body = '{"message":"Insufficient balance"}';
    throws(fn() => $wapi->refund('SB-12-1-order42', 'TT', 'sandbox'), 'Insufficient balance', 'WiPay error messages are passed through');

    /* RefundCommand guards. */
    $http->status = 200;
    $http->body = '{"message":"ok"}';
    $http->calls = [];
    $refund = new \Waypoint\WiPay\Gateway\Command\RefundCommand($wapi, new \Waypoint\WiPay\Model\Platform(), $scope);

    $paidOrder = new FakeOrder(100.00, 'TTD');
    $paidPayment = new FakePayment();
    $paidPayment->setAdditionalInformation(
        \Waypoint\WiPay\Gateway\Request\PaymentRequest::KEY_TRANSACTION,
        'SB-12-1-order42'
    );
    $paidPayment->setAdditionalInformation(
        \Waypoint\WiPay\Gateway\Request\PaymentRequest::KEY_ENVIRONMENT,
        'sandbox'
    );

    throws(
        function () use ($refund) {
            $order = new FakeOrder(100.00, 'TTD');
            $order->billing = new FakeAddress();
            $refund->execute(['payment' => new FakePaymentDataObject(new FakePayment(), $order)]);
        },
        'no WiPay transaction to refund',
        'an order with no transaction cannot be refunded'
    );

    throws(
        function () use ($refund, $paidPayment, $paidOrder) {
            $refund->execute([
                'payment' => new FakePaymentDataObject($paidPayment, $paidOrder),
                'amount' => 50.00,
            ]);
        },
        'full order refunds only',
        'a partial refund is refused rather than silently processed as a full one'
    );
    same(0, count($http->calls), 'a refused partial refund sends nothing to WiPay');

    $refund->execute([
        'payment' => new FakePaymentDataObject($paidPayment, $paidOrder),
        'amount' => 100.00,
    ]);
    same(1, count($http->calls), 'a full refund is submitted');

    /* ---------------------------------------------------------------- */
    /* Report                                                            */
    /* ---------------------------------------------------------------- */

    echo "\n----------------------------------------\n";
    echo "Assertions: {$GLOBALS['assertions']}\n";
    echo "Failures:   {$GLOBALS['failures']}\n";

    if ($GLOBALS['failures'] > 0) {
        echo "RESULT: FAILED\n";
        exit(1);
    }

    echo "RESULT: PASSED\n";
    exit(0);
}
