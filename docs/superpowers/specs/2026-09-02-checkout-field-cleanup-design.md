# Nova Poshta Checkout — Field Cleanup & Translation Fix

**Status:** Design approved, not yet implemented.
**Date:** 2026-09-02
**Relates to:** `app/code/Uho/NovaposhtaCheckout/docs/novaposhta-checkout-architecture.md` (the
original Nova-Poshta-only checkout build). That document's Phase 7 claims the field-hiding was
fully tested and passed; live verification for this spec found it was not actually working. This
document supersedes that claim for the affected fields and does not otherwise revisit the rest of
that architecture.

## 1. Problem

A live check of `legal.test/checkout/` (all Magento caches disabled, Varnish confirmed bypassed —
`Cache-Control: no-store`) showed the shipping-address form still rendering native Magento street
inputs and a region/state dropdown, in addition to the intended Nova Poshta city/warehouse
selectors:

- Two street line inputs ("Адреса (вулиця, номер будинку та квартири...)").
- A region/state `<select>` labelled "Штат/Область" with an untranslated placeholder
  "Please select a region, state or province."

The checkout should show only: Email, Ім'я, Прізвище, Номер телефону, Місто (Nova Poshta city),
Відділення (Nova Poshta warehouse) — nothing else. Street and region must still be computed and
stored server-side (unchanged — see the architecture doc §3.1, §6 B3), just never shown to the
customer.

Separately, the warehouse address stored in the order's `street` field should be sourced from
`perspective_novaposhta_catalog_warehouse.short_address_ua` instead of `description_ua`.

## 2. Root causes (verified live against `legal.test`, base store, Luma theme)

### 2.1 Street group children are never hidden

`Block\Checkout\LayoutProcessor::hideFields()` sets `visible: false` on the top-level `street`
node, which is a `type: group` component (`Magento_Ui/js/form/components/group`). Its Knockout
template
(`vendor/magento/module-ui/view/frontend/web/templates/group/group.html`) never checks the
group's own `visible` — only each child element's:

```html
<!-- ko foreach: { data: elems, as: 'element' } -->
    <!-- ko if: element.visible() -->
        ...
    <!-- /ko -->
<!-- /ko -->
```

Hiding the group container is therefore a no-op for the two street line inputs, which default to
`visible: true` since nothing sets it on them individually.

The group's `<legend>` (its own label, "Адреса (вулиця, номер будинку та квартири...)") is
**outside** that `foreach` and renders unconditionally regardless of any visibility state, group
or child.

### 2.2 `region_id`'s `customEntry` wiring re-shows it after load

`region_id`'s component is `Magento_Ui/js/form/element/region`, which extends `select.js`. Its
config carries `"customEntry": "shippingAddress.region"` — the standard Magento mechanism for
toggling between a region **select** (countries with predefined regions) and a free-text region
**input** (countries without). `select.js#setOptions()`:

```js
setOptions: function (data) {
    ...
    if (this.customEntry) {
        isVisible = !!result.options.length;
        this.setVisible(isVisible);       // <-- unconditionally overrides `visible`
        this.toggleInput(!isVisible);
    }
    return this;
},
```

`region_id` imports its options from `checkoutProvider:dictionaries.region_id`
(`"imports":{"setOptions": "index = checkoutProvider:dictionaries.region_id"}`). The moment
Ukraine's real regions load (which they always do — UA is in
`general/region/state_required`), `setOptions()` fires and sets `visible(true)`, clobbering our
server-side `visible: false` within moments of page load. This was confirmed directly: server-side
`jsLayout` (verified via a temporary debug write inside `LayoutProcessor::process()`) correctly
carries `"visible":false` for `region_id`, and the exact same string is present in the raw HTML the
server sends — but the live `uiRegistry` component's `visible()` reads back `true` once the page
finishes loading.

`region.js#hideRegion()` (called from `initialize()` and `update()`) only ever calls
`setVisible(false)` when the selected country's `is_region_visible === false`; it never forces
`true`, so it is not the source of the re-show — `select.js#setOptions()` is.

