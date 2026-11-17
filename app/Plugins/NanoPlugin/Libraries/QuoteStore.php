<?php

declare(strict_types=1);

namespace App\Plugins\NanoPlugin\Libraries;

/**
 * Where the Nano quote of the in-progress sale lives: the random per-sale
 * order reference, the exact raw amount the payer was shown, and the
 * sale-currency amount it pays. The quote is kept in the register session and,
 * while a sale is suspended, in a durable file keyed by the suspended sale id,
 * because the payer may already have sent a block that commits to it.
 *
 * Session access goes through two callables so this class unit-tests without
 * CodeIgniter.
 */
final class QuoteStore
{
    public const SESSION_KEY = 'nano_plugin_quote';

    /** @var callable(): mixed */
    private $read;

    /** @var callable(?array): void */
    private $write;

    /**
     * @param callable(): mixed      $read  returns the stored session value
     * @param callable(?array): void $write stores (array) or removes (null) it
     */
    public function __construct(callable $read, callable $write, private readonly string $suspendedDir)
    {
        $this->read = $read;
        $this->write = $write;
    }

    /**
     * @return array{order_ref: string, price_raw: string, amount_due: string, suspended_sale_id?: int}|null
     */
    public function get(): ?array
    {
        $quote = ($this->read)();

        return self::isValid($quote) ? $quote : null;
    }

    public function set(array $quote): void
    {
        ($this->write)($quote);
    }

    public function clear(): void
    {
        ($this->write)(null);
    }

    /**
     * Save the outstanding quote with a suspended sale. On success the quote
     * leaves the session (the next sale on this register starts clean); on
     * failure it is KEPT in the session so it is not lost, and the caller
     * logs the failure with the order reference so it can be recovered.
     *
     * @return bool false only when a quote was outstanding and could not be saved
     */
    public function suspend(int $saleId): bool
    {
        $quote = $this->get();
        if ($quote === null || $saleId <= 0) {
            return true;
        }

        $json = json_encode([
            'order_ref'  => $quote['order_ref'],
            'price_raw'  => $quote['price_raw'],
            'amount_due' => $quote['amount_due'],
        ]);

        try {
            if ($json === false) {
                return false;
            }
            DurableFs::writeFile($this->path($saleId), $json);
        } catch (NanoException) {
            return false;
        }

        $this->clear();

        return true;
    }

    /**
     * Bring back the quote saved with a suspended sale. The file is NOT deleted
     * here: the sale can be suspended again, or the browser can fail before the
     * payment is entered, and the block the payer sent must still verify. It is
     * removed by forgetSuspended() once the payment is verified or the sale
     * completes. A damaged file is left in place for the operator.
     *
     * @return bool true when a quote was restored
     */
    public function restore(int $saleId): bool
    {
        $path = $this->path($saleId);
        if ($saleId <= 0 || !is_file($path)) {
            return false;
        }

        $quote = json_decode((string) file_get_contents($path), true);
        if (!self::isValid($quote)) {
            return false;
        }

        $this->set([
            'order_ref'         => (string) $quote['order_ref'],
            'price_raw'         => (string) $quote['price_raw'],
            'amount_due'        => (string) $quote['amount_due'],
            'suspended_sale_id' => $saleId,
        ]);

        return true;
    }

    /**
     * Drop the suspended-quote file once the quote is settled.
     */
    public function forgetSuspended(?array $quote): void
    {
        $saleId = (int) ($quote['suspended_sale_id'] ?? 0);
        if ($saleId > 0 && is_file($this->path($saleId))) {
            unlink($this->path($saleId));
        }
    }

    private function path(int $saleId): string
    {
        return rtrim($this->suspendedDir, '/\\') . DIRECTORY_SEPARATOR . $saleId . '.json';
    }

    private static function isValid(mixed $quote): bool
    {
        return is_array($quote)
            && isset($quote['order_ref'], $quote['price_raw'], $quote['amount_due'])
            && is_string($quote['order_ref']) && $quote['order_ref'] !== '';
    }
}
