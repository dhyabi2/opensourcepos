<?php

namespace App\Plugins\NanoPlugin\Controllers;

use App\Controllers\Secure_Controller;
use App\Libraries\Payments\PaymentProviderRegistry;
use App\Libraries\Sale_lib;
use App\Plugins\NanoPlugin\Libraries\NanoPaymentProvider;
use App\Plugins\NanoPlugin\NanoPlugin;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Register endpoints of the Nano plugin: show the exact amount to send, and
 * credit a payment once its send block verifies.
 */
class NanoController extends Secure_Controller
{
    private Sale_lib $saleLib;

    public function __construct()
    {
        parent::__construct('sales');
        $this->saleLib = new Sale_lib();
    }

    private function provider(): ?NanoPaymentProvider
    {
        $provider = PaymentProviderRegistry::getInstance()->getProvider(NanoPaymentProvider::PROVIDER_ID);

        return ($provider instanceof NanoPaymentProvider && $provider->isAvailable()) ? $provider : null;
    }

    /**
     * The exact amount (XNO and raw), account and representative for the
     * in-progress sale. The quote is stored server side with the sale.
     *
     * @noinspection PhpUnused
     */
    public function getPaymentRequest(): ResponseInterface
    {
        $provider = $this->provider();
        if ($provider === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => lang('NanoPlugin.not_enabled')]);
        }

        $amountDue = $this->saleLib->get_amount_due();
        $store = NanoPlugin::quoteStore();
        $quote = $store->get();

        // Keep the order reference only while the quote still describes this
        // sale: the payer may already have sent a block bound to it.
        $orderRef = ($quote !== null && bccomp((string) $quote['amount_due'], $amountDue, totals_decimals()) === 0)
            ? (string) $quote['order_ref']
            : bin2hex(random_bytes(16));

        $result = $provider->quote($orderRef, (string) $amountDue);
        if (!$result['success']) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $result['error']]);
        }

        $store->set([
            'order_ref'  => $orderRef,
            'price_raw'  => (string) $result['request']['amount_raw'],
            'amount_due' => (string) $amountDue,
        ] + ($quote !== null && $quote['order_ref'] === $orderRef && isset($quote['suspended_sale_id'])
            ? ['suspended_sale_id' => (int) $quote['suspended_sale_id']] : []));

        return $this->response->setJSON($result['request']);
    }

    /**
     * Verify the entered block and credit the quoted sale-currency amount.
     * The posted amount is never used: the block pays exactly the quote.
     *
     * @noinspection PhpUnused
     */
    public function postAddPayment(): ResponseInterface
    {
        $provider = $this->provider();
        if ($provider === null) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => lang('NanoPlugin.not_enabled')]);
        }

        $blockHash = trim((string) $this->request->getPost('block_hash'));
        $store = NanoPlugin::quoteStore();
        $quote = $store->get();

        if ($blockHash === '') {
            return $this->response->setJSON(['success' => false, 'message' => lang('NanoPlugin.enter_block_hash')]);
        }
        if ($quote === null) {
            return $this->response->setJSON(['success' => false, 'message' => lang('NanoPlugin.not_initiated')]);
        }
        if (bccomp((string) $quote['amount_due'], $this->saleLib->get_amount_due(), totals_decimals()) !== 0) {
            return $this->response->setJSON(['success' => false, 'message' => lang('NanoPlugin.quote_changed')]);
        }

        $result = $provider->processCallback([
            'block_hash' => $blockHash,
            'order_ref'  => $quote['order_ref'],
            'price_raw'  => $quote['price_raw'],
        ]);

        if (!$result['success']) {
            return $this->response->setJSON(['success' => false, 'message' => lang('NanoPlugin.payment_refused', [$result['error']])]);
        }

        $label = NanoPlugin::paymentTypeLabel();
        $this->saleLib->addPayment($label, (string) $quote['amount_due'], strtoupper($blockHash));
        session()->set('nano_plugin_verified', $this->saleLib->getPayments()[$label] ?? null);
        $store->forgetSuspended($quote);
        $store->clear();

        return $this->response->setJSON(['success' => true]);
    }
}
