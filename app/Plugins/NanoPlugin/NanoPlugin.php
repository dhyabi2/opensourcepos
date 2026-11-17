<?php

namespace App\Plugins\NanoPlugin;

use App\Libraries\Payments\PaymentProviderRegistry;
use App\Libraries\Plugins\BasePlugin;
use App\Libraries\Sale_lib;
use App\Plugins\NanoPlugin\Libraries\NanoPaymentProvider;
use App\Plugins\NanoPlugin\Libraries\PaymentGateway;
use App\Plugins\NanoPlugin\Libraries\QuoteStore;
use CodeIgniter\Events\Events;

/**
 * Accept Nano (XNO) in the sales register, verified against a node.
 *
 * Opt-in: nothing changes until the plugin is enabled AND an account, a node
 * URL and an XNO price are configured. The plugin registers a Nano payment
 * provider with the payment provider registry, renders the Nano panel in the
 * register's payment area, and credits a payment only after the send block is
 * read from the node and recorded in the durable claims ledger. The credited
 * amount is the sale-currency amount stored with the quote on the server.
 */
class NanoPlugin extends BasePlugin
{
    public const SETTING_KEYS = ['nano_account', 'nano_node_url', 'nano_xno_price', 'nano_replay_window_seconds', 'nano_claims_path'];

    private bool $panelRendered = false;

    public function getPluginId(): string
    {
        return 'nano';
    }

    public function getPluginName(): string
    {
        return 'Nano (XNO)';
    }

    public function getPluginDescription(): string
    {
        return lang('NanoPlugin.description');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function registerEvents(): void
    {
        PaymentProviderRegistry::getInstance()->register($this->provider());

        // Payment area of the register (#4458 hook), with the plugin-system
        // register hook as a fallback; the panel renders once either way.
        Events::on('payment_view:register_payment_actions', [$this, 'injectRegisterPanel']);
        Events::on('view:sales_register_buttons', [$this, 'injectRegisterPanel']);

        // A Nano entry may only come from a verified block (see enforceVerified).
        Events::on('payment_initiated', [$this, 'onPaymentInitiated']);

        Events::on('sale_completed', [$this, 'onSaleFinished']);
        Events::on('return_completed', [$this, 'onSaleFinished']);
        Events::on('sale_suspended', [$this, 'onSaleSuspended']);
        Events::on('sale_unsuspended', [$this, 'onSaleUnsuspended']);
    }

    public function install(): bool
    {
        $this->setSetting('nano_account', '');
        $this->setSetting('nano_node_url', '');
        $this->setSetting('nano_xno_price', '');
        $this->setSetting('nano_replay_window_seconds', '86400');
        $this->setSetting('nano_claims_path', '');

        return true;
    }

    public function getConfigView(): ?string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'config';
    }

    public function getSettings(): array
    {
        $settings = ['enabled' => $this->getSetting('enabled', '0')];
        foreach (self::SETTING_KEYS as $key) {
            $settings[$key] = (string) $this->getSetting($key, $key === 'nano_replay_window_seconds' ? '86400' : '');
        }

        return $settings;
    }

    public function saveSettings(array $settings): bool
    {
        $allowed = array_flip(array_merge(self::SETTING_KEYS, ['enabled']));

        return parent::saveSettings(array_intersect_key(array_map(
            static fn ($v) => is_string($v) ? trim($v) : $v,
            $settings
        ), $allowed));
    }

    /**
     * The settings PaymentGateway reads. nano_enabled is true only because
     * PluginManager calls registerEvents() for enabled plugins alone.
     */
    public function gatewaySettings(): array
    {
        return ['nano_enabled' => $this->isEnabled() ? '1' : '0'] + $this->getSettings();
    }

    public function provider(): NanoPaymentProvider
    {
        return new NanoPaymentProvider($this->gatewaySettings(), self::paymentTypeLabel());
    }

    public static function paymentTypeLabel(): string
    {
        return lang('NanoPlugin.payment_type');
    }

    public static function quoteStore(): QuoteStore
    {
        $session = session();

        return new QuoteStore(
            static fn () => $session->get(QuoteStore::SESSION_KEY),
            static function (?array $quote) use ($session): void {
                $quote === null ? $session->remove(QuoteStore::SESSION_KEY) : $session->set(QuoteStore::SESSION_KEY, $quote);
            },
            WRITEPATH . 'nano' . DIRECTORY_SEPARATOR . 'suspended'
        );
    }

    public function injectRegisterPanel(array $data = []): void
    {
        if ($this->panelRendered || !PaymentGateway::isEnabled($this->gatewaySettings())) {
            return;
        }
        $this->panelRendered = true;

        echo $this->renderView('register_payment', ['paymentType' => self::paymentTypeLabel()]);
    }

    /**
     * The core payment form accepts any payment type string, so a Nano entry
     * typed into it would be an unverified Nano payment. After every core add,
     * put the Nano entry back to exactly what this plugin verified (or remove
     * it), so a Nano payment in the cart always comes from a verified block.
     */
    public function onPaymentInitiated(array $data): void
    {
        if (($data['payment_type'] ?? null) !== self::paymentTypeLabel()) {
            return;
        }

        $saleLib = new Sale_lib();
        $payments = $saleLib->getPayments();
        $verified = session()->get('nano_plugin_verified');

        if (is_array($verified) && isset($verified['payment_amount'])) {
            $payments[self::paymentTypeLabel()] = $verified;
        } else {
            unset($payments[self::paymentTypeLabel()]);
        }

        $saleLib->setPayments($payments);
        $this->log('warning', 'A Nano payment was posted to the generic payment form and was reverted to the verified amount.');
    }

    public function onSaleFinished(int $saleId = 0, mixed $unused = null, array $pluginData = []): void
    {
        $store = self::quoteStore();
        $store->forgetSuspended($store->get());
        $store->clear();
        session()->remove('nano_plugin_verified');
    }

    public function onSaleSuspended(int $saleId, array $pluginData = []): void
    {
        $store = self::quoteStore();
        $quote = $store->get();

        if (!$store->suspend($saleId)) {
            // The quote stays in this register session, so it is not lost; the
            // operator can still recover it from this log line.
            $this->log('error', sprintf(
                'Could not save the Nano quote of suspended sale %d (order_ref=%s, price_raw=%s, amount_due=%s); it is kept in the register session.',
                $saleId,
                $quote['order_ref'] ?? '',
                $quote['price_raw'] ?? '',
                $quote['amount_due'] ?? ''
            ));
        }
        session()->remove('nano_plugin_verified');
    }

    public function onSaleUnsuspended(int $saleId, array $pluginData = []): void
    {
        self::quoteStore()->restore($saleId);

        // A Nano entry saved with the suspended sale was verified before it was suspended.
        $payments = (new Sale_lib())->getPayments();
        if (isset($payments[self::paymentTypeLabel()])) {
            session()->set('nano_plugin_verified', $payments[self::paymentTypeLabel()]);
        }
    }
}