### 2.3 Verified fix

Both were confirmed live by temporarily patching `hideFields()` to (a) cascade `visible: false`
onto each entry of a hidden field's `children` array, and (b) `unset` `config.customEntry` on
hidden fields, then reloading `legal.test/checkout/` with cache disabled. Result: region dropdown
gone, both street inputs gone, np-city/np-warehouse and the silent shipping-method line unaffected.
Only the street group's `<legend>` remained (per §2.1, expected — needs a CSS rule, not a JS/PHP
fix). The patch was reverted after verification; this document specifies it as the design.

### 2.4 Translation gap

`vendor/mageplaza/magento-2-ukrainian-language-pack/uk_UA.csv` (a third-party package already in
use per `CLAUDE.md`) ships:

```
"State/Province","Штат/Область",module,Magento_Checkout
"Please select a region, state or province.","Please select a region, state or province.",module,Magento_Customer
```

i.e. it translates the label to the (inaccurate for a Ukraine-only store) "Штат/Область" and
leaves the placeholder as literal English. This is moot once §2.2's fix hides the field entirely,
but the label text remains in scope in case the field is ever shown by a Magento core future
change, an admin-facing render of the same UI form, or another store add region back — and the
user has asked for it fixed regardless.

## 3. Design

### 3.1 `Block\Checkout\LayoutProcessor` — `hideFields()`

Extend the existing loop (used for both `HIDDEN_SHIPPING_FIELDS` and `HIDDEN_BILLING_FIELDS`, so
this fixes billing too) to, per hidden field:

1. Keep existing: set `visible: false`, unset `validation.required-entry`.
2. New: `unset($fieldset[$field]['config']['customEntry'])` if present.
3. New: if `$fieldset[$field]['children']` is an array, set `visible: false` on every entry in it.

No new fields are added to `HIDDEN_SHIPPING_FIELDS`/`HIDDEN_BILLING_FIELDS` — `region_id` and
`street` are already listed; the fix is in how each listed field gets hidden, not which fields are
listed.

### 3.2 CSS — `view/frontend/web/css/source/_module.less`

Add a rule hiding the street group's fieldset (confirmed rendered as
`<fieldset class="field street ...">`):

```less
.checkout-index-index {
    .fieldset .field.street {
        display: none;
    }
}
```

Scoped to the checkout page body class (already how this file scopes its other rules, per the
existing file) so it cannot affect a `street` group elsewhere (e.g. an admin form reusing the same
UI component).

### 3.3 `Model\Address\Composer::compose()` — street source

Today, one `$warehouseName` (built from `description_ua` → `description_ru` →
`short_address_ua`) feeds both the order's `street` array and the `uho_np_warehouse_name` snapshot
column (admin panel + confirmation email, per architecture doc §9).

Split into two values:

- `street` (new): `[$warehouse->getData(WarehouseInterface::SHORT_ADDRESS_UA)]`, trimmed. No
  fallback chain — the architecture doc's own data audit (§2.2) found `short_address_ua` 0% empty
  across all 54,311 rows, so the `NoSuchEntityException` "no address" guard is unreachable for this
  field and is not duplicated here.
- `uho_np_warehouse_name` (unchanged): keeps today's `description_ua` → `description_ru` →
  `short_address_ua` chain and its existing "no address" exception, since this is what §9's admin
  panel and confirmation email render as "Відділення" and the friendlier
  `Поштомат "Нова Пошта" №44666: ...` framing is intentionally kept there (per user decision,
  2026-09-02: "street only", not "everywhere").

No other part of `compose()` changes — `region`, `region_id`, `postcode`, city resolution, and ref
validation are all untouched.

### 3.4 Translations — module-level override, not theme-level

