<?php
/**
 * module/synrapay/session — the payment step asks for card fields.
 *
 * Creates the Direct API order for the cart (or reuses the one already open for
 * the same amount, currency and customer) and opens a hosted session on it.
 * Only the client_token goes back; the signing key never does.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'synrapay/controllers/front/abstract.php';

class SynrapaySessionModuleFrontController extends SynrapayAbstractModuleFrontController
{
    public function postProcess()
    {
        if (!$this->guard('session', 30)) {
            return;
        }
        $cart = $this->currentCart();
        if (!$cart) {
            return;
        }
        if (!$this->module->isConfigured()) {
            $this->json(['ok' => false, 'error' => $this->module->l('SynraPay is not configured (missing Merchant ID or API key).', 'session')], 500);
            return;
        }
        $client = $this->module->client();
        $link = SynraPayLink::getByCart((int) $cart->id);
        if ($link && in_array($link['status'], ['paid', 'pending_confirmation'], true) && (int) $link['id_order']) {
            $customer = new Customer((int) $cart->id_customer);
            $this->json(['ok' => false, 'error' => $this->module->l('This order has already been placed.', 'session'), 'redirect' => $this->module->confirmationUrl($cart->id, $link['id_order'], $customer->secure_key)], 409);
            return;
        }

        $customer = $this->module->customerPayload($cart);
        if (is_string($customer)) {
            $this->json(['ok' => false, 'error' => $customer], 400);
            return;
        }
        list($amount, $currency) = $this->module->cartAmount($cart);
        if ((float) $amount <= 0) {
            $this->json(['ok' => false, 'error' => $this->module->l('There is nothing to pay for yet.', 'session')], 400);
            return;
        }
        if ((int) ($link['attempts'] ?? 0) >= 8) {
            $this->json(['ok' => false, 'error' => $this->module->l('Too many attempts. Please wait a few minutes and try again.', 'session')], 429);
            return;
        }

        $fresh    = (bool) Tools::getValue('fresh');
        $phone_fp = isset($customer['phone']) ? $customer['phone'] : $customer['phoneCountryCode'] . $customer['phoneNumber'];
        $fp       = md5(implode('|', [$amount, $currency, $customer['email'], $phone_fp, $client->env()]));

        $direct_order_id   = isset($link['direct_order_id']) ? $link['direct_order_id'] : '';
        $merchant_order_id = isset($link['merchant_order_id']) ? $link['merchant_order_id'] : '';
        $reusable = !$fresh && $direct_order_id !== '' && ($link['fp'] ?? '') === $fp && ($link['status'] ?? '') === 'created'
            && (time() - strtotime($link['date_upd'])) < 1500;

        if (!$reusable) {
            $merchant_order_id = $this->merchantOrderId((int) $cart->id);
            $shop = trim(strip_tags((string) Configuration::get('PS_SHOP_NAME')));
            $description = preg_replace('/[\x00-\x1F\x7F<>]/u', '', ($shop !== '' ? $shop : 'PrestaShop') . ' order (cart ' . (int) $cart->id . ')');
            list($ok, $data, $err) = $client->createOrder([
                'merchant_order_id' => $merchant_order_id,
                'amount'            => $amount,
                'currency'          => $currency,
                'description'       => mb_substr($description, 0, 255),
                'customer'          => $customer,
            ]);
            if (!$ok || empty($data['order_id'])) {
                $this->module->log('create-order failed for cart #' . $cart->id . ': ' . ($ok ? 'no order_id' : $err), 3);
                $this->json(['ok' => false, 'error' => $ok ? 'No order id returned.' : $err], 502);
                return;
            }
            $history = $link ? trim(($link['history'] ?? '') . ' ' . $link['direct_order_id'] . ' ' . $link['merchant_order_id']) : '';
            $direct_order_id = (string) $data['order_id'];
            if (!empty($data['merchant_order_id'])) {
                $merchant_order_id = (string) $data['merchant_order_id'];
            }
            SynraPayLink::save((int) $cart->id, [
                'direct_order_id'   => $direct_order_id,
                'merchant_order_id' => $merchant_order_id,
                'amount'            => $amount,
                'currency'          => $currency,
                'fp'                => $fp,
                'env'               => $client->env(),
                'status'            => 'created',
                'gateway_ref'       => '',
                'history'           => mb_substr($history, -2000),
                'attempts'          => (int) ($link['attempts'] ?? 0) + 1,
                'id_order'          => 0,
            ]);
        }

        list($ok, $data, $err) = $client->hostedSession($direct_order_id, $this->requestOrigin());
        if (!$ok || empty($data['client_token'])) {
            // The order may be closed (paid, failed, expired): forget its fingerprint so the next call starts a new one.
            SynraPayLink::save((int) $cart->id, ['fp' => '']);
            $this->module->log('hosted-session failed on ' . $direct_order_id . ': ' . ($ok ? 'no client_token' : $err), 3);
            $this->json(['ok' => false, 'error' => $ok ? 'No client token returned.' : $err, 'retry' => !$fresh], 502);
            return;
        }
        // The client token says which environment minted it (hs_live_ / hs_test_ / hs_dev_). It must be
        // the environment this shop is set to, or Sandbox approvals would settle Live orders.
        $token_env = SynraPayDirectClient::tokenEnv((string) $data['client_token']);
        if ($token_env !== '' && $token_env !== $client->env()) {
            SynraPayLink::save((int) $cart->id, ['fp' => '']);
            $this->module->log('ENVIRONMENT_MISMATCH — token is ' . $token_env . ' but the shop is set to ' . $client->env(), 3);
            $this->json(['ok' => false, 'error' => $this->module->l('SynraPay: the API key does not belong to the selected environment. Check the module settings.', 'session')], 409);
            return;
        }

        $this->json([
            'ok'              => true,
            'clientToken'     => $data['client_token'],
            'sdkUrl'          => !empty($data['sdk_url']) ? $data['sdk_url'] : $client->sdkUrl(),
            'expiresAt'       => isset($data['expires_at']) ? $data['expires_at'] : null,
            'sessionId'       => isset($data['session_id']) ? $data['session_id'] : null,
            'orderId'         => $direct_order_id,
            'merchantOrderId' => $merchant_order_id,
            'amount'          => $amount,
            'currency'        => $currency,
        ]);
    }

    /** A merchant_order_id the Direct API accepts: letters, digits and single hyphens. */
    private function merchantOrderId($id_cart)
    {
        $site = strtoupper(substr(md5((string) Tools::getShopDomain(false)), 0, 6));
        $time = strtoupper(base_convert((string) (int) floor(microtime(true) * 1000), 10, 36));
        $rand = strtoupper(bin2hex(random_bytes(2)));
        return 'PS-' . $site . '-' . (int) $id_cart . '-' . $time . '-' . $rand;
    }
}
