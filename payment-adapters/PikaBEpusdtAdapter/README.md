# Pika BEpusdt Adapter

`PikaBEpusdtAdapter` is an MIT-licensed, clean-room implementation of the
public BEpusdt `create-transaction` and callback signing protocol for the
official Acg-Faka 3.6.4 and 3.7.0 payment interfaces, with isolated install and
callback validation on fixed 3.7.5. It does not vendor the GPL BEpusdt
PHP SDK and does not modify Acg-Faka business-core files.

The installer copies `Config/`, `Impl/`, and `Support/` into:

```text
app/Pay/PikaBEpusdtAdapter
```

The Acg-Faka payment profile stores only:

- `gateway_origin`: an HTTP loopback origin such as `http://127.0.0.1:8080`;
- `checkout_origin`: the public canonical HTTPS origin used for the buyer
  checkout; V0.1 requires it to equal `merchant_origin`;
- `merchant_origin`: the canonical HTTPS origin of the Acg-Faka site; callback
  and return URLs must match it exactly;
- `fiat`: `CNY`, `USD`, `EUR`, `JPY`, or `GBP`.

Before enabling an adapter payment row, Acg-Faka's native
`callback_domain` setting must be explicitly set to the same canonical HTTPS
origin as `merchant_origin` and `checkout_origin`. This is especially
important after cloning a database, restoring a backup, or changing domains:
a non-empty old `callback_domain` does not follow the current request host.
The installed doctor reads the native `Config`, `Pay`, and `PayProfile` APIs
and rejects any active mismatch without reading or printing the external API
token.

The API token and the per-site namespace are never stored in the Acg-Faka
database. For canonical site root `<site-root>`, calculate:

```text
site_hash = sha256(realpath(<site-root>))
```

Then provision these files without trailing newlines:

```text
/var/lib/pika-local-extensions/sites/<site_hash>/secrets/bepusdt-token
/var/lib/pika-local-extensions/sites/<site_hash>/secrets/bepusdt-namespace
```

The state root, `sites`, and site-hash directories are `root:root 0755`. The
`secrets` directory is `root:<webgid> 0750`; both secret files are regular,
single-link `root:<webgid> 0640` files. The API token is 16-256 printable
non-space ASCII characters. The namespace is 4-12 lowercase ASCII letters or
digits.

The adapter prefixes every upstream `order_id` with that namespace. A signed
callback is verified before the namespace is checked and stripped, after which
the original Acg-Faka order ID is restored through the official callback
context. Only a signed `status=2` callback reaches that context; waiting
(`status=1`) and expired (`status=3`) notifications are rejected. A successful
paid callback uses the adapter's literal `ok` response contract.

Supported Acg-Faka payment codes are `usdt.bep20`, `tron.trx`, and
`usdt.trc20`. The adapter recognizes the legacy 18-character
`/pay/checkout/` response and the UUID `/pay/checkout-counter/` response. It
always rebuilds the browser URL from the configured trusted same-origin
checkout origin.

## Dedicated callbacks in the 0.1.2 candidate

New real transactions use `POST /user/api/pikaBEpusdt/order.<tradeNo>` or
`POST /user/api/pikaBEpusdt/recharge.<tradeNo>` on the configured merchant
origin. The existing native callback URL must first pass the original origin
and flow checks; the newly generated URL is included in the signed create
request. Explicit `TEST_` callbacks retain the native test route. Already
created BE transactions keep their stored notification URL and are not migrated.

The separately managed `app/Controller/User/Api/PikaBEpusdt.php` controller
starts with HTTP 500 before WAF/injection, retains the native WAF and
`CallbackIpWhitelist`, and accepts only JSON POST with the exact 18-digit
route and one route parameter. It resolves the stored order/recharge and its
Pika payment channel, then reuses native `callbackInitialize` for this request's
signature, paid-notification status and namespace verification. The local route
ID and amount must match; `gateway_amount` is used unless it is null.

An unpaid record calls its native business callback exactly once. Only its
exact `ok` result with no active framework or PDO transaction receives
`HTTP 200` and `ok`. A replay after commit is acknowledged only after fresh
authentication, refreshed payment binding and amount checks, with local
`status == 1` and a nonempty `pay_time`; it does not invoke fulfillment or
crediting again. Other states and failures return non-200. No exception text,
old payment context, or status flag alone is accepted as proof. Native
verification failures can still invoke the official failure log/hook; the
promise is no new business settlement, not absolutely zero side effects.

The endpoint owns its final response and exits after native service hooks;
global controller-after/HTTP-response hooks do not rewrite the ACK. It adds no
outer transaction, ledger, queue or database table. Both transaction-level and
PDO checks reject an outer transaction instead of mistaking a savepoint for a
durable commit.

## Verified scope and remaining limits

The unreleased 0.1.2 candidate completed the agreed isolated matrix on
Acg-Faka 3.7.5 (`3430d0a881c4dccbdee1513473a402bcb1b1773d`) and BEpusdt
1.24.2 (`4d88040fd4096e77e8fb9ad2650e775753a977b6`): eight changed PHP files
linted, 66 synthetic calls, the full install matrix, native installation and
restore/reinstall, two public HTTP pages, and 30 callbacks across both flows.
All business data was synthetic. Wrong amounts produced merchant HTTP 500
and BE notify-state 0; legitimate completion produced HTTP 200 and state 1.
Duplicate notifications and actual BE resends after a client sent a request
without reading its response did not fulfill or credit twice. Independent SQL
confirmed the first commit before each resend. Paid-record wrong amounts and
signed order-ID/route mismatches were rejected without business changes.

The merchant entity body on the real BE path remains `BODY_NOT_CAPTURED`:
wire-byte counts do not prove `ok`. Direct HTTP negative cases separately
returned `500/fail`. This evidence does not establish BE first-send ACK loss,
natural retries, concurrency, the complete create/paid/cancel/checkout
lifecycle, real wallets/funds/RPC/chain settlement, or production/customer E2E.
This batch's new-endpoint dynamic regression on 3.6.4/3.7.0 is also `NOT RUN`.
This snapshot is an unreleased public candidate. Later bounded non-transaction
checks on fixed Acg-Faka 3.7.9 do not replace this 3.7.5 synthetic callback
evidence or establish post-upgrade real payments. This candidate has not been
published or deployed; see [project status](../../docs/PROJECT_STATUS.md).

- Failures before controller construction, already-sent headers and proxy/FPM
  response rewriting are outside this controller's guarantee.
- Binding is refreshed but not locked across the native service reread. Do not
  rebind payment channels/profiles while callbacks are in flight. Concurrent
  native callbacks can return a transient non-200; a subsequent independently
  verified paid replay may ACK. Concurrency is not yet verified.
- Old notification URLs and `TEST_` are outside the new HTTP-status contract.
  Restoring/removing the new controller with in-flight transactions would break
  their callbacks; settle or expire them before rollback, as in the existing
  install/restore procedure. Code rollback does not undo paid business data.
- Local synthetic tests replace the official service, models, DB, DI and
  Decimal, so they cannot establish native commit, HTTP, BE notify-state or
  real lost-response behavior by themselves. The separate native-stack evidence
  above establishes only the explicitly tested isolated scenarios.