Two stores are in play: `base` (Luma theme, unmodified vendor theme, no existing i18n override
slot) and `pr` / `pr_ua` (Uho/seed-store theme, which already has
`app/design/frontend/Uho/seed-store/i18n/uk_UA.csv`). The user asked for the fix to apply to "all
stores and themes" — duplicating the two lines into a per-theme override file for Luma would work
for today's two themes but not for a future third theme without remembering to copy it again.

Instead: add both lines to `Uho_NovaposhtaCheckout`'s own `i18n/uk_UA.csv`, and add
`Mageplaza_Core` (the language pack's owning module) to `Uho_NovaposhtaCheckout`'s `<sequence>` in
`etc/module.xml`. Magento merges translation CSVs from every active module for the resolved locale
into one dictionary, keyed by module load order (a topological sort of `<sequence>` dependencies)
with later-loaded modules winning on a duplicate source string. Declaring the sequence dependency
guarantees `Uho_NovaposhtaCheckout` always loads after `Mageplaza_Core`, so its two lines always
win — automatically, for every current and future store/theme, with a single change instead of a
per-theme file.

New lines in `app/code/Uho/NovaposhtaCheckout/i18n/uk_UA.csv`:

```
"State/Province","Область"
"Please select a region, state or province.","Оберіть область"
```

`etc/module.xml` sequence gains one entry:

```xml
<module name="Mageplaza_Core"/>
```

`en_US.csv` is untouched — English already reads correctly ("State/Province", "Please select a
region, state or province.").

**Risk:** the last-loaded-wins CSV merge behavior described above is standard Magento translation
loading behavior but was not re-verified live for this spec (unlike §2.1–§2.3, which were).
Verification step 2 in §5 checks this directly. If the sequence dependency does not win for some
reason, the fallback is a theme-level override (the technique `Uho/seed-store/i18n/uk_UA.csv`
already uses, which unconditionally beats module translations) added to both Luma and
Uho/seed-store — at the cost of the per-theme duplication this design otherwise avoids.

## 4. Out of scope

- No change to `region`/`region_id`/`postcode` resolution logic, the NP→Magento region map, or the
  postcode sentinel strategy (architecture doc §3.1–§3.3) — only visibility and label text change.
- No change to the admin order view, confirmation email rendering, or the `uho_np_warehouse_name` /
  `uho_np_warehouse_site_key` snapshot columns.
- No change to `city` field hiding — already confirmed working (native `city` is already hidden;
  only the Nova Poshta `uho-np-city` component is shown).
- Billing address fields go through the same `hideFields()` fix via
  `hideBillingFieldsets()` (§3.1 applies there too, unchanged call site) but this was not
  separately re-verified live in a browser for this spec, since decision #1 in the architecture doc
  forces billing = shipping and hides the billing form entirely — the billing-fieldset
  `street`/`region_id` nodes exist only as the "safety net" the architecture doc already describes.

## 5. Verification plan (for the implementation step)

1. `legal.test/checkout/` (base, Luma, `en_US`): only Email, Ім'я/First name, Прізвище/Last name,
   Номер телефону/Phone, Місто/City (NP), Відділення/Warehouse visible in the shipping form; no
   street inputs, no region dropdown, no leftover "Адреса..." label.
2. `pr_ua` store (Uho/seed-store, `uk_UA`): same check, plus confirm "Область" label and "Оберіть
   область" placeholder if the field is ever shown (defense-in-depth per §2.4) and confirm the
   seed-store's own `i18n/uk_UA.csv` isn't now duplicating/conflicting with the two new module-level
   lines.
3. Place a full guest order on a Postomat with a short `short_address_ua` value; confirm the
   resulting `sales_order_address.street` matches `short_address_ua` exactly (not the
   `description_ua` "Поштомат №..." framing), and confirm the admin order view / confirmation email
   "Відділення" line still shows the `description_ua`-based friendly name, unchanged.
4. Re-run the existing `Test/Unit` suite for `Uho_NovaposhtaCheckout` (48 tests as of the
   architecture doc) plus any new/updated test for `Composer::compose()`'s street source.
5. Confirm billing address (rendered per payment method) still shows no street/region fields either.
