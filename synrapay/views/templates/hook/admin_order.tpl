<div class="card mt-2">
  <div class="card-header"><h3 class="card-header-title">SynraPay</h3></div>
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-3">Transaction (order id)</dt><dd class="col-sm-9"><code>{$synrapay_link.direct_order_id|escape:'html':'UTF-8'}</code></dd>
      <dt class="col-sm-3">Merchant order id</dt><dd class="col-sm-9"><code>{$synrapay_link.merchant_order_id|escape:'html':'UTF-8'}</code></dd>
      <dt class="col-sm-3">Gateway reference</dt><dd class="col-sm-9">{if $synrapay_link.gateway_ref}<code>{$synrapay_link.gateway_ref|escape:'html':'UTF-8'}</code>{else}—{/if}</dd>
      <dt class="col-sm-3">Status</dt><dd class="col-sm-9">{$synrapay_link.status|escape:'html':'UTF-8'} · {$synrapay_link.env|escape:'html':'UTF-8'} · {$synrapay_link.currency|escape:'html':'UTF-8'} {$synrapay_link.amount|string_format:"%.2f"}</dd>
    </dl>
  </div>
</div>
