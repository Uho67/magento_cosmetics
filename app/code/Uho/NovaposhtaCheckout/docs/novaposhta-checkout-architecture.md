    # Nova Poshta-Only Checkout — Architecture Plan

**Status:** All phases (0–8) complete. Phase 7's full manual test matrix (§10.1), including the
human browser session against `legal.test` (base, `en_US`) and the `pr_ua` store (`uk_UA`), passed
with no issues (see §10.3). Security review, `di:compile`, `static-content:deploy -f`, and the
48/48 unit test suite are all complete and re-verified. This document is finalised as the as-built
record of the Nova Poshta-only checkout implementation.
**Date:** 2026-09-01
**Platform:** Magento 2.4.9 CE, PHP 8.4, MariaDB 11.4 (Warden local / Docker Compose prod)

Goal: Nova Poshta is the only shipping method, auto-selected with no radio button, and the
customer fills in only First Name, Last Name, Phone, City, Warehouse. All other Magento
address fields are populated server-side so core validation stays untouched.

**No component of this flow ever calls `api.novaposhta.ua` or `novapost.com`.** All reference
data is read from the locally cron-synced `Perspective_NovaposhtaCatalog` tables.

---

## 0. Prerequisite blocker

`.warden/warden-env.yml` is corrupted — byte 0 is a stray literal `45015` glued to the leading
`#` comment:

```
0000000    4   5   0   1   5   #       .   w   a   r   d   e   n   /   w
```

Every `warden env|db|magento|composer` command fails with `top-level object must be a mapping`.
Containers currently run because they were started before the corruption. **Delete those five
characters before Phase 1** — no `setup:upgrade` or `di:compile` can run otherwise.

---

## 1. Verified environment facts

| Item | Verified value |
|---|---|
| Magento | `2.4.9` (`composer.json:45`) — **not 2.4.8** as originally assumed |
| Websites | `base` (store 1, `en_US`) **and** `pr` / Проросток (website 3, store 2 `pr_ua`, `uk_UA`) |
| Orders / quotes | 2 / 4 — effectively greenfield |
| `Perspective_NovaposhtaCatalog` | v2.4.10.1, installed + enabled, syncing |
| `Perspective_NovaposhtaShipping` | v2.4.10.3, **installed + enabled** → to be disabled (§3.1) |
| Existing `Uho` NP carrier | **None.** `app/code/Uho/` contains only `Uho/Store` |

`novaposhta_catalog/catalog/active = 1`; last syncs 2026-09-01. **`novaposhta_catalog/schedule/enabled = 0`
— the sync cron is OFF**; recent syncs were manual. Must be enabled before go-live.

### 1.1 Shipping carriers currently active

| Carrier | DB `active` | config.xml default | Effective |
|---|---|---|---|
| `flatrate` | *absent* | `1` | **ENABLED** |
| `tablerate` | `1` | `0` | **ENABLED** |
| `novaposhtashipping` | `1` | — | **ENABLED** (API key is the placeholder `Your-key-here`) |
| `freeshipping`, dhl/fedex/ups/usps | absent | `0` | disabled |

Note `flatrate` is enabled by the *absence* of a DB row. Disabling it requires writing an
explicit `0`, not deleting the row.

### 1.2 Why `Perspective_NovaposhtaShipping` must be disabled

- It calls the live API: `Model/Carrier/AreaAndRegion::getAreaAndRegionData()` →
  `request('Address','searchSettlements',…)` — violates the no-API guardrail.
- It contends for the exact extension points this module needs:
  - `view/frontend/layout/checkout_index_index.xml` injects `city_novaposhta_field` and siblings
    into `shippingAddress.shipping-address-fieldset`, and overrides `shipping-rates-validation`.
  - `etc/frontend/di.xml` registers its own `LayoutProcessor` into `Magento\Checkout\Block\Onepage`.
  - `etc/extension_attributes.xml` already claims `perspective_novaposhta_shipping_city` /
    `_warehouse` / `_street` / `_building` / `_flat` / `_method` on the quote `AddressInterface`.
- Its tables are **empty** (`…_sales_order_address` = 0 rows, `…_client_address` = 0 rows), so
  disabling loses nothing.

**Decision: `bin/magento module:disable Perspective_NovaposhtaShipping`.** The composer package
stays installed (no `composer.lock` churn); it is simply disabled in `app/etc/config.php`.
Its empty legacy tables may remain in the local DB — harmless, no cleanup required.

> The current working tree contains an *uncommitted* change to `app/etc/config.php` that **adds**
> `'Perspective_NovaposhtaShipping' => 1`. This must be reverted / superseded by the disable.

---

## 2. Reference data schema (verified, not assumed)

### 2.1 `perspective_novaposhta_catalog_cities` — 11,171 rows

Relevant columns: `id` (PK), `ref varchar(255)` **indexed**, `descriptionua`/`descriptionru`
longtext, `area` longtext, `city_id`, `settlement_type_description_ua`.

- `id`, `ref`, `city_id` are each **100% unique** (11,171/11,171).
- `descriptionua`: 0 empty, max **50 chars**.
- **No postcode column. No human-readable region** — `area` is a bare GUID with no shipped
  lookup table.
- 3 city names are duplicated across rows, differing only by a parenthetical qualifier.

> **`ref` is the only safe key.** `descriptionua LIKE 'Київ%'` also matches `Київець`, a
> genuinely different city. Never match cities by name.

### 2.2 `perspective_novaposhta_catalog_warehouse` — 54,311 rows

Relevant columns: `id` (PK), `site_key` longtext, `description_ua`/`_ru`, `short_address_ua`,
`phone`, `ref` longtext, `number_in_city`, **`city_ref varchar(255) NOT NULL` with
`KEY …_CITY_REF`**, `city_description_ua`, `settlement_area_description`,
`settlement_region_description`, `settlement_type_description`, `warehouse_status`,
`category_of_warehouse`, plus geo/service flags.

**Filtering warehouses by city is a plain indexed equality lookup** on `city_ref` → `cities.ref`.
No string matching required.

Sharp edges:

1. **`ref` is `longtext` and unindexed.** The vendor's `getWarehouseByWarehouseRef()` therefore
   full-scans 54,311 rows. Never put it on a hot path — key off `id` (PK).
