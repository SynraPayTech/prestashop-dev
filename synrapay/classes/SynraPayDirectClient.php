<?php
/**
 * SynraPay Direct API client for PrestaShop 8 (PHP 7.4+) — the same contract as the
 * WooCommerce plugin's Synra_Pay_API::direct_call() and the Shopify / Wix apps:
 *
 *   x-merchant-id:        <Merchant ID>
 *   x-timestamp:          <unix ms>   (strictly increasing: every signature is single-use)
 *   x-signature-version:  2
 *   x-signature:          HMAC_SHA512( "{ts}.{METHOD}.{path}.{rawBody}", api_signature_key )   // hex
 *   path = the URL's own path (e.g. /v1/direct/create-order); body = '' on GET
 *
 *   POST /direct/create-order               LIVE  the order (email + phone required)
 *   POST /direct/hosted-session             LIVE  a session on it → client_token for sdk.js
 *   GET  /direct/payment/{order_id}/status  LIVE  the ORDER decides; the browser never does
 *   POST /refund                            PLACEHOLDER (same as every other plugin)
 *
 * Plain PHP + curl: no OpenCart dependencies, loaded with require_once by the
 * module. Nothing here logs a card or a key.
 */

if (class_exists('SynraPayDirectClient', false)) {
    return;
}

class SynraPayDirectClient
{
    const VERSION = '1.0.0';

    const ENVS = [
        'develop' => ['api' => 'https://dev-api.synrapay.com/v1',     'sdk' => 'https://dev.js.synrapay.com/direct/v1/sdk.js',     'label' => 'Develop', 'prefix' => 'hs_dev_'],
        'sandbox' => ['api' => 'https://sandbox-api.synrapay.com/v1', 'sdk' => 'https://sandbox.js.synrapay.com/direct/v1/sdk.js', 'label' => 'Sandbox', 'prefix' => 'hs_test_'],
        'live'    => ['api' => 'https://api.synrapay.com/v1',         'sdk' => 'https://js.synrapay.com/direct/v1/sdk.js',         'label' => 'Live',    'prefix' => 'hs_live_'],
    ];

    const PATH_CREATE_ORDER   = '/direct/create-order';
    const PATH_HOSTED_SESSION = '/direct/hosted-session';
    const PATH_STATUS         = '/direct/payment/%s/status';
    const PATH_REFUND         = '/refund';

    /** @var int last x-timestamp used by this process (ms) */
    private static $last_ts = 0;

    private $base;
    private $merchant_id;
    private $signature_key;
    private $env;

    public static function normEnv($env): string
    {
        $env = strtolower(trim((string) $env));
        if ($env === 'production' || $env === 'live') {
            return 'live';
        }
        if ($env === 'develop' || $env === 'dev') {
            return 'develop';
        }
        return 'sandbox';
    }

    public function __construct(string $env, string $merchant_id, string $signature_key, string $base_override = '')
    {
        $this->env           = self::normEnv($env);
        $this->base          = rtrim($base_override !== '' ? $base_override : self::ENVS[$this->env]['api'], '/');
        $this->merchant_id   = trim($merchant_id);
        $this->signature_key = trim($signature_key);
    }

    public function env(): string
    {
        return $this->env;
    }

    public function sdkUrl(): string
    {
        return self::ENVS[$this->env]['sdk'];
    }

    public function isConfigured(): bool
    {
        return $this->merchant_id !== '' && $this->signature_key !== '';
    }

    /** Step 1 — POST /v1/direct/create-order. @return array [ok, data, err, http] */
    public function createOrder(array $payload): array
    {
        return $this->call('POST', self::PATH_CREATE_ORDER, $payload);
    }

    /** Step 2 — POST /v1/direct/hosted-session on that order. @return array [ok, data, err, http] */
    public function hostedSession(string $order_id, string $origin, array $options = []): array
    {
        $body = array_merge([
            'order_id'  => $order_id,
            'origin'    => $origin,
            'capture'   => true,
            'three_ds'  => true,
            'save_card' => 'never',
        ], $options);
        return $this->call('POST', self::PATH_HOSTED_SESSION, $body);
    }

    /** Step 3 — GET /v1/direct/payment/{order_id}/status. The ORDER decides. */
    public function status(string $order_id, int $timeout = 45): array
    {
        return $this->call('GET', sprintf(self::PATH_STATUS, rawurlencode($order_id)), null, $timeout);
    }

    /** PLACEHOLDER route, as in the WooCommerce plugin. */
    public function refund(array $payload): array
    {
        return $this->call('POST', self::PATH_REFUND, $payload);
    }

