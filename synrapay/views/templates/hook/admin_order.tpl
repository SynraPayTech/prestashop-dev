{* The ids as the SynraPay merchant dashboard names them, so the two can be matched line for line. *}
<div class="card mt-2">
  <div class="card-header"><h3 class="card-header-title">SynraPay</h3></div>
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-3">Merchant order ID</dt><dd class="col-sm-9"><code>{$synrapay_link.merchant_order_id|escape:'html':'UTF-8'}</code></dd>
      <dt class="col-sm-3">Transaction ID</dt><dd class="col-sm-9">{if !empty($synrapay_link.txn_id)}<code>{$synrapay_link.txn_id|escape:'html':'UTF-8'}</code>{else}<code>{$synrapay_link.direct_order_id|escape:'html':'UTF-8'}</code> <small class="text-muted">(SynraPay order ID — Transaction ID not received yet)</small>{/if}</dd>
      <dt class="col-sm-3">SynraPay order ID</dt><dd class="col-sm-9"><code>{$synrapay_link.direct_order_id|escape:'html':'UTF-8'}</code></dd>
      {if !empty($synrapay_link.auth_code)}<dt class="col-sm-3">Auth code</dt><dd class="col-sm-9"><code>{$synrapay_link.auth_code|escape:'html':'UTF-8'}</code></dd>{/if}
      <dt class="col-sm-3">Status</dt><dd class="col-sm-9">{$synrapay_link.status|escape:'html':'UTF-8'} · {$synrapay_link.env|escape:'html':'UTF-8'} · {$synrapay_link.currency|escape:'html':'UTF-8'} {$synrapay_link.amount|string_format:"%.2f"}</dd>
    </dl>
  </div>
</div>
