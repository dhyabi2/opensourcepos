<?php

declare(strict_types=1);

namespace App\Plugins\NanoPlugin\Tests;

use App\Plugins\NanoPlugin\Libraries\ClaimsFile;
use App\Plugins\NanoPlugin\Libraries\DurableFs;
use App\Plugins\NanoPlugin\Libraries\NanoPaymentProvider;
use App\Plugins\NanoPlugin\Libraries\PaymentGateway;
use App\Plugins\NanoPlugin\Libraries\QuoteStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the plugin-side pieces: the payment provider registered through
 * the payment provider API, the suspended-quote store, and the durable file
 * helpers. The Nano node is faked by an injected block reader; no database,
 * no network.
 */
final class NanoPluginPiecesTest extends TestCase
{
    private const BURN = 'nano_1111111111111111111111111111111111111111111111111111hifc8npp';
    private const PRICE_RAW = '50000000000000000000000000000';
    private const ORDER = 'order-0001';
    private const REP = 'nano_33q9dassq66es6mcay83n5kypqgpzjikr9bkc9ope9xch1borfphgehj4ozj';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nano-plugin-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function settings(): array
    {
        return [
            'nano_enabled'               => '1',
            'nano_account'               => self::BURN,
            'nano_node_url'              => 'http://127.0.0.1:7076',
            'nano_xno_price'             => '0.05',
            'nano_replay_window_seconds' => '86400',
            'nano_claims_path'           => $this->dir . '/ledger/claims.log',
        ];
    }

    private function confirmedSend(): array
    {
        return [
            'block_account'   => 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3',
            'amount'          => self::PRICE_RAW,
            'confirmed'       => 'true',
            'subtype'         => 'send',
            'local_timestamp' => '1763200000',
            'contents'        => ['link_as_account' => self::BURN, 'representative' => self::REP],
        ];
    }

    private function provider(?array $settings = null): NanoPaymentProvider
    {
        return new NanoPaymentProvider(
            $settings ?? $this->settings(),
            'Nano (XNO)',
            fn (array $s): PaymentGateway => new PaymentGateway($s, fn (string $h): array => $this->confirmedSend(), static fn (): int => 1763200100)
        );
    }

    /** @return array{0: QuoteStore, 1: \ArrayObject} */
    private function store(): array
    {
        $session = new \ArrayObject();
        $store = new QuoteStore(
            static fn () => $session['q'] ?? null,
            static function (?array $q) use ($session): void {
                if ($q === null) {
                    unset($session['q']);
                } else {
                    $session['q'] = $q;
                }
            },
            $this->dir . '/suspended'
        );

        return [$store, $session];
    }

    public function testProviderIsUnavailableUntilConfigured(): void
    {
        $this->assertTrue($this->provider()->isAvailable());
        $this->assertFalse($this->provider(['nano_enabled' => '0'] + $this->settings())->isAvailable());
        $this->assertFalse($this->provider(['nano_account' => ''] + $this->settings())->isAvailable());
        $this->assertSame(['Nano (XNO)' => 'Nano (XNO)'], $this->provider()->getPaymentTypes());
        $this->assertSame('nano', $this->provider()->getProviderId());
    }

    public function testProviderQuotesAndVerifiesThroughTheProviderApi(): void
    {
        $quote = $this->provider()->quote(self::ORDER, '1');
        $this->assertTrue($quote['success']);
        $this->assertSame(self::PRICE_RAW, $quote['request']['amount_raw']);
        $this->assertSame(self::REP, $quote['request']['representative']);

        $ok = $this->provider()->processCallback(['block_hash' => str_repeat('1', 64), 'order_ref' => self::ORDER, 'price_raw' => self::PRICE_RAW]);
        $this->assertTrue($ok['success']);

        // The ledger lives on disk: a new provider (new request) refuses the block for another sale.
        $replay = $this->provider()->processCallback(['block_hash' => str_repeat('1', 64), 'order_ref' => 'order-0002', 'price_raw' => self::PRICE_RAW]);
        $this->assertFalse($replay['success']);
    }