    /**
     * One signed Direct API call. @return array [ok(bool), data(array|null), error(string), http(int)]
     */
    public function call(string $method, string $path, $payload = null, int $timeout = 45): array
    {
        if (!$this->isConfigured()) {
            return [false, null, 'SynraPay is not configured (missing Merchant ID or API key).', 0];
        }
        $method      = strtoupper($method);
        $url         = $this->base . $path;
        $signed_path = (string) parse_url($url, PHP_URL_PATH);
        $body        = $method === 'GET' ? '' : json_encode(is_array($payload) ? $payload : new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Strictly increasing: two calls in the same millisecond would share a
        // signature, and a signature is good for one request only.
        $ts = (int) round(microtime(true) * 1000);
        if ($ts <= self::$last_ts) {
            $ts = self::$last_ts + 1;
        }
        self::$last_ts = $ts;
        $ts  = (string) $ts;
        $sig = hash_hmac('sha512', $ts . '.' . $method . '.' . $signed_path . '.' . $body, $this->signature_key);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-merchant-id: ' . $this->merchant_id,
            'x-timestamp: ' . $ts,
            'x-signature-version: 2',
            'x-signature: ' . $sig,
            'User-Agent: SynraPay-PrestaShop/' . self::VERSION,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => max(3, $timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [false, null, 'Could not reach SynraPay: ' . $cerr, 0];
        }
        $data = json_decode((string) $raw, true);
        if ($http < 200 || $http >= 300 || !is_array($data)) {
            return [false, is_array($data) ? $data : null, self::errorText($data, $http), $http];
        }
        return [true, $data, '', $http];
    }

    /** A readable sentence out of a Direct API error answer. */
    public static function errorText($data, int $code): string
    {
        $msg = sprintf('Gateway error (HTTP %d).', $code);
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            if (!empty($data['error']['message'])) {
                $msg = (string) $data['error']['message'];
            }
            if (!empty($data['error']['code'])) {
                $msg = $data['error']['code'] . ': ' . $msg;
            }
            if (!empty($data['error']['details']) && is_array($data['error']['details'])) {
                $bits = [];
                foreach ($data['error']['details'] as $d) {
                    if (is_array($d) && !empty($d['message'])) {
                        $bits[] = $d['message'];
                    } elseif (is_array($d) && !empty($d['field'])) {
                        $bits[] = $d['field'];
                    }
                }
                if ($bits) {
                    $msg .= ' ' . implode(' ', $bits);
                }
            }
        } elseif (is_array($data) && !empty($data['error_message'])) {
            $msg = (string) $data['error_message'];
        } elseif (is_array($data) && !empty($data['message'])) {
            $msg = (string) $data['message'];
        }
        return $msg;
    }

    /** 'paid' | 'failed' | 'pending' — mirrors the WooCommerce confirm rules (awaiting_mastercard is not final). */
    public static function classify(array $data): string
    {
        $status   = strtoupper((string) ($data['status'] ?? ''));
        $awaiting = isset($data['confirmation']) && $data['confirmation'] === 'awaiting_mastercard';
        if (!$awaiting && in_array($status, ['CAPTURED', 'AUTHORIZED'], true)) {
            return 'paid';
        }
        if (!$awaiting && in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED', 'DECLINED'], true)) {
            return 'failed';
        }
        return 'pending';
    }

    /** Does the status read show a payment was actually attempted (frame_ok may only be honoured then)? */
    public static function evidence(array $data): bool
    {
        return (isset($data['confirmation']) && $data['confirmation'] === 'awaiting_mastercard')
            || !empty($data['gateway_transaction_id']) || !empty($data['transaction_id']) || !empty($data['transactionid']);
    }

    /** The environment a hosted-session client token was minted in, or '' when unprefixed. */
    public static function tokenEnv(string $token): string
    {
        foreach (self::ENVS as $env => $cfg) {
            if (strpos($token, $cfg['prefix']) === 0) {
                return $env;
            }
        }
        return '';
    }

