# Nano (XNO) payments plugin

Lets the sales register take **Nano (XNO)**, settling the exact amount of a
sale directly to an account the operator owns. It is a self-contained plugin
(`app/Plugins/NanoPlugin/`) built on the plugin system and the payment
provider API: it registers a `nano` provider with `PaymentProviderRegistry`
and renders its panel through the register's payment view hook. Nothing
changes until the plugin is enabled **and** configured.

There is **no key and no seed** anywhere in OSPOS: the account is yours, the
payer holds their own wallet, and the plugin only *reads* public ledger data
from a node you name.

## Settings (Plugins > Nano (XNO) > Configure)

| Setting | Meaning |
| --- | --- |
| `nano_account` | your receiving account, a checksum-valid `nano_...` address. |
| `nano_node_url` | an `http(s)` Nano RPC node URL you control or trust (read only). |
| `nano_xno_price` | XNO per one unit of your sale currency (e.g. `3.2` means 1.00 = 3.2 XNO). |
| `nano_replay_window_seconds` | how recent a send block must be to be accepted (default `86400`). |
| `nano_claims_path` | the claims ledger file (default `writable/nano/claims.log`). |

## How a sale is paid

1. In the register payment area the cashier clicks **Nano (XNO)**. The panel
   calls `GET plugins/nano/paymentRequest`, which returns the exact amount to
   send, in XNO and in **raw** (1 XNO = 10^30 raw), derived from the amount due
   and your price with no floating-point rounding, and the **representative
   account** the payer must set on the send block. The representative is a
   commitment to this sale
   (`SHA256("xno-binding/v1/rep|" + orderRef + "|" + account + "|" + priceRaw)`
   as a `nano_` account, option C of the xno-binding v1 standard). The quote
   is stored in the register session on the server.
2. The customer sends, and the cashier enters the **block hash** and clicks
   **Verify and add** (`POST plugins/nano/addPayment`).
3. The plugin reads the block from your node (`block_info`) and credits the
   sale only when it is a **confirmed send** of the **exact raw amount**, to
   **your account**, carrying **this sale's commitment**, recent enough, and not
   already in the durable, fsynced **claims ledger**. One block credits one
   sale once, across restarts.

The amount credited is the sale-currency amount stored with the quote, never
an amount typed in the browser. If the sale changes after the quote, the
payment is refused until the request is shown again. A Nano entry posted
through the generic payment form is reverted to what the plugin verified.

## Suspend and unsuspend

The plugin listens to the core `sale_suspended` / `sale_unsuspended` events.
An outstanding quote is written durably (temp file, fsync, rename, directory
fsync) to `writable/nano/suspended/<sale_id>.json`; if that write fails the
quote stays in the register session and the failure is logged with the order
reference. On unsuspend the quote is restored and its file is kept until the
payment is verified or the sale completes.

## Code

- `NanoPlugin.php`: the `BasePlugin`; registers the provider, view hooks and events.
- `Libraries/NanoPaymentProvider.php`: the `PaymentProviderBase` provider.
- `Libraries/PaymentGateway.php`: quote and verify; node read and clock injectable.
- `Libraries/Blake2b.php`, `Address.php`, `Amount.php`, `Binding.php`, `Verifier.php`: dependency-free Nano pieces.
- `Libraries/ClaimsFile.php`: the restart-proof, single-lock claims ledger.
- `Libraries/QuoteStore.php`, `DurableFs.php`: session/suspended quote and durable writes.
- `Controllers/NanoController.php`, `Config/Routes.php`, `Views/`, `Language/en/`.
- `Tests/`: unit tests; the node is faked by an injected block reader.
