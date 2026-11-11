<?php
/**
 * 1.0.2 — the link table keeps SynraPay's Transaction ID (the merchant dashboard's
 * "Transaction ID", synrapay_transaction_id) and the authorisation code next to
 * the Direct API order id. The old gateway_ref column is kept but no longer written.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    require_once _PS_MODULE_DIR_ . 'synrapay/classes/SynraPayLink.php';
    return SynraPayLink::ensureColumns();
}
