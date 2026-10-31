<?php
/**
 * module/synrapay/webhook?token=<per-shop token> — SynraPay → shop, signed.
 * Source of truth, idempotent. Creates the order when the shopper left before
 * the page could confirm, completes an awaiting order, fails a declined one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'synrapay/controllers/front/abstract.php';

class SynrapayWebhookModuleFrontController extends SynrapayAbstractModuleFrontController
{
    public $ssl = false;

    public function postProcess()
    {
        $token = (string) Tools::getValue('token');
        $want  = (string) Configuration::get('SYNRAPAY_WEBHOOK_TOKEN');
        if ($want === '' || $token === '' || !hash_equals($want, $token)) {
            $this->plain(404, 'not found');
            return;
        }
        $raw  = (string) Tools::file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->plain(400, 'invalid payload');
            return;
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!$headers) {
            foreach ($_SERVER as $k => $v) {
                if (strpos($k, 'HTTP_') === 0) {
                    $headers[str_replace('_', '-', substr($k, 5))] = $v;
                }
            }
        }
        if (!SynraPayDirectClient::verifyWebhook($raw, $headers, (string) Configuration::get('SYNRAPAY_WEBHOOK_KEY'), (string) Configuration::get('SYNRAPAY_X_API_KEY_NAME'), (string) Configuration::get('SYNRAPAY_X_API_KEY'))) {
            $this->module->log('webhook refused — signature did not verify', 2);
            $this->plain(401, 'invalid signature');
            return;
        }

        $refs = [(string) ($body['transactionId'] ?? ''), (string) ($body['order_id'] ?? ''), (string) ($body['orderId'] ?? ''), (string) ($body['merchant_order_id'] ?? '')];
        $link = [];
        foreach ($refs as $ref) {
            if ($ref !== '' && !$link) {
                $link = SynraPayLink::getByRef($ref);
            }
        }
        if (!$link) {
            $this->plain(200, 'ok (unknown order)');
            return;
        }
        // A webhook about a superseded Direct order (an earlier declined try) must not settle the current one.
        if (!in_array($link['direct_order_id'], $refs, true) && !in_array($link['merchant_order_id'], $refs, true)) {
            $this->plain(200, 'ok (superseded order)');
            return;
        }
        if ($link['status'] === 'paid') {
            $this->plain(200, 'ok');
            return;
        }
        $status   = strtolower((string) ($body['status'] ?? ''));
        $awaiting = isset($body['confirmation']) && $body['confirmation'] === 'awaiting_mastercard';
        if (!$awaiting && in_array($status, ['success', 'captured', 'authorized', 'paid'], true)) {
            $cart = new Cart((int) $link['id_cart']);
            if (!Validate::isLoadedObject($cart)) {
                $this->plain(200, 'ok (cart gone)');
                return;
            }
            // The shopper left before the page confirmed: no order yet, and the cart may already be
            // ordered by another means — validateOrder refuses a cart that has an order.
            if (!(int) $link['id_order'] && $cart->orderExists()) {
                $this->plain(200, 'ok (cart already ordered)');
                return;
            }
            $this->module->settlePaid($cart, $link, [
                'order_id'               => $link['direct_order_id'],
                'status'                 => $status,
                'amount'                 => isset($body['amount']) ? $body['amount'] : null,
                'currency'               => isset($body['currency']) ? $body['currency'] : null,
                'gateway_transaction_id' => isset($body['gateway_transaction_id']) ? $body['gateway_transaction_id'] : (isset($body['transactionId']) ? $body['transactionId'] : ''),
            ], (int) $link['id_order']);
        } elseif (!$awaiting && in_array($status, ['failed', 'cancelled', 'canceled', 'expired', 'declined'], true)) {
            $this->module->settleFailed($link, $status, (string) ($body['error_message'] ?? ''));
        }
        $this->plain(200, 'ok');
    }
}
