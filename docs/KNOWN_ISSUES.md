# Known Issues & Fixes

A running log of production bugs — what broke, why, and how it was fixed.

---

## Issue #1 — Add to cart silently fails; cart always appears empty

**Severity:** Critical  
**Reported:** 2026-09-01  
**Status:** Fixed (deploy VCL rebuild)

### Symptom

- Customers cannot add products to cart.
- The `POST /checkout/cart/add/…` request returns **HTTP 200** (or 302 with "Your session has expired").
- `customer/section/load` returns an empty cart immediately after.
- Affects all new visitors; existing visitors with cached browser cookies may partially work but lose the cart after any session renewal.

### Root cause

The Varnish VCL in `deploy/varnish/default.vcl` stripped `Set-Cookie` from **every** response whose `Cache-Control` header did not contain the word `"private"`:

```vcl
# BUGGY — strips cookies from all Magento dynamic pages
if (beresp.http.Cache-Control !~ "private" && ...) {
    unset beresp.http.Set-Cookie;
}
```

Magento's dynamic pages (product pages, `customer/section/load`, add-to-cart responses) all use:

```
Cache-Control: max-age=0, must-revalidate, no-cache, no-store
```

This string does **not** contain `"private"`, so the condition matched and Varnish stripped `PHPSESSID`, `form_key`, `X-Magento-Vary`, `private_content_version`, and `mage-messages` from every response.

**Consequence chain:**

1. Customer visits a product page → Varnish strips `Set-Cookie: PHPSESSID=…` → browser never receives a session cookie.
2. JS calls `customer/section/load` → Varnish strips the new `PHPSESSID` again.
3. Customer posts add-to-cart → PHP creates a new session and writes the cart to it → Varnish strips the new `PHPSESSID` from the response → browser keeps its old (empty) session.
4. `customer/section/load` reads the old session → returns empty cart.

For users whose session was **renewed** by Magento during the add-to-cart flow (session fixation protection), the cart was written to the new session ID but the browser was left holding the old one — causing the "cart silently empties" symptom even for returning visitors.

### Fix

`deploy/varnish/default.vcl` — add guards for `no-store`, `no-cache`, and `beresp.uncacheable` so that `Set-Cookie` is only stripped from genuinely cacheable static responses:

```vcl
# Before (buggy)
if (beresp.http.Cache-Control !~ "private" &&
    bereq.url !~ "^/d2_Dmin" &&
    bereq.url !~ "^/rest/" &&
    bereq.url !~ "^/graphql") {
    unset beresp.http.Set-Cookie;
}

# After (fixed)
if (!beresp.uncacheable &&
    beresp.http.Cache-Control !~ "no-store" &&
    beresp.http.Cache-Control !~ "no-cache" &&
    beresp.http.Cache-Control !~ "private" &&
    bereq.url !~ "^/d2_Dmin" &&
    bereq.url !~ "^/rest/" &&
    bereq.url !~ "^/graphql") {
    unset beresp.http.Set-Cookie;
}
```

**Why this is safe:**

- Magento dynamic pages always include `no-store` → condition is false → cookies pass through ✓
- Static assets (`/static/`, `/media/`) have `Cache-Control: public, max-age=…` → condition is true → `Set-Cookie` stripped (they never set one anyway) ✓
- PASS requests (POST, admin, REST, GraphQL) → `beresp.uncacheable` is `true` → condition is false → cookies always pass through ✓

### Deploy steps

This is a VCL-only change — no full code deploy needed (see `docs/DEPLOY.md §4`):

```bash
cd /srv/legal/repo && git pull

COMPOSE="docker compose -f /srv/legal/repo/deploy/docker-compose.prod.yml --env-file /srv/legal/.env.prod"

$COMPOSE build varnish
$COMPOSE up -d --no-deps --force-recreate varnish
```

---

## Issue #2 — system.log flooded with Braintree configuration errors

**Severity:** Minor (noise; no customer-facing breakage if Braintree is not in use)  
**Reported:** 2026-09-01  
**Status:** Open

### Symptom

`var/log/system.log` fills with repeated entries every few seconds:

```
main.ERROR: Braintree\Configuration::merchantId needs to be set
(or accessToken needs to be passed to Braintree\Gateway). [] []
```

### Root cause

The Braintree payment method is installed (it ships with Magento) but has not been configured with a Merchant ID. On every checkout page load, Magento initialises all active payment adapters and Braintree throws when it finds empty credentials.

### Fix options

**Option A — Configure Braintree** (if it will be used):  
Admin → Stores → Configuration → Sales → Payment Methods → Braintree → enter Merchant ID, Public Key, and Private Key.

**Option B — Disable Braintree** (if it will not be used):  
Admin → Stores → Configuration → Sales → Payment Methods → Braintree → set *Enable* to **No**.

Either option eliminates the log flood.

---

## Issue #3 — GraphQL error: "Page id/identifier should be specified"

**Severity:** Minor (affects GraphQL clients only; storefront is unaffected)  
**Reported:** 2026-09-01  
**Status:** Open

### Symptom

`var/log/exception.log` contains:

```
main.ERROR: "Page id/identifier should be specified
GraphQL (6:1)
… a_cmsPage: cmsPage{ content … }
```

### Root cause

A GraphQL query batch sent to the store includes a `cmsPage {}` field without providing the required `id` or `identifier` argument. The resolver throws a `GraphQlInputException`.

This is typically caused by a third-party headless client, PWA Studio frontend, or monitoring script that sends a generic introspection/data-fetch query without the mandatory identifier.

### Fix

Identify the client sending the malformed query (check the IP in exception.log or the nginx access log around the same timestamp) and add the required argument:

```graphql
# Wrong
cmsPage { content identifier }

# Correct
cmsPage(identifier: "home") { content identifier }
```

If the query comes from a third-party package, update or patch it, or open a bug report with the vendor.
