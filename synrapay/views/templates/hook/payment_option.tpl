{*
  Shown under the "Credit / Debit card" option when it is selected. The card
  fields are mounted here by views/js/synra-hs.js; the actual "Order" click is
  PrestaShop's own button, which submits payment_form.tpl (intercepted by the JS).
*}
<div class="synra-pay-hs" id="synra-pay-hs" data-synra-mount>
  <div class="synra-pay-head">
    <small class="text-muted">{$synrapay_description|escape:'html':'UTF-8'}</small>
    {if $synrapay_test}<span class="synra-pay-test">Test mode</span>{/if}
  </div>
  <div class="synra-pay-mount" id="synra-pay-mount">
    <div id="synra-pay-skeleton" aria-hidden="true">
      <span class="synra-pay-sk synra-pay-sk-label"></span><span class="synra-pay-sk synra-pay-sk-field"></span>
      <span class="synra-pay-sk-row"><span class="synra-pay-sk synra-pay-sk-half"></span><span class="synra-pay-sk synra-pay-sk-half"></span></span>
    </div>
    <div id="synra-pay-fields" style="display:none"></div>
  </div>
  <p class="synra-pay-status" id="synra-pay-status"></p>
  <div class="synra-pay-foot">{$synrapay_secure|escape:'html':'UTF-8'}</div>
  <div class="synra-pay-overlay" id="synra-pay-overlay"><span id="synra-pay-overlay-text"></span></div>
</div>
