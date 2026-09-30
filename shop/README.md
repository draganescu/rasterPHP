# Blue Hour Ceramics: an example shop

A small online shop for one maker, built on Raster's records. It's an example, not part of the framework: copy it, change it, and the code is yours. Nothing in `system/` knows it exists.

```sh
php shop/seed.php                        # four pieces on the shelf, and the studio's account
RASTER_APP=shop php bin/raster serve     # http://localhost:8000
php tests/shop.php                       # buyers, the studio and the payment provider, over HTTP
```

Log in at `/login` as `studio@bluehour.test` with the password `studio password`, and change the password straight away.

## How it's put together

| Part | What it is |
|---|---|
| `models/product` | The pieces for sale: a **public** record type. The studio edits them in place on any page that lists them. `check()` never lets stock go below zero. |
| `models/cart` | The cart lives in the visitor's session. It isn't anybody's record until it becomes an order. |
| `models/order` | Orders: a **private** record type with an **owner**. `check()` decides which status may follow which, and that a shipped order has a tracking number. The actions are **Ship**, **Mark paid** (for orders paid on delivery), **Cancel** (puts the pieces back on the shelf) and **Refund** (admins only). |
| `models/order/payments.php` | The payment provider: Stripe Checkout, or a pretend provider for trying the shop. |
| `views/kiln/orders.html` | The studio's page: orders to ship, orders waiting for payment, orders shipped, and the shelf. Press E to edit. |
| `views/kiln/account.html` | The logged in person's own orders (`owner=me`), studio accounts included. |

### Checkout, and the last piece

`order::checkout()` turns the cart into an order inside `cms_records::transaction()`. For each piece it lowers the stock through `cms_records::update()`, so `product::check()` sees every change. When two people want the last vase, the first checkout takes it. The second one's stock would go to −1, so `check()` refuses with `sold_out` and the whole transaction rolls back: no order, and no stock change. The buyer sees the `sold_out` alert. The order keeps its own copy of what was sold (name, price, quantity), so changing a product later never changes an old order.

### Payments

Out of the box the shop takes no money online: buyers **pay the courier on delivery**. Checkout places the order unpaid, emails the buyer what to have ready, and tells the studio. The studio ships it unpaid, and presses **Mark paid** when the courier brings the money. Nothing pretends an order is paid before it is.

To also take cards, set `shop_payments` in `config/the_app.php`, or use environment variables. Checkout then offers a choice between the two.

- **test** (development only): checkout sends the buyer to `/pay/test`, a pretend provider page. Pressing Pay signs the same `checkout.session.completed` event Stripe would send and hands it to the code the webhook runs. Anywhere other than development it's off, and so is the example's public webhook secret. Checkout then refuses before touching the shelf.
- **stripe**: set `SHOP_PAYMENTS=stripe`, `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET`. Checkout creates a Stripe Checkout Session for cards and sends the buyer there. In Stripe's dashboard, add a webhook for `checkout.session.completed` pointing at `https://<your site>/api/order/webhook`.

The webhook is an ordinary public model method reached through `/api`, the only one `order::api()` offers. A server posting has no browser headers and no session, so Raster's cross-site check lets it through. The method does these checks itself:

- It verifies the provider's signature. This is Stripe's scheme: an HMAC of the timestamp and the body, with five minutes of leeway.
- It only accepts the checkout session this shop opened for the order.
- The amount and currency must be the order's total.
- The payment status must be `paid`.

Anything else is answered 200 and logged, since providers resend what they think didn't arrive. Marking an order paid reads the order again inside a transaction. So a repeated event, or two arriving together, changes it once, and the receipt and the studio's email go out after the commit.

If Stripe can't be reached at checkout, the order it started is cancelled and its pieces go back on the shelf. The buyer keeps their cart and sees an alert.

### What each action guards against

- **Ship** only moves a paid order, or an order paid on delivery, once, and needs a tracking number.
- **Mark paid** records the courier's money for an order paid on delivery, once it has shipped.
- **Cancel** only moves an unpaid order. It first closes the Stripe session so nobody can pay any more, then puts the pieces back by product id, so a piece renamed or taken off the shop still gets its stock back. If a buyer manages to pay a cancelled order anyway, the webhook refunds them straight away and emails the studio.
- **Refund** (admins only) asks Stripe first, outside any transaction, with an idempotency key. Pressing it again after a timeout therefore never refunds twice. Pieces of an order that never shipped go back on the shelf.

Card orders nobody pays hold their pieces for an hour (`order::HOLD_MINUTES`, which is also the Stripe session's expiry). The next checkout after that cancels them and frees the pieces. Orders paid on delivery wait for the studio.

### Before a real shop opens

This example leaves out things a real shop needs to decide for itself. Taxes: for sales to consumers across the EU, a provider that handles VAT (Stripe Tax, or a merchant of record like Paddle or Lemon Squeezy) keeps the order model simple. Also shipping costs, invoices your country requires, and terms and returns pages. Checkout has no rate limit, so a stranger could hold pieces with unpaid orders for up to an hour at a time. Payment methods that settle days later (SEPA debits, bank transfers) would need `checkout.session.async_payment_succeeded`; this example takes cards only.
