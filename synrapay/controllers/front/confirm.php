<?php
/**
 * module/synrapay/confirm — after fields.submit(), the page asks whether the
 * ORDER is paid. GET /direct/payment/{order_id}/status decides; the page's own
 * view of the result is never trusted. Only here (or in the webhook) does the
 * PrestaShop order get created, with validateOrder().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'synrapay/controllers/front/abstract.php';

class SynrapayConfirmModuleFrontController extends SynrapayAbstractModuleFrontController
{
    public function postProcess()
    {
        if (!$this->guard('confirm', 300)) {
            return;
        }
        $cart = $this->currentCart();
        if (!$cart) {
            return;
        }
        $customer = new Customer((int) $cart->id_customer);
        $link = SynraPayLink::getByCart((int) $cart->id);
        if (!$link || $link['direct_order_id'] === '') {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->module->l('No card session on this order.', 'confirm')], 400);
            return;
        }
        if (in_array($link['status'], ['paid', 'pending_confirmation'], true) && (int) $link['id_order']) {
            $this->json(['ok' => true, 'status' => 'paid', 'redirect' => $this->module->confirmationUrl($cart->id, $link['id_order'], $customer->secure_key)]);
            return;
        }
        // The cart must still be payable for this exact Direct order: same amount and currency.
        list($amount, $currency) = $this->module->cartAmount($cart);
        if (number_format((float) $link['amount'], 2, '.', '') !== $amount || strtoupper($link['currency']) !== $currency) {
            SynraPayLink::save((int) $cart->id, ['fp' => '']);
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->module->l('Your order total changed. The card form has been refreshed — please try again.', 'confirm'), 'refresh' => true], 409);
            return;
        }

        // frame_ok is a hint from the browser ("the frame said approved"). It only shortens the status
        // timeout and is honoured below ONLY when SynraPay's own status read shows a payment was attempted.
        $frame_ok = (bool) Tools::getValue('frame_ok');
        list($ok, $data, $err) = $this->module->client()->status($link['direct_order_id'], $frame_ok ? 6 : 45);
        if (!$ok || !is_array($data)) {
            $this->json(['ok' => false, 'status' => 'pending', 'error' => $frame_ok ? '' : $err]);
            return;
        }
        $verdict = SynraPayDirectClient::classify($data);
        $this->module->log('confirm cart #' . $cart->id . ' → ' . ($data['status'] ?? '?') . (isset($data['confirmation']) ? ' (' . $data['confirmation'] . ')' : '') . ($frame_ok ? ' frame:ok' : ''), 1);

        if ($verdict === 'paid') {
            $id_order = $this->module->settlePaid($cart, $link, $data);
            if (!$id_order) {
                $this->json(['ok' => false, 'status' => 'error', 'error' => $this->module->l('The payment did not match this order. Please contact the store.', 'confirm')]);
                return;
            }
            $this->json(['ok' => true, 'status' => 'paid', 'redirect' => $this->module->confirmationUrl($cart->id, $id_order, $customer->secure_key)]);
            return;
        }
        if ($verdict === 'failed') {
            $reason = isset($data['error_message']) ? $data['error_message'] : (isset($data['reason']) ? $data['reason'] : strtolower((string) $data['status']));
            // The customer is still on the page: no PrestaShop order exists; the next session() call
            // opens a NEW Direct order for the retry.
            SynraPayLink::save((int) $cart->id, ['status' => 'failed']);
            $this->json(['ok' => false, 'status' => 'failed', 'error' => $reason]);
            return;
        }
        if ($frame_ok && SynraPayDirectClient::evidence($data)) {
            // Approved at the gateway, final settlement pending at SynraPay: do not keep the customer
            // waiting. The order is placed in "Awaiting SynraPay confirmation"; webhook / recheck finish it.
            $id_order = $this->module->settleAwaiting($cart, $link);
            $this->json(['ok' => true, 'status' => 'paid', 'redirect' => $this->module->confirmationUrl($cart->id, $id_order, $customer->secure_key)]);
            return;
        }
        $this->json(['ok' => false, 'status' => 'pending']);
    }
}
