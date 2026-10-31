{*
  The form PrestaShop submits when the customer clicks "Order with an obligation
  to pay" with this option selected. synra-hs.js intercepts the submit, runs
  fields.submit() and then asks module/synrapay/confirm; without JavaScript the
  POST lands on confirm, which reads the ORDER status and never charges by itself.
*}
<form action="{$synrapay_confirm_url|escape:'html':'UTF-8'}" method="post" id="synra-pay-form" data-synra-form>
  <input type="hidden" name="synra_pay" value="1">
</form>
