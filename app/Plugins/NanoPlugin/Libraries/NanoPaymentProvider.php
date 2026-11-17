<?php

declare(strict_types=1);

namespace App\Plugins\NanoPlugin\Libraries;

use App\Libraries\Payments\PaymentProviderBase;
use Throwable;

/**
 * The Nano (XNO) payment provider, registered with the payment provider
 * registry by NanoPlugin. It has no webhook and holds no key: a quote gives
 * the exact raw amount and the representative that binds a send block to one
 * sale, and a verification reads that block from the operator's node and
 * records it in the durable claims ledger. Its settings come from the plugin's
 * own configuration (not from ospos_app_config).
 */
class NanoPaymentProvider extends PaymentProviderBase
{
    public const PROVIDER_ID = 'nano';

    /** @var callable(array): PaymentGateway */
    private $gatewayFactory;

    /**
     * @param array<string, mixed>                $settings       the plugin settings (nano_* keys)
     * @param (callable(array): PaymentGateway)?  $gatewayFactory injected in tests to fake the node
     */
    public function __construct(array $settings, private readonly string $paymentTypeLabel, ?callable $gatewayFactory = null)
    {
        parent::__construct($settings);
        $this->gatewayFactory = $gatewayFactory ?? static fn (array $s): PaymentGateway => new PaymentGateway($s);
    }

    public function getProviderId(): string
    {
        return self::PROVIDER_ID;
    }

    public function getProviderName(): string
    {
        return 'Nano (XNO)';
    }

    public function getProviderDescription(): string
    {
        return 'Feeless Nano payments verified against an operator-chosen node.';
    }

    public function getPaymentTypes(): array
    {
        return [$this->paymentTypeLabel => $this->paymentTypeLabel];
    }

    public function isAvailable(): bool
    {
        return PaymentGateway::isEnabled($this->config);
    }

    public function getSettings(): array
    {
        return $this->config;
    }

    public function saveSettings(array $settings): bool
    {
        // Settings are owned and saved by NanoPlugin (plugin config table).
        return false;
    }

    /**
     * @param array{order_ref: string} $options
     */
    public function initiatePayment(float $amount, string $currency, array $options = []): array
    {
        return $this->quote((string) ($options['order_ref'] ?? ''), (string) $amount);
    }

    /**
     * Quote using the exact decimal string of the amount due (never a float).
     *
     * @return array{success: bool, request?: array, error?: string}
     */
    public function quote(string $orderRef, string $amountDue): array
    {
        try {
            return ['success' => true, 'request' => $this->gateway()->quote($orderRef, $amountDue)];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify a block against a stored quote.
     *
     * @param array{block_hash: string, order_ref: string, price_raw: string} $data
     * @return array{success: bool, error?: string}
     */
    public function processCallback(array $data): array
    {
        try {
            $result = $this->gateway()->verify(
                (string) ($data['block_hash'] ?? ''),
                (string) ($data['order_ref'] ?? ''),
                (string) ($data['price_raw'] ?? '')
            );
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return $result['ok'] ? ['success' => true] : ['success' => false, 'error' => (string) $result['error']];
    }

    public function getPaymentStatus(string $transactionId): array
    {
        return ['success' => false, 'error' => 'Nano payments are verified once, at the register.'];
    }

    public function refund(string $transactionId, float $amount, string $reason = ''): array
    {
        return ['success' => false, 'error' => 'A Nano refund is a new send from the merchant wallet; it is not made here.'];
    }

    public function cancel(string $transactionId): array
    {
        return ['success' => false, 'error' => 'A confirmed Nano send cannot be cancelled.'];
    }

    private function gateway(): PaymentGateway
    {
        return ($this->gatewayFactory)($this->config);
    }
}
