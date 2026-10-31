{if $synrapay_awaiting}
  <p class="alert alert-info">{l s='Your card payment was approved and is being confirmed by SynraPay. Your order %s will be marked as paid in a few minutes.' sprintf=[$synrapay_reference] mod='synrapay'}</p>
{else}
  <p>{l s='Your card payment was received by SynraPay. Thank you for your order %s.' sprintf=[$synrapay_reference] mod='synrapay'}</p>
{/if}
