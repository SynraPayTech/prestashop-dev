<?php
/**
 * Shared plumbing for the module's AJAX front controllers: JSON answers with a
 * real HTTP status, the current cart, the Origin / rate guard and the origin
 * a hosted session is opened for.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class SynrapayAbstractModuleFrontController extends ModuleFrontController
{
    /** @var SynraPay */
    public $module;
    public $ajax = true;
    public $ssl = true;

    public function init()
    {
        $this->ajax = true;
        parent::init();
    }

    /** No HTML is ever rendered by these controllers. */
    public function initContent()
    {
        // postProcess() answered already (or will); nothing to render.
    }

    protected function json(array $data, $code = 200)
    {
        http_response_code((int) $code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $this->ajaxRender(json_encode($data));
        exit;
    }

    protected function plain($code, $text)
    {
        http_response_code((int) $code);
        header('Content-Type: text/plain');
        $this->ajaxRender($text);
        exit;
    }

    /** The cart this checkout is paying for, or null after answering the error. */
    protected function currentCart()
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->id_customer || !$cart->id_address_invoice || !$this->module->active) {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->module->l('Your cart could not be found. Please refresh the page.', 'abstract')], 400);
            return null;
        }
        if ($cart->nbProducts() <= 0) {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->module->l('Your cart is empty.', 'abstract')], 400);
            return null;
        }
        return $cart;
    }

    /**
     * The browser endpoints accept only requests this shop's own pages make (Origin or Referer on
     * this host) and only so many per checkout cookie: each session call creates an order at
     * SynraPay, each confirm call reads one.
     */
    protected function guard($bucket, $max)
    {
        $host = strtolower((string) Tools::getShopDomainSsl(false));
        $host_plain = strtolower((string) Tools::getShopDomain(false));
        $from = '';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $from = (string) parse_url((string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $from = (string) parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        }
        $from = strtolower($from);
        if ($from === '' || ($from !== $host && $from !== $host_plain)) {
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->module->l('Request refused.', 'abstract')], 403);
            return false;
        }
        $now = time();
        $key = 'synra_rl_' . $bucket;
        $raw = isset($this->context->cookie->{$key}) ? (string) $this->context->cookie->{$key} : '';
        $parts = explode(':', $raw);
        $n = (int) ($parts[0] ?? 0);
        $reset = (int) ($parts[1] ?? 0);
        if ($now > $reset) {
            $n = 0;
            $reset = $now + 900;
        }
        $n++;
        $this->context->cookie->{$key} = $n . ':' . $reset;
        $this->context->cookie->write();
        if ($n > $max) {
            $this->module->log('rate limit hit: ' . $bucket, 2);
            $this->json(['ok' => false, 'status' => 'error', 'error' => $this->module->l('Too many attempts. Please wait a few minutes and try again.', 'abstract')], 429);
            return false;
        }
        return true;
    }

    /** The checkout page's own Origin when it is this shop (exact scheme and port), else the shop origin. */
    protected function requestOrigin()
    {
        $site = $this->module->siteOrigin();
        $hdr  = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
        if ($hdr !== '' && strtolower((string) parse_url($hdr, PHP_URL_HOST)) === strtolower((string) parse_url($site, PHP_URL_HOST))) {
            return $hdr;
        }
        return $site;
    }
}