    public function testProviderRefusesTheWrongAmount(): void
    {
        $r = $this->provider()->processCallback(['block_hash' => str_repeat('1', 64), 'order_ref' => self::ORDER, 'price_raw' => '1']);
        $this->assertFalse($r['success']);
    }

    public function testSuspendedQuoteSurvivesRestoreAndIsKeptUntilSettled(): void
    {
        [$store] = $this->store();
        $store->set(['order_ref' => self::ORDER, 'price_raw' => self::PRICE_RAW, 'amount_due' => '1.00']);

        $this->assertTrue($store->suspend(42));
        $this->assertNull($store->get(), 'a saved quote leaves the register session');
        $this->assertFileExists($this->dir . '/suspended/42.json');

        $this->assertTrue($store->restore(42));
        $this->assertSame(self::ORDER, $store->get()['order_ref']);
        // Restoring does not delete the quote: the sale may be suspended again
        // or the page may fail before the block is entered.
        $this->assertFileExists($this->dir . '/suspended/42.json');
        $store->clear();
        $this->assertTrue($store->restore(42));

        $store->forgetSuspended($store->get());
        $this->assertFileDoesNotExist($this->dir . '/suspended/42.json');
    }

    public function testAFailedSuspendKeepsTheQuoteInTheSession(): void
    {
        // A regular file where the suspended directory should be: saving fails.
        mkdir($this->dir, 0o700, true);
        touch($this->dir . '/suspended');

        [$store] = $this->store();
        $store->set(['order_ref' => self::ORDER, 'price_raw' => self::PRICE_RAW, 'amount_due' => '1.00']);

        $this->assertFalse(@$store->suspend(7));
        $this->assertSame(self::ORDER, $store->get()['order_ref']);
    }

    public function testADamagedSuspendedQuoteIsNotRestoredNorDeleted(): void
    {
        mkdir($this->dir . '/suspended', 0o700, true);
        file_put_contents($this->dir . '/suspended/9.json', '{not json');

        [$store] = $this->store();
        $this->assertFalse($store->restore(9));
        $this->assertNull($store->get());
        $this->assertFileExists($this->dir . '/suspended/9.json');
    }

    public function testLedgerDirectoryIsCreatedAndTheFirstClaimIsDurable(): void
    {
        $claims = new ClaimsFile($this->dir . '/a/b/claims.log');
        $this->assertTrue($claims->claim(str_repeat('A', 64), self::ORDER));
        $this->assertDirectoryExists($this->dir . '/a/b');
        $this->assertTrue((new ClaimsFile($this->dir . '/a/b/claims.log'))->isClaimed(str_repeat('a', 64)));
    }

    public function testAnInterruptedTailIsRepairedButCompleteClaimsAreKept(): void
    {
        mkdir($this->dir, 0o700, true);
        $path = $this->dir . '/claims.log';
        file_put_contents($path, str_repeat('B', 64) . "\torder-b\n" . str_repeat('C', 32));

        $claims = new ClaimsFile($path);
        $this->assertTrue($claims->claim(str_repeat('D', 64), 'order-d'));
        $this->assertSame(
            str_repeat('B', 64) . "\torder-b\n" . str_repeat('D', 64) . "\torder-d\n",
            file_get_contents($path)
        );
    }

    public function testDurableWriteReplacesAtomically(): void
    {
        DurableFs::writeFile($this->dir . '/x/q.json', 'one');
        DurableFs::writeFile($this->dir . '/x/q.json', 'two');
        $this->assertSame('two', file_get_contents($this->dir . '/x/q.json'));
        $this->assertSame(['q.json'], array_values(array_diff(scandir($this->dir . '/x'), ['.', '..'])));
    }
}