    /**
     * Verify a SynraPay → store webhook over the RAW body:
     *   x-synrapay-signature = HMAC-SHA512( "{x-synrapay-timestamp}.{rawBody}", webhook secret )  (v2)
     * plus the optional API-key header the merchant configured.
     */
    public static function verifyWebhook(string $raw, array $headers, string $secret, string $api_key_name = '', string $api_key_value = ''): bool
    {
        $secret = trim($secret);
        if ($secret === '' || $raw === '') {
            return false;
        }
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = is_array($v) ? (string) reset($v) : (string) $v;
        }
        $ts = (int) ($h['x-synrapay-timestamp'] ?? 0);
        if ($ts <= 0 || abs(time() - $ts) > 300) {
            return false;
        }
        $version = strtolower(trim($h['x-synrapay-signature-version'] ?? ''));
        if ($version !== 'v2' && $version !== '2') {
            return false;
        }
        $expected = hash_hmac('sha512', $ts . '.' . $raw, $secret);
        $received = strtolower(trim($h['x-synrapay-signature'] ?? ''));
        if ($received === '' || !hash_equals($expected, $received)) {
            return false;
        }
        $api_key_name = trim($api_key_name);
        $api_key_value = trim($api_key_value);
        if ($api_key_name !== '' && $api_key_value !== '') {
            $got = $h[strtolower($api_key_name)] ?? '';
            if (!hash_equals($api_key_value, (string) $got)) {
                return false;
            }
        }
        return true;
    }

    /* ── phones: the Direct API wants { phoneCountryCode: '+971', phoneNumber: '501234567' } ── */

    const DIAL_CODES = [
        'AE' => '971', 'SA' => '966', 'QA' => '974', 'KW' => '965', 'BH' => '973', 'OM' => '968', 'JO' => '962', 'LB' => '961', 'EG' => '20', 'IQ' => '964', 'IR' => '98', 'IL' => '972', 'PS' => '970', 'SY' => '963', 'YE' => '967',
        'IN' => '91', 'PK' => '92', 'BD' => '880', 'LK' => '94', 'NP' => '977', 'AF' => '93', 'MV' => '960', 'BT' => '975',
        'US' => '1', 'CA' => '1', 'MX' => '52', 'BR' => '55', 'AR' => '54', 'CL' => '56', 'CO' => '57', 'PE' => '51', 'VE' => '58', 'EC' => '593', 'UY' => '598', 'PY' => '595', 'BO' => '591',
        'GB' => '44', 'IE' => '353', 'FR' => '33', 'DE' => '49', 'IT' => '39', 'ES' => '34', 'PT' => '351', 'NL' => '31', 'BE' => '32', 'LU' => '352', 'CH' => '41', 'AT' => '43', 'SE' => '46', 'NO' => '47', 'DK' => '45', 'FI' => '358', 'IS' => '354',
        'PL' => '48', 'CZ' => '420', 'SK' => '421', 'HU' => '36', 'RO' => '40', 'BG' => '359', 'GR' => '30', 'TR' => '90', 'CY' => '357', 'MT' => '356', 'HR' => '385', 'SI' => '386', 'RS' => '381', 'BA' => '387', 'ME' => '382', 'MK' => '389', 'AL' => '355', 'XK' => '383',
        'UA' => '380', 'BY' => '375', 'RU' => '7', 'KZ' => '7', 'GE' => '995', 'AM' => '374', 'AZ' => '994', 'MD' => '373', 'LT' => '370', 'LV' => '371', 'EE' => '372', 'UZ' => '998', 'KG' => '996', 'TJ' => '992', 'TM' => '993', 'MN' => '976',
        'CN' => '86', 'HK' => '852', 'MO' => '853', 'TW' => '886', 'JP' => '81', 'KR' => '82', 'SG' => '65', 'MY' => '60', 'TH' => '66', 'VN' => '84', 'PH' => '63', 'ID' => '62', 'KH' => '855', 'LA' => '856', 'MM' => '95', 'BN' => '673',
        'AU' => '61', 'NZ' => '64', 'FJ' => '679', 'PG' => '675',
        'ZA' => '27', 'NG' => '234', 'KE' => '254', 'GH' => '233', 'TZ' => '255', 'UG' => '256', 'ET' => '251', 'MA' => '212', 'DZ' => '213', 'TN' => '216', 'LY' => '218', 'SD' => '249', 'SN' => '221', 'CI' => '225', 'CM' => '237', 'RW' => '250', 'ZM' => '260', 'ZW' => '263', 'MU' => '230', 'BW' => '267', 'NA' => '264', 'MZ' => '258', 'AO' => '244', 'CD' => '243', 'SO' => '252', 'DJ' => '253',
    ];

    /**
     * Mirrors the WooCommerce hs_split_phone(): dial code from the billing country when
     * the number has none; 8–15 digits in total or it is not a phone. @return array|null
     */
    public static function splitPhone(string $phone, string $dial, string $country): ?array
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        $dial  = preg_replace('/\D/', '', $dial);
        if ($phone === '') {
            return null;
        }
        if ($dial === '' && $country !== '') {
            $dial = self::DIAL_CODES[strtoupper($country)] ?? '';
        }
        $digits = ltrim($phone, '+');
        if ($dial !== '' && strpos($digits, $dial) === 0 && strlen($digits) > strlen($dial) + 4) {
            $digits = substr($digits, strlen($dial));                       // "+971 50 …" typed with the code in it
        } elseif (strpos($phone, '00') === 0 && $dial !== '' && strpos($digits, '00' . $dial) === 0) {
            $digits = substr($digits, 2 + strlen($dial));                   // "00971 50 …"
        } elseif ($dial === '' && strpos($phone, '+') === 0) {
            // A full international number with no billing country: take the longest known dial code.
            $codes = array_unique(array_values(self::DIAL_CODES));
            usort($codes, function ($a, $b) { return strlen($b) - strlen($a); });
            foreach ($codes as $c) {
                if (strpos($digits, $c) === 0 && strlen($digits) > strlen($c) + 4) {
                    $dial   = $c;
                    $digits = substr($digits, strlen($c));
                    break;
                }
            }
        }
        $digits = ltrim($digits, '0');
        if ($dial === '' || $digits === '' || strlen($dial . $digits) < 8 || strlen($dial . $digits) > 15) {
            return null;
        }
        return ['phoneCountryCode' => '+' . $dial, 'phoneNumber' => $digits];
    }
}