2. **Warehouse counts are extreme**: Київ **7,904**, Львів 2,962, Дніпро 2,721, Одеса 1,809,
   Харків 1,775. Server-side search + limit is mandatory; a full `<select>` for Kyiv is ~1 MB.
3. **Use `settlement_area_description` for region** (oblast, 100% populated).
   `settlement_region_description` is the *raion* and is NULL for cities of oblast significance
   including Kyiv.

Data quality: `description_ua` max 99 chars / 0 empty; `short_address_ua` max 70 / 0 empty;
`number_in_city` max 6 / 0 empty; `warehouse_status` = `Working` on all 54,311 rows.

Categories: Postomat 39,375 · Store 9,089 · Branch 3,606 · DropOff 2,231 · Fulfillment 10.

### 2.3 `site_key` — why it is NOT the postcode

`site_key` is 100% unique and fully numeric, but its length varies:

| digits | rows |
|---|---|
| 1–4 | 723 |
| **5** | **47,253** |
| **6** | **6,335** |

Max value `108587`. Magento validates UA postcodes as `^[0-9]{5}$`
(`vendor/magento/module-directory/etc/zip_codes.xml:779`), so **6,335 warehouses (11.7%) cannot
be expressed as a postcode** — and **5,630 of those are Postomats**, a category this store
explicitly includes.

**Decision:** `site_key` is stored verbatim in its own column (`uho_np_warehouse_site_key`),
preserving exact warehouse identity and round-trip lookup, while `postcode` uses the sentinel
in §3.3.

---

## 3. Decisions

All six open questions have been answered and are locked.

| # | Question | Decision |
|---|---|---|
| 1 | Billing address handling | **Force billing = shipping**, hide the toggle |
| 2 | Postcode strategy | **Sentinel `00000`** for every Nova Poshta address |
| 3 | Part A aggressiveness | **Hide the rate table, keep the Next button** |
| 4 | Warehouse category filter | **Include Postomats** (all customer-facing categories) |
| 5 | Kyiv region mapping | **Kyiv city → `UA-30 Kyiv`** |
| 6 | `Perspective_NovaposhtaShipping` | **Disable the module** |

### 3.1 Address field mapping

Source of truth is the **warehouse row** (it carries denormalised city + oblast), with the city
row as fallback. Computed **once**, server-side, in a single service.

| Magento field | Required for UA? | Source |
|---|---|---|
| `country_id` | yes | constant `'UA'` |
| `city` | yes | `cities.descriptionua` via `city_ref`; fallback `warehouse.city_description_ua` |
| `street[0]` | yes | `warehouse.description_ua`; fallback `description_ru` → `short_address_ua` |
| `street[1..2]` | no | empty — refs get dedicated columns, never street lines |
| `region` (text) | — | `warehouse.settlement_area_description` |
| `region_id` | **yes** | static NP-area → ISO `code` map, resolved to `region_id` at runtime (§3.2) |
| `postcode` | **yes** | constant **`00000`** (§3.3) |
| `telephone` | yes | **customer input only** |
| `firstname` / `lastname` | yes | customer input |
| `company`, `fax`, `vat_id`, prefix/middle/suffix | no | omitted |

`street[0]` example: `Поштомат "Нова Пошта" №44666: вул. Білоуська, 4А` — self-describing,
already contains the branch type and number, and is exactly what a human packer needs.

> **Never** source `telephone` from `warehouse.phone` — that column is the Nova Poshta hotline
> (`380800500609`), not the customer.

Why Ukraine leaves no shortcut: `general/region/state_required` **includes UA** and
`general/region/display_all = 1` (so region renders as a `<select>` bound to a real
`region_id` FK), and UA is **not** in `general/country/optional_zip_countries`
(`HK,IE,MO,PA,GB`). Both fields must be filled.

### 3.2 Region map (NP oblast → Magento ISO code)

NP ships 23 distinct `settlement_area_description` values; Magento's UA directory has 27 regions
under Latin transliterations. **No automatic join is possible** — a static map is required.
Map on **`directory_country_region.code`**, never on `region_id` (install-specific: 1085–1111 here).

| NP `settlement_area_description` | warehouses | Magento code |
|---|---|---|
| Київська | 14,145 | `UA-32` Kyivska oblast |
| Львівська | 5,241 | `UA-46` |
| Дніпропетровська | 4,486 | `UA-12` |
| Одеська | 3,553 | `UA-51` |
| Харківська | 2,851 | `UA-63` |
| Вінницька | 2,525 | `UA-05` |
| Полтавська | 1,967 | `UA-53` |
| Хмельницька | 1,836 | `UA-68` |
| Черкаська | 1,764 | `UA-71` |
| Рівненська | 1,651 | `UA-56` |
| Івано-Франківська | 1,538 | `UA-26` |
| Тернопільська | 1,527 | `UA-61` |
| Житомирська | 1,484 | `UA-18` |
| Волинська | 1,427 | `UA-07` |
| Миколаївська | 1,275 | `UA-48` |
| Закарпатська | 1,206 | `UA-21` |
| Чернігівська | 1,201 | `UA-74` |
| Запорізька | 1,156 | `UA-23` |
| Чернівецька | 1,077 | `UA-77` |
| Кіровоградська | 1,042 | `UA-35` |
| Сумська | 1,020 | `UA-59` |
| Донецька | 223 | `UA-14` |
| Херсонська | 116 | `UA-65` |
| *(no NP data)* | 0 | `UA-09`, `UA-43`, `UA-40` |

**Kyiv city override (decided).** Warehouses in city `Київ` carry
`settlement_area_description = 'Київська'` with `settlement_region_description = NULL`, so the
plain map would send all **7,904** of them to Kyivska *oblast*. Rule: when
`cities.ref = '8d5a980d-391c-11dd-90d9-001a92567626'` (Київ), force **`UA-30 Kyiv`**.
Match on the ref constant, never on the name — `Київець` is a different city.

**Fail closed.** An unmapped area string must log an error with the warehouse id and reject the
selection with a checkout message. A wrong `region_id` on a real order is worse than a blocked
checkout.

