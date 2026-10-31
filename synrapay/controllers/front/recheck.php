<?php
/**
 * module/synrapay/recheck?token=<cron token> — call every 5–15 minutes. Reads
 * the orders left "Awaiting SynraPay confirmation" again and settles them when
 * final (the webhook is the primary path; this is the safety net).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'synrapay/controllers/front/abstract.php';

class SynrapayRecheckModuleFrontController extends SynrapayAbstractModuleFrontController
{
    public $ssl = false;

    public function postProcess()
    {
        $token = (string) Tools::getValue('token');
        $want  = (string) Configuration::get('SYNRAPAY_CRON_TOKEN');
        if ($want === '' || $token === '' || !hash_equals($want, $token)) {
            $this->plain(404, 'not found');
            return;
        }
        if (!$this->module->isConfigured()) {
            $this->plain(200, 'not configured');
            return;
        }
        $client = $this->module->client();
        $done = [];
        foreach (SynraPayLink::getAwaiting() as $link) {
            $cart = new Cart((int) $link['id_cart']);
            if (!Validate::isLoadedObject($cart)) {
                continue;
            }
            list($ok, $data) = $client->status($link['direct_order_id'], 30);
            SynraPayLink::save((int) $link['id_cart'], ['checks' => (int) $link['checks'] + 1]);
            if (!$ok || !is_array($data)) {
                $done[] = $link['id_cart'] . ':unreachable';
                continue;
            }
            $verdict = SynraPayDirectClient::classify($data);
            $this->module->log('recheck cart #' . $link['id_cart'] . ' → ' . ($data['status'] ?? '?') . ' ' . $verdict, 1);
            if ($verdict === 'paid') {
                $this->module->settlePaid($cart, $link, $data, (int) $link['id_order']);
            } elseif ($verdict === 'failed' || (int) $link['checks'] >= 12) {
                $this->module->settleFailed($link, $verdict === 'failed' ? strtolower((string) $data['status']) : 'not confirmed', (string) ($data['error_message'] ?? ''));
            }
            $done[] = $link['id_cart'] . ':' . $verdict;
        }
        $this->plain(200, 'ok ' . count($done) . ' ' . implode(' ', $done));
    }
}
