<?php
/**
 * SynraPay for PrestaShop 8 — "Credit / Debit card" via the SynraPay Direct API
 * hosted session: SynraPay's own card fields (sdk.js, SynraPayFields.create) on
 * the checkout payment step. Same logic as the WooCommerce plugin v2.1, the
 * Magento 2 module, the OpenCart 4 extension and the Shopify / Wix apps.
 *
 *   payment step → module/synrapay/session   Direct create-order for the cart (+ reuse) + hosted-session → clientToken
 *   page         → sdk.js                    the card is typed into SynraPay's frame
 *   "Order"      → fields.submit()           3-D Secure in an overlay; redirect:false
 *   page         → module/synrapay/confirm   GET /direct/payment/{id}/status decides → validateOrder() → order-confirmation
 *   SynraPay     → module/synrapay/webhook   signed, source of truth (creates the order if the shopper left early)
 *   cron         → module/synrapay/recheck   orders left "awaiting confirmation"
 *
 * Requires PrestaShop 8.0.2+ and PHP 7.4+ (no PHP 8 syntax in this module).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

require_once __DIR__ . '/classes/SynraPayDirectClient.php';
require_once __DIR__ . '/classes/SynraPayLink.php';

class SynraPay extends PaymentModule
{
    const CONFIG_KEYS = [
        'SYNRAPAY_ENVIRONMENT', 'SYNRAPAY_MERCHANT_ID', 'SYNRAPAY_API_KEY', 'SYNRAPAY_FALLBACK_PHONE',
        'SYNRAPAY_WEBHOOK_KEY', 'SYNRAPAY_X_API_KEY_NAME', 'SYNRAPAY_X_API_KEY',
    ];
    const SECRET_KEYS = ['SYNRAPAY_API_KEY', 'SYNRAPAY_WEBHOOK_KEY', 'SYNRAPAY_X_API_KEY'];

    public function __construct()
    {
        $this->name = 'synrapay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'SynraPay';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.2', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->controllers = ['session', 'confirm', 'webhook', 'recheck'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('SynraPay — Credit / Debit card');
        $this->description = $this->l('Visa, Mastercard and American Express on your checkout page with SynraPay\'s secure card fields (hosted session).');
        $this->confirmUninstall = $this->l('Remove SynraPay? Orders already paid keep their SynraPay references.');
    }

    /* ── install / uninstall ────────────────────────────────────────────── */

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('actionFrontControllerSetMedia')
            || !$this->registerHook('displayPaymentReturn')
            || !$this->registerHook('displayAdminOrderMainBottom')) {
            return false;
        }
        if (!SynraPayLink::installTable()) {
            return false;
        }
        Configuration::updateValue('SYNRAPAY_ENVIRONMENT', 'develop');
        Configuration::updateValue('SYNRAPAY_X_API_KEY_NAME', 'x-synrapay-key');
        Configuration::updateValue('SYNRAPAY_FALLBACK_PHONE', '971500000000');
        if (!Configuration::get('SYNRAPAY_WEBHOOK_TOKEN')) {
            Configuration::updateValue('SYNRAPAY_WEBHOOK_TOKEN', bin2hex(random_bytes(16)));
        }
        if (!Configuration::get('SYNRAPAY_CRON_TOKEN')) {
            Configuration::updateValue('SYNRAPAY_CRON_TOKEN', bin2hex(random_bytes(16)));
        }
        return $this->installOrderState();
    }

    public function uninstall()
    {
        // The link table and the custom order state are kept on purpose: an uninstall must not
        // erase which SynraPay transaction paid which order, nor orders sitting in that state.
        foreach (self::CONFIG_KEYS as $k) {
            Configuration::deleteByName($k);
        }
        return parent::uninstall();
    }

    /** "Awaiting SynraPay confirmation": approved in the card frame, SynraPay not final yet. */
    private function installOrderState()
    {
        $id = (int) Configuration::get('SYNRAPAY_OS_AWAITING');
        if ($id && Validate::isLoadedObject(new OrderState($id))) {
            return true;
        }
        $state = new OrderState();
        $state->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $state->name[(int) $lang['id_lang']] = 'Awaiting SynraPay confirmation';
        }
        $state->send_email = false;
        $state->color = '#4169E1';
        $state->hidden = false;
        $state->delivery = false;
        $state->logable = false;
        $state->invoice = false;
        $state->paid = false;
        $state->unremovable = true;
        $state->module_name = $this->name;
        if (!$state->add()) {
            return false;
        }
        Configuration::updateValue('SYNRAPAY_OS_AWAITING', (int) $state->id);
        return true;
    }

    /* ── configuration page ─────────────────────────────────────────────── */

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitSynrapay')) {
            $errors = [];
            $merchant = trim((string) Tools::getValue('SYNRAPAY_MERCHANT_ID'));
            if ($merchant === '') {
                $errors[] = $this->l('Merchant ID is required.');
            }
            $env = (string) Tools::getValue('SYNRAPAY_ENVIRONMENT');
            if (!in_array($env, ['develop', 'sandbox', 'live'], true)) {
                $env = 'develop';
            }
            $phone = preg_replace('/\D/', '', (string) Tools::getValue('SYNRAPAY_FALLBACK_PHONE'));
            if ($phone !== '' && (strlen($phone) < 8 || strlen($phone) > 15)) {
                $errors[] = $this->l('Fallback phone must be 8–15 digits including the country code.');
            }
            // Blank secret fields keep the saved value.
            foreach (self::SECRET_KEYS as $k) {
                $v = trim((string) Tools::getValue($k));
                if ($v !== '') {
                    Configuration::updateValue($k, $v);
                }
            }
            if ((string) Configuration::get('SYNRAPAY_API_KEY') === '') {
                $errors[] = $this->l('API Key is required.');
            }
            if (!$errors) {
                Configuration::updateValue('SYNRAPAY_MERCHANT_ID', $merchant);
                Configuration::updateValue('SYNRAPAY_ENVIRONMENT', $env);
                Configuration::updateValue('SYNRAPAY_FALLBACK_PHONE', $phone);
                Configuration::updateValue('SYNRAPAY_X_API_KEY_NAME', trim((string) Tools::getValue('SYNRAPAY_X_API_KEY_NAME')));
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            } else {
                $output .= $this->displayError(implode('<br>', $errors));
            }
        }
        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $webhook_url = $this->context->link->getModuleLink($this->name, 'webhook', ['token' => Configuration::get('SYNRAPAY_WEBHOOK_TOKEN')], true);
        $cron_url    = $this->context->link->getModuleLink($this->name, 'recheck', ['token' => Configuration::get('SYNRAPAY_CRON_TOKEN')], true);
        $origin      = $this->siteOrigin();
        $mask = function ($k) { return (string) Configuration::get($k) !== '' ? $this->l('•••••••• (saved — leave blank to keep)') : ''; };

        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->l('SynraPay settings'), 'icon' => 'icon-credit-card'],
                'description' => $this->l('All credentials come from the SynraPay Merchant Portal → Developers. Each environment (Develop / Sandbox / Live) has its own portal and its own keys — use the keys that match the Environment chosen here.'),
                'input' => [
                    ['type' => 'select', 'label' => $this->l('Environment'), 'name' => 'SYNRAPAY_ENVIRONMENT', 'options' => ['query' => [
                        ['id' => 'develop', 'name' => $this->l('Develop (dev-api.synrapay.com)')],
                        ['id' => 'sandbox', 'name' => $this->l('Sandbox (testing)')],
                        ['id' => 'live', 'name' => $this->l('Live (production)')],
                    ], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'text', 'label' => $this->l('Merchant ID (x-merchant-id)'), 'name' => 'SYNRAPAY_MERCHANT_ID', 'required' => true,
                        'desc' => $this->l('Developers → API Keys → section 1 "Request Signing" → Merchant ID.')],
                    ['type' => 'password', 'label' => $this->l('API Key (signature key)'), 'name' => 'SYNRAPAY_API_KEY', 'hint' => $mask('SYNRAPAY_API_KEY'),
                        'desc' => $this->l('Developers → API Keys → section 1 → API Signature Key. Signs every request to SynraPay; it lives on your server only and is never sent to the browser.') . ' ' . $mask('SYNRAPAY_API_KEY')],
                    ['type' => 'text', 'label' => $this->l('Fallback customer phone'), 'name' => 'SYNRAPAY_FALLBACK_PHONE',
                        'desc' => $this->l('SynraPay requires a phone number on every order. When the customer\'s phone is missing or invalid this number is sent instead (digits with country code, e.g. 971500000000). Leave empty to require a valid customer phone.')],
                    ['type' => 'password', 'label' => $this->l('Webhook signing secret'), 'name' => 'SYNRAPAY_WEBHOOK_KEY',
                        'desc' => $this->l('Developers → Webhooks → Configuration → Signing secret (shown once). Every webhook carries x-synrapay-signature = HMAC-SHA512 of "timestamp.body" with it; a webhook that does not verify is refused with 401.') . ' ' . $mask('SYNRAPAY_WEBHOOK_KEY')],
                    ['type' => 'text', 'label' => $this->l('Webhook API-Key header name'), 'name' => 'SYNRAPAY_X_API_KEY_NAME',
                        'desc' => $this->l('Developers → API Keys → section 2 "Webhook Verification". Optional: leave both API-Key fields empty to verify webhooks by signature only.')],
                    ['type' => 'password', 'label' => $this->l('Webhook API-Key value'), 'name' => 'SYNRAPAY_X_API_KEY', 'desc' => $mask('SYNRAPAY_X_API_KEY')],
                    ['type' => 'html', 'label' => $this->l('Your webhook URL'), 'name' => 'SYNRAPAY_WEBHOOK_URL_HTML',
                        'html_content' => '<input type="text" class="form-control" readonly onclick="this.select()" value="' . Tools::safeOutput($webhook_url) . '"><p class="help-block">' . $this->l('Merchant Portal → Developers → Webhooks → Update Webhook → paste as the Endpoint URL with All events subscribed.') . '</p>'],
                    ['type' => 'html', 'label' => $this->l('Your checkout origin'), 'name' => 'SYNRAPAY_ORIGIN_HTML',
                        'html_content' => '<input type="text" class="form-control" readonly onclick="this.select()" value="' . Tools::safeOutput($origin) . '"><p class="help-block">' . $this->l('Hosted session binds each card session to this website. Register this origin on your SynraPay merchant (Developers → Integrations) or the card form is refused with ORIGIN_NOT_REGISTERED.') . '</p>'],
                    ['type' => 'html', 'label' => $this->l('Re-check cron URL'), 'name' => 'SYNRAPAY_CRON_URL_HTML',
                        'html_content' => '<input type="text" class="form-control" readonly onclick="this.select()" value="' . Tools::safeOutput($cron_url) . '"><p class="help-block">' . $this->l('Call this every 5–15 minutes (server cron or the Cron tasks manager module). It re-reads orders left "Awaiting SynraPay confirmation"; the webhook is the primary path.') . '</p>'],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitSynrapay';
        $helper->fields_value = [
            'SYNRAPAY_ENVIRONMENT'      => Configuration::get('SYNRAPAY_ENVIRONMENT'),
            'SYNRAPAY_MERCHANT_ID'      => Configuration::get('SYNRAPAY_MERCHANT_ID'),
            'SYNRAPAY_API_KEY'          => '',
            'SYNRAPAY_FALLBACK_PHONE'   => Configuration::get('SYNRAPAY_FALLBACK_PHONE'),
            'SYNRAPAY_WEBHOOK_KEY'      => '',
            'SYNRAPAY_X_API_KEY_NAME'   => Configuration::get('SYNRAPAY_X_API_KEY_NAME'),
            'SYNRAPAY_X_API_KEY'        => '',
            'SYNRAPAY_WEBHOOK_URL_HTML' => '',
            'SYNRAPAY_ORIGIN_HTML'      => '',
            'SYNRAPAY_CRON_URL_HTML'    => '',
        ];
        return $helper->generateForm([$fields_form]);
    }

    /* ── shared helpers used by the front controllers ───────────────────── */

    public function client()
    {
        return new SynraPayDirectClient(
            (string) Configuration::get('SYNRAPAY_ENVIRONMENT'),
            (string) Configuration::get('SYNRAPAY_MERCHANT_ID'),
            (string) Configuration::get('SYNRAPAY_API_KEY')
        );
    }

    public function isConfigured()
    {
        return (string) Configuration::get('SYNRAPAY_MERCHANT_ID') !== '' && (string) Configuration::get('SYNRAPAY_API_KEY') !== '';
    }

    public function env()
    {
        return SynraPayDirectClient::normEnv((string) Configuration::get('SYNRAPAY_ENVIRONMENT'));
    }

    /** scheme://host[:port] of this shop, as the browser writes it in the Origin header. */
    public function siteOrigin()
    {
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED');
        $domain = $ssl ? Tools::getShopDomainSsl(false) : Tools::getShopDomain(false);
        return ($ssl ? 'https://' : 'http://') . $domain;
    }

    public function log($message, $severity = 1)
    {
        PrestaShopLogger::addLog('SynraPay: ' . $message, (int) $severity, null, 'SynraPay');
    }

    /** @return array [amount(string, 2 dp), currency iso, id_currency] */
    public function cartAmount(Cart $cart)
    {
        $currency = new Currency((int) $cart->id_currency);
        $amount = Tools::ps_round((float) $cart->getOrderTotal(true, Cart::BOTH), 2);
        return [number_format($amount, 2, '.', ''), strtoupper((string) $currency->iso_code), (int) $currency->id];
    }

    /** The customer in the Direct API's shape (email + phone required), or an error sentence. */
    public function customerPayload(Cart $cart)
    {
        $customer = new Customer((int) $cart->id_customer);
        $address  = new Address((int) $cart->id_address_invoice);
        $email = strtolower(trim((string) $customer->email));
        if (!Validate::isEmail($email)) {
            return $this->l('Enter a valid email address to load the card form.');
        }
        $out = ['email' => $email];
        $iso = Validate::isLoadedObject($address) ? (string) Country::getIsoById((int) $address->id_country) : '';
        $split = null;
        foreach ([(string) $address->phone_mobile, (string) $address->phone] as $phone) {
            if ($phone !== '') {
                $split = SynraPayDirectClient::splitPhone($phone, '', $iso);
                if ($split) {
                    break;
                }
            }
        }
        if ($split) {
            $out['phoneCountryCode'] = $split['phoneCountryCode'];
            $out['phoneNumber']      = $split['phoneNumber'];
        } else {
            $fallback = preg_replace('/\D/', '', (string) Configuration::get('SYNRAPAY_FALLBACK_PHONE'));
            if (strlen($fallback) < 8 || strlen($fallback) > 15) {
                return $this->l('Enter a valid phone number to load the card form.');
            }
            $out['phone'] = '+' . $fallback;
        }
        foreach (['firstName' => (string) ($address->firstname ?: $customer->firstname), 'lastName' => (string) ($address->lastname ?: $customer->lastname)] as $k => $v) {
            $v = trim($v);
            if (mb_strlen($v) >= 2 && mb_strlen($v) <= 120 && preg_match('/\p{L}/u', $v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Paid at SynraPay for the cart's amount: create the PrestaShop order (or move an awaiting one
     * to Payment accepted). Returns the order id, or 0 when the amount does not match.
     */
    public function settlePaid(Cart $cart, array $link, array $data, $awaiting_order_id = 0)
    {
        list($total, $currency, $id_currency) = $this->cartAmount($cart);
        $paid_amount = isset($data['amount']) && $data['amount'] !== null ? number_format(round((float) $data['amount'], 2), 2, '.', '') : null;
        $paid_ccy    = !empty($data['currency']) ? strtoupper((string) $data['currency']) : null;
        $ref = (string) ($data['gateway_transaction_id'] ?? '');

        if ($awaiting_order_id) {
            $order = new Order((int) $awaiting_order_id);
            if (!Validate::isLoadedObject($order)) {
                return 0;
            }
            $order_total = number_format((float) $order->total_paid, 2, '.', '');
            if ($paid_amount !== null && $paid_amount !== $order_total) {
                $this->log('amount mismatch on order #' . $order->id . ': paid ' . $paid_ccy . ' ' . $paid_amount . ' vs ' . $order_total, 3);
                SynraPayLink::save((int) $cart->id, ['status' => 'mismatch']);
                return 0;
            }
            $this->changeState($order, (int) Configuration::get('PS_OS_PAYMENT'));
            SynraPayLink::save((int) $cart->id, ['status' => 'paid', 'gateway_ref' => mb_substr($ref, 0, 128), 'awaiting_since' => null, 'id_order' => (int) $order->id]);
            return (int) $order->id;
        }

        if ($paid_amount !== null && ($paid_amount !== $total || ($paid_ccy && $paid_ccy !== $currency))) {
            $this->log('amount mismatch on cart #' . $cart->id . ': paid ' . $paid_ccy . ' ' . $paid_amount . ' vs ' . $currency . ' ' . $total, 3);
            SynraPayLink::save((int) $cart->id, ['status' => 'mismatch']);
            return 0;
        }
        $customer = new Customer((int) $cart->id_customer);
        $message = 'SynraPay transaction ' . $link['direct_order_id'] . ($ref !== '' ? ' (gateway ref ' . $ref . ')' : '');
        $this->validateOrder((int) $cart->id, (int) Configuration::get('PS_OS_PAYMENT'), (float) $total, $this->displayName, $message, ['transaction_id' => $link['direct_order_id']], $id_currency, false, $customer->secure_key);
        $id_order = (int) $this->currentOrder;
        SynraPayLink::save((int) $cart->id, ['status' => 'paid', 'gateway_ref' => mb_substr($ref, 0, 128), 'awaiting_since' => null, 'id_order' => $id_order]);
        return $id_order;
    }

    /** Approved in the card frame, SynraPay not final: place the order in the awaiting state. */
    public function settleAwaiting(Cart $cart, array $link)
    {
        list($total, $currency, $id_currency) = $this->cartAmount($cart);
        $customer = new Customer((int) $cart->id_customer);
        $this->validateOrder((int) $cart->id, (int) Configuration::get('SYNRAPAY_OS_AWAITING'), (float) $total, $this->displayName, 'SynraPay: card frame reported the payment approved; waiting for SynraPay\'s final confirmation (webhook or re-check). Transaction ' . $link['direct_order_id'], ['transaction_id' => $link['direct_order_id']], $id_currency, false, $customer->secure_key);
        $id_order = (int) $this->currentOrder;
        SynraPayLink::save((int) $cart->id, ['status' => 'pending_confirmation', 'awaiting_since' => date('Y-m-d H:i:s'), 'checks' => 0, 'id_order' => $id_order]);
        return $id_order;
    }

    /** A final decline on an order placed as awaiting → Payment error. */
    public function settleFailed(array $link, $status, $reason = '')
    {
        if ($link['status'] === 'pending_confirmation' && (int) $link['id_order']) {
            $order = new Order((int) $link['id_order']);
            if (Validate::isLoadedObject($order)) {
                $this->changeState($order, (int) Configuration::get('PS_OS_ERROR'));
                $this->log('order #' . $order->id . ' failed: ' . $status . ' ' . $reason, 2);
            }
        }
        SynraPayLink::save((int) $link['id_cart'], ['status' => 'failed', 'awaiting_since' => null]);
    }

    public function changeState(Order $order, $id_state)
    {
        if ((int) $order->current_state === (int) $id_state) {
            return;
        }
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState((int) $id_state, $order, true);
        $history->addWithemail(true);
    }

    public function confirmationUrl($id_cart, $id_order, $secure_key)
    {
        return $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $id_cart, 'id_module' => (int) $this->id, 'id_order' => (int) $id_order, 'key' => $secure_key,
        ]);
    }

    /* ── hooks ──────────────────────────────────────────────────────────── */

    public function hookActionFrontControllerSetMedia()
    {
        if (!($this->context->controller instanceof OrderController) || !$this->active || !$this->isConfigured()) {
            return;
        }
        $this->context->controller->registerStylesheet('modules-synrapay-hs', 'modules/' . $this->name . '/views/css/synra-hs.css', ['media' => 'all', 'priority' => 150]);
        $this->context->controller->registerJavascript('modules-synrapay-hs', 'modules/' . $this->name . '/views/js/synra-hs.js', ['position' => 'bottom', 'priority' => 150]);
        Media::addJsDef(['synrapayConfig' => [
            'sessionUrl' => $this->context->link->getModuleLink($this->name, 'session', [], true),
            'confirmUrl' => $this->context->link->getModuleLink($this->name, 'confirm', [], true),
            'sdkUrl'     => SynraPayDirectClient::ENVS[$this->env()]['sdk'],
            'i18n'       => [
                'processing' => $this->l('Processing your payment…'),
                'confirming' => $this->l('Confirming your payment…'),
                'notReady'   => $this->l('The card form is still loading.'),
                'incomplete' => $this->l('Please complete your card details.'),
                'declined'   => $this->l('Your payment was not completed. Please try again or use another card.'),
                'expired'    => $this->l('The card session expired. A new one is loading…'),
                'sdkFailed'  => $this->l('Could not load the card form. Please refresh the page.'),
            ],
        ]]);
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->isConfigured()) {
            return [];
        }
        $cart = $params['cart'];
        if (!$this->checkCurrency($cart)) {
            return [];
        }
        // Card data must travel over TLS: no card form on a plain-http Live shop.
        if ($this->env() === 'live' && !Configuration::get('PS_SSL_ENABLED')) {
            return [];
        }
        $this->context->smarty->assign([
            'synrapay_test'        => $this->env() !== 'live',
            'synrapay_description' => $this->l('Visa, Mastercard and American Express accepted. Secured by SynraPay.'),
            'synrapay_secure'      => $this->l('Your card details are entered in SynraPay\'s secure frame and are never seen by this store. Payments processed by SynraPay.'),
            'synrapay_confirm_url' => $this->context->link->getModuleLink($this->name, 'confirm', [], true),
        ]);
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Credit / Debit card'))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'))
            ->setAdditionalInformation($this->context->smarty->fetch('module:' . $this->name . '/views/templates/hook/payment_option.tpl'))
            ->setForm($this->context->smarty->fetch('module:' . $this->name . '/views/templates/hook/payment_form.tpl'));
        return [$option];
    }

    public function checkCurrency(Cart $cart)
    {
        $currency_order = new Currency((int) $cart->id_currency);
        $currencies_module = $this->getCurrency((int) $cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ((int) $currency_order->id === (int) $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || empty($params['order'])) {
            return '';
        }
        $order = $params['order'];
        $awaiting = (int) $order->current_state === (int) Configuration::get('SYNRAPAY_OS_AWAITING');
        $this->context->smarty->assign([
            'synrapay_awaiting' => $awaiting,
            'synrapay_reference' => $order->reference,
        ]);
        return $this->fetch('module:' . $this->name . '/views/templates/hook/payment_return.tpl');
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        $id_order = (int) ($params['id_order'] ?? 0);
        if (!$id_order) {
            return '';
        }
        $link = SynraPayLink::getByOrder($id_order);
        if (!$link) {
            return '';
        }
        $this->context->smarty->assign(['synrapay_link' => $link]);
        return $this->fetch('module:' . $this->name . '/views/templates/hook/admin_order.tpl');
    }
}