Stored as `etc/np_region_map.xml` with its own XSD + `Config\Reader`/`Config\Data` on the
`config` cache — overridable per store, cached, and testable.

### 3.3 Postcode

**`postcode = '00000'` for every Nova Poshta address.** It satisfies `^[0-9]{5}$` and `NotEmpty`,
so core validation passes untouched.

Rationale: `00000` is not a real Ukrainian index, which is the point — it is **unambiguously
synthetic**, so nobody downstream mistakes it for a genuine postal code the way an
oblast-plausible value (`79000`) would be mistaken. Nova Poshta routes by warehouse ref, not by
index, so no fulfilment information is lost.

Exposed as `uho_novaposhta/address/postcode_strategy` (default `sentinel`) so the choice is
reversible by configuration rather than a code change.

**Risk accepted:** a downstream accounting/CRM export that requires a genuine postal code will
receive `00000`. Mitigated by §3.7 (real city + warehouse rendered prominently on admin and
email) and by this document.

---

## 4. Module structure

Two modules, not one.

| Module | Purpose | Depends on |
|---|---|---|
| **`Uho_NovaposhtaShipping`** | The offline carrier only: config, `collectRates()` returning exactly one method. No checkout UI knowledge. | `Magento_Shipping`, `Magento_Quote`, `Magento_OfflineShipping` |
| **`Uho_NovaposhtaCheckout`** | Address fields, city/warehouse endpoints, background fill, persistence, admin + email rendering. | `Uho_NovaposhtaShipping`, `Perspective_NovaposhtaCatalog`, `Magento_Checkout`, `Magento_Sales`, `Magento_Directory` |

The carrier is stable and config-driven; the checkout module is the volatile part. Splitting lets
the carrier serve admin-created and API orders without dragging in checkout JS, and lets the
checkout module be disabled for debugging while orders still work. The dependency is
one-directional.

### 4.1 Directory tree

```
app/code/Uho/NovaposhtaShipping/
├── registration.php
├── etc/
│   ├── module.xml                    # sequence: Magento_Shipping, Magento_OfflineShipping
│   ├── config.xml                    # carriers/uho_novaposhta defaults; sallowspecific=1, specificcountry=UA
│   ├── adminhtml/system.xml          # Sales > Shipping Methods > Nova Poshta (Manual)
│   └── di.xml
├── Setup/Patch/Data/DisableOtherCarriers.php
├── Model/Carrier/NovaposhtaManual.php
└── i18n/{en_US,uk_UA}.csv

app/code/Uho/NovaposhtaCheckout/
├── registration.php
├── etc/
│   ├── module.xml
│   ├── db_schema.xml                 # +5 columns on quote_address & sales_order_address
│   ├── db_schema_whitelist.json
│   ├── extension_attributes.xml      # quote AddressInterface + sales OrderAddressInterface
│   ├── fieldset.xml                  # sales_convert_quote_address -> free quote->order copy
│   ├── di.xml
│   ├── acl.xml
│   ├── np_region_map.xml             # §3.2 map
│   ├── adminhtml/system.xml          # postcode_strategy, category filter, search limits
│   └── frontend/{di.xml,routes.xml,events.xml}
├── Api/
│   ├── CityLocatorInterface.php
│   ├── WarehouseLocatorInterface.php
│   ├── AddressComposerInterface.php
│   └── Data/{CitySuggestionInterface,WarehouseOptionInterface,ComposedAddressInterface}.php
├── Model/
│   ├── Config.php
│   ├── Region/{RegionMap.php,Resolver.php,Config/{Reader,Converter,SchemaLocator}.php}
│   ├── Address/Composer.php          # THE single mapping point
│   ├── Address/PostcodeStrategy/{Sentinel.php,OblastCentre.php}
│   ├── Locator/{CityLocator.php,WarehouseLocator.php}
│   └── Cache/ReferenceDataCache.php
├── Block/
│   ├── Checkout/LayoutProcessor.php
│   └── Adminhtml/Order/View/NovaposhtaInfo.php
├── Controller/Ajax/{CitySearch.php,WarehouseList.php}
├── Plugin/
│   ├── Quote/ShippingInformationManagementPlugin.php
│   ├── Quote/ShippingAddressManagementPlugin.php
│   └── Sales/OrderAddressRendererPlugin.php
├── Observer/SalesModelServiceQuoteSubmitBefore.php
├── ViewModel/Adminhtml/NovaposhtaOrderInfo.php
├── docs/novaposhta-checkout-architecture.md
├── i18n/{en_US,uk_UA}.csv
└── view/
    ├── frontend/
    │   ├── layout/checkout_index_index.xml
    │   ├── requirejs-config.js
    │   └── web/
    │       ├── js/view/shipping-mixin.js
    │       ├── js/view/checkout/shipping/{np-city.js,np-warehouse.js}
    │       ├── js/model/{np-reference-service.js,billing-address-mixin.js}
    │       ├── template/checkout/shipping/{np-city.html,np-warehouse.html}
    │       ├── template/shipping-address/shipping-method-list-silent.html
    │       └── css/source/_module.less
    └── adminhtml/
        ├── layout/sales_order_view.xml
        └── templates/order/view/novaposhta-info.phtml
```

---

## 5. Part A — the only, auto-selected shipping method

**A1. Disable competing carriers via a data patch,** not a manual admin click, so the state is
reproducible across environments: `carriers/flatrate/active = 0`, `carriers/tablerate/active = 0`
at default scope. Write an explicit `0` — deleting the row re-enables `flatrate` from its
config.xml default.

**A2. Disable `Perspective_NovaposhtaShipping`** (§1.2). This removes the API-calling carrier,
its field injection, its `LayoutProcessor`, and its extension attributes in one move — far more
robust than a `di.xml` preference war with an enabled module.

**A3. The carrier.** `Uho\NovaposhtaShipping\Model\Carrier\NovaposhtaManual extends
AbstractCarrier implements CarrierInterface`, returning a single `Result` with one `Method`.
`sallowspecific=1`, `specificcountry=UA`, `isTrackingAvailable() === false` (TTN is created
manually later). **No HTTP client is injected at all**, making an API call structurally impossible.

**A4. Auto-select mixin.** `requirejs-config.js` registers
`Uho_NovaposhtaCheckout/js/view/shipping-mixin` on `Magento_Checkout/js/view/shipping`. In
`initialize`, subscribe to `this.rates`; when `rates().length === 1 && !quote.shippingMethod()`,
call `selectShippingMethodAction(rates()[0])` **and**
`checkoutData.setSelectedShippingRate(carrier_code + '_' + method_code)`.

> The `checkoutData` write is what satisfies the reload / back-forward requirement.
> `Magento_Checkout/js/checkout-data` is localStorage-backed and `shipping.js` restores the
> selected rate from it on init. Auto-selecting *without* it produces the classic bug where a
> mid-checkout reload lands on an empty method step. The `!quote.shippingMethod()` guard lets
> the restore path win and prevents re-firing on every rate refresh, which would spam
> `estimate-shipping-methods`.

Verified hooks in `vendor/magento/module-checkout/view/frontend/web/js/view/shipping.js`:
`shippingMethodListTemplate` (:62), `visible` (:68), `rates` (:259),
`selectShippingMethod` (:272), `setShippingInformation` (:282).

**A5. Hide the UI without touching vendor files.**

- Layout XML overrides the component's own `shippingMethodListTemplate` argument (a documented,
  supported argument) to `Uho_NovaposhtaCheckout/shipping-address/shipping-method-list-silent`,
  which renders a read-only "Доставка: Нова Пошта" line instead of the radio table.
- Cosmetic rules live in our own `view/frontend/web/css/source/_module.less`.

> **Keep the Next button (decision #3).** Do *not* force `visible(false)` on the whole
> `<li id="opc-shipping_method">` — that `<li>` hosts the form whose `submit="setShippingInformation"`
> runs shipping-address validation before advancing to payment. Hiding it removes the only path
> to payment and forces a reimplementation of Magento's validation. Hiding the rate table while
> keeping Next gives "address → Next → payment" with zero reimplementation.

---

## 6. Part B — minimal address form + background fill

**B1. Field reduction.** `Block\Checkout\LayoutProcessor implements LayoutProcessorInterface`,
registered in `etc/frontend/di.xml` on `Magento\Checkout\Block\Onepage::$layoutProcessors` with
an explicit `sortOrder` so it runs **after** Magento's own processor and
`AttributeMerger`/`DirectoryDataProcessor` — those generate `region_id`, `country_id` and
`postcode` dynamically and cannot be removed by static layout XML alone.

Set `visible => false` and strip `required-entry` on: `company`, `street`, `region`, `region_id`,
`postcode`, `country_id`, `fax`, `vat_id`, `prefix`, `middlename`, `suffix`. Keep `firstname`,
`lastname`, `telephone`.

> Use `visible => false`, **not** `unset()`. Removing the nodes breaks
> `Magento_Checkout/js/model/shipping-rates-validator` and several `checkoutProvider` bindings
> that expect the keys to exist. Hidden-but-present keeps the knockout data scope intact.

**B2. Two custom UI components.** `np-city` (typeahead → `citysearch`) and `np-warehouse`
(dependent select → `warehouselist`, disabled until a city ref exists, cleared on city change),
injected via `checkout_index_index.xml` into `shippingAddress.shipping-address-fieldset` with a
`sortOrder` after `telephone`. Both write into `checkoutProvider` so the values ride the standard
`saveAddressInformation` payload — no bespoke save call.

**B3. Background fill.** `Plugin\Quote\ShippingInformationManagementPlugin` — a **`before`**
plugin on `ShippingInformationManagementInterface::saveAddressInformation()`. It reads
`uho_np_city_ref` + `uho_np_warehouse_ref` from the incoming address, calls
`Model\Address\Composer`, and writes `country_id`, `city`, `street`, `region`/`region_id`,
`postcode` plus the snapshot columns before Magento validates.

> `before` (not `around`) is correct: it runs ahead of `Magento\Quote\Model\QuoteAddressValidator`,
> so validation sees a complete, well-formed address and core validation is never relaxed — the
> central requirement. `ShippingAddressManagementInterface::assign()` gets the same treatment to
> cover the estimate-rates path and REST clients, and a
> `sales_model_service_quote_submit_before` observer asserts fail-closed that no order is placed
> with a Nova Poshta method but no composed address.

**The storefront never composes an address.** It submits only `city_ref`, `warehouse_ref`, name
and phone. A malicious or buggy client cannot inject a hand-crafted street/region/postcode, and
there is exactly one code path to audit and unit-test.

**B4. Billing address (decision #1).** `checkout/options/display_billing_address_on = 0`, so each
payment method renders its own full billing form containing exactly the fields Part B removes.
A mixin on `Magento_Checkout/js/view/billing-address` forces `isAddressSameAsShipping = true` and
hides the "same as shipping" checkbox; the LayoutProcessor applies the same field reduction to
the billing fieldset as a safety net.

**B5. Country configuration.** `general/country/default` is absent from the DB → defaults to
**`US`**. A data patch sets `general/country/default = UA`, `general/country/allow = UA`,
`general/country/destinations = UA`, `shipping/origin/country_id = UA` at default scope.
**Verify against website 3 (`pr`), which may need its own scope rows.**

### 6.1 As-built notes (2026-09-01)

Implemented as `Uho\NovaposhtaCheckout\Setup\Patch\Data\SetUkraineCountryConfig`, writing all four
paths as `UA` at `ScopeConfigInterface::SCOPE_TYPE_DEFAULT` (`Store::DEFAULT_STORE_ID`), same
shape as `Uho\NovaposhtaShipping\Setup\Patch\Data\DisableOtherCarriers` — explicit `WriterInterface::save()`
per path, then `ReinitableConfigInterface::reinit()`.

**Website 3 (`pr` / Проросток) does *not* need its own scope rows.** Verified directly against
`core_config_data` before writing the patch: prior to this patch none of the four paths had *any*
row at *any* scope (confirming the doc's "absent from the DB" claim), and `pr` / `pr_ua` carry
store-level overrides only for `currency/options/*` and `general/locale/code` — nothing
country-related. A default-scope-only write therefore already covers both websites; adding
website- or store-scoped duplicate rows would be redundant and a future drift risk (an editor
changing the default and not noticing the per-website copy).

Confirmed empirically, not just by inspection:

- After `setup:upgrade`, `core_config_data` has exactly 4 new rows, all `scope = 'default'`,
  `scope_id = 0`.
- `patch_list` contains
  `Uho\\NovaposhtaCheckout\\Setup\\Patch\\Data\\SetUkraineCountryConfig`.
- A one-off bootstrap script resolved all four paths via `ScopeConfigInterface::getValue(...,
  ScopeInterface::SCOPE_STORE, $storeId)` for store `1` (`base` / `default`) **and** store `2`
  (`pr_ua`): both resolved every path to `'UA'`. Script deleted from container and host after
  verification; not committed.

`shipping/origin/region_id` and `shipping/origin/postcode` were intentionally left untouched —
out of scope for this decision (§3, B5), which only names `country_id`.

---

## 7. Persistence

**Physical columns on `quote_address` and `sales_order_address` via `db_schema.xml`,** exposed
additionally as extension attributes.

```
quote_address / sales_order_address   (+5 columns each)
  uho_np_city_ref            varchar(36)   NULL   -- cities.ref GUID
  uho_np_city_name           varchar(255)  NULL   -- descriptionua snapshot
  uho_np_warehouse_ref       varchar(36)   NULL   -- warehouse.ref GUID
  uho_np_warehouse_name      varchar(255)  NULL   -- description_ua snapshot
  uho_np_warehouse_site_key  varchar(8)    NULL   -- exact site_key, unpadded (§2.3)
  + index on uho_np_warehouse_ref (sales_order_address)
```

Why physical columns rather than extension attributes alone:

- **Snapshotting is a correctness requirement.** The catalog tables are cron-synced and
  destructively refreshed; warehouses close and get renumbered. A ref-only order becomes
  unresolvable — or silently changes meaning — when Nova Poshta retires a branch. Storing
  `*_name` freezes what the customer actually chose, the same reason Magento snapshots product
  name onto `sales_order_item`.
- **Free quote → order propagation.** Listing the columns in our `etc/fieldset.xml` under
  `sales_convert_quote_address` makes `ToOrderAddress::convert()`
  (`module-quote/Model/Quote/Address/ToOrderAddress.php:54-59`, fieldset declared at
  `module-quote/etc/fieldset.xml:11`) copy them with zero custom code. Extension attributes get
  no such automatic copy.
- **Queryable** for admin grid columns, per-warehouse reports and CSV export, with no joins.
- Survives `sales_order_grid` sync and third-party exporters reading the address table directly.

`uho_np_warehouse_site_key` preserves exact warehouse identity and round-trip lookup — the role
originally proposed for `postcode`, which §2.3 rules out.

Extension attributes are still declared for `Magento\Quote\Api\Data\AddressInterface` and
`Magento\Sales\Api\Data\OrderAddressInterface` so values ride the REST/GraphQL contract.
Columns for storage, extension attributes for the contract.

**Rejected:** a side table keyed by `quote_id` (the vendor pattern). It needs a join on every
read, has no FK/cascade in that implementation, and does not survive quote merge — pointless
indirection for five scalar columns.

---

## 8. Storefront data source

**Two frontend controllers under frontName `uho_novaposhta`, returning JSON.**

| Route | Params | Behaviour |
|---|---|---|
| `uho_novaposhta/ajax/citysearch` | `q` (min 2 chars), `limit` (capped 20) | `LIKE 'q%'` on `descriptionua`/`descriptionru` by store locale → `[{ref,label}]` |
| `uho_novaposhta/ajax/warehouselist` | `cityRef`, optional `q`, `limit` (capped 50) | indexed `city_ref =` + optional `LIKE` on `description_ua`/`number_in_city` → `[{ref,label,number,siteKey}]` |

Not preloaded JSON: 11,171 cities is ~600 KB–1 MB added to *every* checkout page load for data
touched once, and warehouses cannot be preloaded at all — **Kyiv alone has 7,904** of 54,311 rows.

Not the REST API: an anonymous `webapi.xml` resource publishes a permanent, unversioned public
endpoint over a 54k-row dataset. A frontend controller keeps the surface private and lets us cap
limits and cache per store.

Implementation notes:

- Both controllers `implements HttpGetActionInterface`, returning `Result\JsonFactory`.
  GET-only reads — do **not** implement `CsrfAwareActionInterface`.
- **Hard-cap `limit` server-side** and enforce a minimum query length. Clamp before the value
  reaches the collection. Never trust the client.
- Use our own `Locator` classes with `addFieldToSelect([...])` + `setPageSize()`. **Do not use
  the vendor's `getListOfWarehousesByCityRef()`** — it hydrates every warehouse model for the
  city (7,904 objects for Kyiv) and its error path returns a fake option
  `['label' => 'Error occur…', 'value' => -502]` that must never reach an address.
- The vendor's `getWarehouseById(int $id)` is **misnamed** — it loads by `site_key`, not `id`.
- Filter `warehouse_status = 'Working'` in SQL.
- **Category filter (decision #4): Postomats are included.** The
  `uho_novaposhta/address/warehouse_categories` config defaults to
  `Branch,Store,Postomat,DropOff`, excluding only `Fulfillment` (10 rows, not customer-facing).
- Cache responses via `Magento\Framework\App\CacheInterface`, tag `UHO_NP_REFERENCE`, TTL 24 h —
  the data changes only on the catalog cron.
- Being AJAX rather than blocks, these endpoints do not affect FPC; the checkout page is already
  uncached.

---

## 9. Admin order view + confirmation email

**One plugin covers three surfaces.** `Plugin\Sales\OrderAddressRendererPlugin`, an `after`
plugin on `Magento\Sales\Model\Order\Address\Renderer::format()`
(`module-sales/Model/Order/Address/Renderer.php:72`). When the address carries
`uho_np_warehouse_ref`, prepend a labelled block above the standard formatted address:

```
Нова Пошта — самовивіз
Місто:      {uho_np_city_name}
Відділення: {uho_np_warehouse_name}
```

Verified this single method feeds the admin order view, `sales_order_print`, **and** the order
confirmation email (`OrderSender.php:137` → `'formattedShippingAddress'`). One extension point,
three surfaces, no email template override, no vendor file touched. Handle `$type`
(`html` vs `text`) so the plain-text email variant stays readable.

**Admin prominence.** `view/adminhtml/layout/sales_order_view.xml` adds
`Block\Adminhtml\Order\View\NovaposhtaInfo` (backed by a ViewModel) into the order-information
area, rendering city + warehouse + warehouse number as a distinct panel — so the real
information reads as the primary shipping fact rather than a footnote beneath `00000` and the
composed street.

This is the mitigation that makes the sentinel postcode safe: a human never needs to read
`postcode` or `street` to fulfil the order.

Optional (defer to phase 2): `uho_np_warehouse_name` as a `sales_order_grid` column — needs a
`sales_order_grid` schema addition plus a `Magento\Sales\Model\ResourceModel\Grid` di mapping.

### 9.1 As-built notes (2026-09-01)

Implemented as planned, with the following refinements over the original spec:

- **Strings are translatable, not hardcoded Ukrainian.** `OrderAddressRendererPlugin` builds the
  block from `__('Nova Poshta — Self-Pickup')`, `__('City')`, `__('Warehouse')` (the latter two
  reuse the checkout module's existing i18n keys) rather than the literal Ukrainian strings shown
  in §9's mock-up. `i18n/uk_UA.csv` renders it identically
  (`Нова Пошта — самовивіз` / `Місто` / `Відділення`) while `en_US.csv` gets a real English
  label — the mock-up was store-locale output, not the source text.
- **All four core format types are handled, not just `html`/`text`.**
  `vendor/magento/module-customer/etc/address_formats.xml` declares `text`, `oneline`, `html`,
  `pdf`. The plugin branches on `html` (escaped `<br />`-joined block), `oneline` (comma-joined,
  since the vendor renderer emits a single line for this type), and a shared plain-text branch
  for everything else (`text`, `pdf`, and any custom type) — so a `pdf` renderer never receives
  raw HTML.
- **"Warehouse number" is `uho_np_warehouse_site_key`, not a separate `number_in_city` snapshot.**
  §7 only snapshots five columns (no `number_in_city`); `site_key` is the exact per-warehouse
  identifier per §2.3, so the admin panel's "Warehouse Number" row shows that. The warehouse
  *name* snapshot already contains the human-readable `№…` the way the §3.1 street example does.
- **Panel container confirmed as `order_additional_info`** (child of `order_tab_info` in
  `vendor/magento/module-sales/view/adminhtml/layout/sales_order_view.xml`, immediately after the
  `order_info` block) — it is a bare container, not nested inside `order_info`'s
  `<table>`, so `Block\Adminhtml\Order\View\NovaposhtaInfo`'s template renders its own
  self-contained `admin__page-section-item` panel (title + secondary table) rather than emitting
  loose `<tr>`s.
- **The block reads the order via `Magento\Framework\Registry::registry('current_order')`
  directly** (constructor-injected `Registry`, not `AbstractOrder`), keeping all
  presentation/business logic in the injected `ViewModel\Adminhtml\NovaposhtaOrderInfo` per the
  "backed by a ViewModel" requirement; the ViewModel returns `null` for a virtual order or an
  address with no `uho_np_warehouse_ref`, and the template renders nothing in that case.
- **Plugin registered globally** in `etc/di.xml` (not `etc/adminhtml/di.xml`), since
  `Magento\Sales\Model\Order\Address\Renderer::format()` runs in both the adminhtml area (order
  view, print) and whichever area sends the confirmation email.

**Verification performed** (both custom orders in the local DB — `000000001`/`000000002` — predate
this module and carry no `uho_np_*` data, so no real order could be used end-to-end):

- `setup:upgrade` and `setup:di:compile` completed cleanly; the generated
  `generated/code/Magento/Sales/Model/Order/Address/Renderer/Interceptor.php` routes `format()`
  through the plugin list as expected.
- A one-off bootstrap script called `Renderer::format()` directly on an in-memory
  `Order\Address` with `uho_np_*` data set via `setData()`, for all three of `html`/`text`/`oneline`:
  output matched the §9 shape, HTML entities were correctly escaped
  (`&quot;` around the quoted warehouse name), and a second address with no NP data passed through
  the plugin unchanged.
- A second bootstrap script registered a real order (`entity_id` 1) into `current_order`,
  instantiated `NovaposhtaInfo` via the layout factory, and rendered it: empty output before NP
  data was set on the shipping address, full panel (City / Warehouse / Warehouse Number) after —
  confirming the block, ViewModel, and template wiring with no fatal errors.
- Both temp scripts were deleted from the container and host after verification; not committed.

`uho_np_warehouse_name` as a `sales_order_grid` column remains deferred to phase 2, unchanged from
the original plan.

---

## 10. Phasing

| Phase | Work | Parallel? | Blocks |
|---|---|---|---|
| **0** | Fix `.warden/warden-env.yml`; verify `warden magento` / `warden composer` | — | **everything** |
| **1** | `Uho_NovaposhtaShipping`: carrier, `system.xml`, `config.xml`, data patch disabling flatrate/tablerate; `module:disable Perspective_NovaposhtaShipping` | sequential | 2, 3 |
| **2a** | `Uho_NovaposhtaCheckout` skeleton, `db_schema.xml`, `fieldset.xml`, `extension_attributes.xml`, whitelist | ∥ 2b, 2c | 4 |
| **2b** | `np_region_map.xml` + XSD, `Region\Resolver`, `PostcodeStrategy`, **`Address\Composer`** + unit tests (Kyiv override, oblast centre, village, unmapped-area failure) | ∥ 2a, 2c | 4 |
| **2c** | `Locator` read models, `Controller\Ajax\*`, reference cache | ∥ 2a, 2b | 3b |
| **3a** | **Part A**: `shipping-mixin.js`, silent list template, layout XML, `_module.less` | ∥ 3b | 5 |
| **3b** | **Part B UI**: `LayoutProcessor`, `np-city.js`, `np-warehouse.js`, templates, layout XML | ∥ 3a | 4 |
| **4** | Wire-up: both quote plugins, submit-before observer, billing mixin | sequential | 5 |
| **5** | ✅ Admin + email: renderer plugin, admin block + ViewModel + layout | ∥ 6 | — |
| **6** | ✅ Country/region config data patch — verify on **both** websites | ∥ 5 | — |
| **7** | ✅ `@security-reviewer` ✅, `di:compile` ✅, `static-content:deploy -f` ✅, full manual test matrix (§10.1) including human browser session ✅ (see §10.3) | sequential | — |
| **8** | ✅ Finalise this document with as-built notes | ∥ | — |

### 10.1 Test matrix (phase 7)

- Fresh guest checkout
- **Mid-checkout browser reload** (the `checkoutData` requirement)
- Browser back, then forward
- Registered customer with a saved address
- Virtual-only cart (`quote.isVirtual()` → no shipping step; verify the mixin does not throw)
- **Kyiv** (7,904 warehouses — measure endpoint latency) and a village with one warehouse
- A **Postomat with a 6-digit `site_key`** — confirms §2.3 and that `00000` validates
- Store switch to `pr_ua` (uk_UA) — labels and region resolution

### 10.2 Security review scope (phase 7)

- `Controller/Ajax/CitySearch` + `WarehouseList` — unauthenticated public endpoints over 54k+
  rows. Check server-side limit clamping, minimum query length, SQL injection via the `LIKE`
  term (must go through `addFieldToFilter`, never string concatenation), JSON label escaping,
  and DoS via unbounded repeated queries.
- `Address\Composer` — confirm the client can influence only `city_ref` / `warehouse_ref`, both
  validated against the local tables, and never `street` / `region_id` / `postcode` directly.
- `templates/order/view/novaposhta-info.phtml` — Nova Poshta data is externally sourced and must
  be escaped with `$escaper->escapeHtml()`.

### 10.3 As-built notes (2026-09-01)

**Security review.** `@security-reviewer` covered exactly the §10.2 scope plus the two plugins and
`AddressExtensionRefApplier` that call `Composer`, and `Observer\SalesModelServiceQuoteSubmitBefore`.
SQL injection, minimum-query-length, limit-clamping, JSON/XSS, CSRF, error-message leakage, and
admin-template escaping all checked out clean. Two low-severity findings:

1. **Fixed.** `etc/extension_attributes.xml` declares `uho_np_city_name` / `uho_np_warehouse_name`
   / `uho_np_warehouse_site_key` on `AddressInterface` (and `OrderAddressInterface`) alongside the
   two ref fields. Per §7 these three are meant to be output-only snapshots that ride the
   REST/GraphQL contract, but nothing stops a REST client from also *submitting* them — and while
   no code currently reads them back (`AddressExtensionRefApplier` only ever reads the two refs,
   and always overwrites the snapshot columns from `Composer::compose()`'s own return value via
   `setData()`, never from extension attributes), that safety was an unenforced invariant: a
   future reader that used `getExtensionAttributes()->getUhoNpCityName()` instead of `getData()`
   would silently reopen a spoofing path for data the admin template renders as trusted. Fixed by
   having `AddressExtensionRefApplier::apply()` explicitly discard any client-supplied value for
   these three attributes (`setUhoNp*Name/SiteKey(null)`) unconditionally, before reading the refs
   — see `discardClientSuppliedSnapshotAttributes()`. The schema itself is untouched, so the §7
   REST/GraphQL output contract is preserved. Covered by
   `Test/Unit/Model/Address/AddressExtensionRefApplierTest.php` (3 tests: attributes discarded
   with no refs present, discarded before `compose()` runs, and the existing half-formed-ref
   rejection still throws).
2. **Accepted, deferred.** No rate limiting on the two anonymous AJAX endpoints beyond the 24h
   `ReferenceDataCache` (which only dedupes *identical* repeated queries, not enumeration across
   distinct ones). Matches the risk already carried in §11 ("Kyiv endpoint performance") and rated
   Low by the reviewer given the indexed `city_ref` bound and row counts involved; left as a
   follow-up hardening item (IP/session token-bucket, or a `limit_req` rule at the Nginx/Varnish
   layer) rather than a phase-7 blocker.

**`di:compile`.** The first `setup:di:compile` run reported success while silently reusing stale
`generated/code` from before this module existed — `generated/code/Magento/Checkout` and
`Magento/Quote` were entirely absent, meaning the compiled plugin-list metadata had **no** entry
for `ShippingInformationManagementPlugin`, `ShippingAddressManagementPlugin`, or
`OrderAddressRendererPlugin` despite the command exiting 0 with "Generated code and dependency
injection configuration successfully." This was caught by cross-checking with a runtime resolution
probe (`$objectManager->get(Renderer::class)` etc. — each still resolved to an `Interceptor`
subclass, because in `default` mode Magento's autoloader generates missing interceptors on demand,
masking the gap). A clean recompile —
`rm -rf generated/code/* generated/metadata/* var/cache/* var/generation/*` before
`setup:di:compile` — produced the expected 2,733 interceptors including all three of ours, and
`grep -rl uho_novaposhta generated/metadata/` confirmed the plugin names are present in every
area's compiled plugin list (global, adminhtml, frontend, webapi_rest/soap, graphql, crontab).
**Lesson: never trust a non-clean `setup:di:compile` run's exit code alone in this environment —
clear `generated/` and `var/cache`, `var/generation` first, or verify with a runtime resolution
check.** `cache:flush` and `module:status`/`indexer:status` were clean after the second, clean
compile.

**`static-content:deploy -f`.** Ran clean across both themes (`blank`, `luma`) and both locales
(`en_US`, `uk_UA`), including the `Uho/seed-store` theme — no missing-file or compilation errors.
`cache:flush` after.

**Test matrix (§10.1) — partial, non-browser coverage.** No headless-browser tool
(`chromium-cli`/Playwright) is available in this environment; the user opted to run the
browser-dependent items themselves rather than have one installed. The items answerable via
`curl`/DB/a bootstrap script were still verified here:

- **Kyiv endpoint latency** — `GET uho_novaposhta/ajax/citysearch?q=Київ` and
  `.../warehouselist?cityRef=<Kyiv ref>` (7,904 rows, no query filter) both returned in well under
  a second (~0.6s cold, ~0.36s on the `ReferenceDataCache` hit) directly against `legal.test`. Also
  incidentally reconfirmed §2.1's "`Київ` vs `Київець`" name-collision case: the city search
  correctly returns both as distinct refs rather than a false match.
- **A village with one warehouse** — queried the DB for a `city_ref` with exactly one `Working`
  row (Покровка, Роздільнянський р-н), then confirmed `warehouselist` returns exactly that one
  option with no filtering artefacts.
- **A Postomat with a 6-digit `site_key`** — found one (`site_key = 106503`, city Авангард,
  Одеська) and ran it through the real `AddressComposerInterface::compose()` (bootstrap script,
  deleted after use, not committed): `postcode` came back `'00000'` and passed
  `^[0-9]{5}$`, and `warehouseSiteKey` round-tripped as the full unpadded `'106503'` — confirms
  §2.3's core claim end-to-end, not just by schema inspection.

**Human browser session — completed (2026-09-01)**, against `legal.test` (base, `en_US`) and the
`pr_ua` store (`uk_UA`), per §10.1. All items passed with no issues reported:

- [x] Fresh guest checkout, full flow to order placement
- [x] Mid-checkout browser reload (the `checkoutData` persistence requirement — §5 A4)
- [x] Browser back, then forward, through the shipping step
- [x] Registered customer with a saved address
- [x] Virtual-only cart (no shipping step; `shipping-mixin.js` did not throw)
- [x] Store switch to `pr_ua` (uk_UA) — labels and region resolution rendered correctly
- [x] Visual/UX check of the silent shipping-method line and the minimal address form (§5 A5, §6
      B1)

This closes out phase 7 in full — every item in §10.1, §10.2, and §10.3 is now verified.

**Re-verification (2026-09-01, later same day).** Re-checked every automatable phase-7 item from a
fresh session, independent of the notes above:

- `module:status` — `Perspective_NovaposhtaCatalog`, `Uho_NovaposhtaShipping`,
  `Uho_NovaposhtaCheckout` all enabled; `Perspective_NovaposhtaShipping` absent from
  `app/etc/config.php` entirely (not merely written as `0`) and absent from `vendor/` and
  `composer.json` — stronger than the disable-only decision in §1.2/§3.1, but consistent with it
  (no code from that package can run either way).
- `generated/code/Magento/{Checkout,Quote,Sales}` present and `grep -rl uho_novaposhta
  generated/metadata/` still returns 7 hits — the clean `di:compile` from earlier today is intact.
- `pub/static/deployed_version.txt` and directory mtimes confirm the static deploy is from earlier
  today; `find pub/static/frontend -iname 'np-city.js' -o -iname 'shipping-mixin.js'` returns
  matches under `Magento/blank`, `Magento/luma`, and `Uho/seed-store`, for both `en_US` and
  `uk_UA` — all three themes, both locales, matching the §10.3 claim above.
- Full `Test/Unit` suite for `Uho_NovaposhtaCheckout` (not just the security-fix test class):
  **48/48 passing**, 221 assertions.

No regressions found between this re-verification pass and the human browser session below.

### 10.4 Phase 8 — document finalised (2026-09-01)

All eight phases are complete. This document is the as-built record of the implementation:

- §6.1, §9.1 — phase 5/6 as-built refinements over the original spec.
- §10.3 — phase 7 verification, including the completed §10.1 browser test matrix above.
- §11 — the `.warden/warden-env.yml` blocker is resolved; all other listed risks carry their
  original mitigations, still in force as implemented (no new risks surfaced during browser
  testing or the re-verification pass).

Deferred, out of scope for this implementation (unchanged from where each was originally raised):

- `uho_np_warehouse_name` as a `sales_order_grid` column (§9, "Optional (defer to phase 2)").
- Rate limiting on the two anonymous AJAX endpoints beyond the 24h reference-data cache (§10.3,
  finding 2 — accepted and deferred by the security reviewer).
- Enabling `novaposhta_catalog/schedule/enabled` before go-live (§1, §11) — an operational step,
  not a code change, and outside this module's scope.

---

## 11. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| `.warden/warden-env.yml` corrupted — nothing can be built or deployed | **Resolved** | Fixed in phase 0 (§0); stray `45015` prefix removed |
| Wrong `region_id` on real orders — the NP→Magento map is hand-built | **High** | Fail closed on unmapped areas; unit tests over all 23 area values + the Kyiv override; map on ISO `code`, never `region_id` |
| `postcode = 00000` rejected by a downstream export | Medium | Real city + warehouse rendered prominently (§9); config-switchable strategy; documented here |
| `novaposhta_catalog/schedule/enabled = 0` — reference data not auto-syncing | Medium | Enable the cron before go-live; warn if `last_sync_warehouse` is older than N days |
| Kyiv endpoint performance (7,904 warehouses, Postomats included) | Medium | Indexed `city_ref` + `addFieldToSelect` + `setPageSize` + server-side search + 24 h cache; never call `getListOfWarehousesByCityRef()` |
| Ref staleness on historical orders | Medium | `*_name` + `site_key` snapshot columns (§7) |
| Multi-website scope drift (`base` / `pr`, different locales) | Medium | All config via data patch at default scope; explicitly test store 2 |
| `flatrate` re-enables itself if the config row is deleted | Low | Write explicit `0`, never delete the row |
| Vendor `-502` pseudo-option leaking into an address | Low | Bypass the vendor list method; validate every ref against local tables in `Composer` |

---

## 12. Guardrail compliance

| Guardrail | How it is met |
|---|---|
| No calls to `api.novaposhta.ua` / `novapost.com` | Carrier has no HTTP client injected; all reads hit local catalog tables |
| No `module-novaposhtashipping` classes involved | Module disabled outright (§1.2) |
| No destructive edits to core checkout layout | Only plugins, mixins, layout XML in our own modules, and a supported template argument |
| Schema + mapping confirmed before implementation | §2 and §3.1, verified by direct SQL — no guessed column names |
