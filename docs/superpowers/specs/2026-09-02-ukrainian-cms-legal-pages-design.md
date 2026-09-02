# Ukrainian-language CMS legal & content pages, across all stores

## Context

This repo runs three storefronts, all confirmed `uk_UA` locale (no per-store
override needed — the `general/locale/code` value is already `uk_UA` at
default scope):

| Website | Store code | Store name | Status |
|---|---|---|---|
| `base` (website 1) | `default` | Default Store View | Cosmetics/general storefront |
| `odiag` (website 2) | `odiag` | Одяг | No theme override, no brand guide yet — set up manually in Admin, not via a data patch |
| `pr` (website 3) | `pr_ua` | Проросток | `Uho/seed-store` theme, brand guide at `docs/BRAND_GUIDE.md` (Сіємо разом) |

All six `cms_page` rows currently in the system (`privacy-policy-cookie-restriction-mode`,
`enable-cookies`, `customer-service`, `no-route`, `about-us`, `home`) are
**unmodified Luma sample content**: English, referencing the fictional "Luma
Inc.", US shipping zones (Alaska/Hawaii/PST), and generic GDPR-agnostic
privacy boilerplate. All are currently scoped `store_id=0` ("All Store
Views"), shared identically across all three storefronts. `general/store_information`
config is empty at every scope, for every store — no real company name,
address, or contact info exists anywhere yet.

Each of the three storefronts is (or will become) a **separate legal
entity/brand**, but none has real registered business details yet. This
spec treats that as a known future gap: content uses clearly-bracketed
placeholders (e.g. `[Назва компанії]`) rather than blocking on business
registration details that don't exist yet.

## Goal

Replace the stock Luma content on all six CMS pages with Ukrainian content,
legally grounded for a Ukraine-only storefront, applied across all three
stores. Not a literal translation of the Luma text — a rewrite, since the
existing content doesn't reflect this business (wrong company, wrong
country's law, wrong shipping/returns model).

## Non-goals

- Real company/legal-entity details — these don't exist yet; placeholders
  are used and a follow-up task will fill them in per store once available.
- Magento's built-in Cookie Restriction Mode banner/toggle behavior
  (`web/cookie/*` config) — out of scope. Ukraine has no EU-style mandatory
  cookie-consent-banner law, so this is a CMS-content-only change; the
  banner mechanism itself is unchanged.
- `cms_block` entries (e.g. `home_hero_banner`, `home_featured_categories`,
  `gear-block`, `women-block`, etc.) — a much larger, separate set of
  content, and out of scope here. The `home` CMS *page* itself has no body
  content (layout/block-driven), so only its meta title is touched.
- Renaming CMS page identifiers — `privacy-policy-cookie-restriction-mode`,
  `enable-cookies`, etc. are referenced by hardcoded Magento core config
  paths (footer links, cookie-notice template) and stay as-is. Only title,
  meta fields, and body content change.
- Одяг's theme/brand build-out — this spec gives it placeholder-quality
  legal/about content consistent with the other stores, not a real brand
  voice (no brand guide exists for it yet).

## Legal basis

Rewritten legal text is grounded in:
- Law of Ukraine "On Personal Data Protection" (№2297-VI) — privacy policy structure and required disclosures.
- Law of Ukraine "On Electronic Commerce" (№675-VIII) — public-offer / order-rules disclosures.
- Law of Ukraine "On Consumer Rights Protection" — the 14-day return right (Art. 9) for distance-sold goods of proper quality, used in the delivery/returns page.

## Page-by-page treatment

| Page (identifier) | Treatment | Store scope |
|---|---|---|
| `privacy-policy-cookie-restriction-mode` | Full rewrite: Ukrainian privacy policy per Law №2297-VI / №675-VIII. Entity-specific fields (company name, address, EDRPOU, contact) as bracketed placeholders, e.g. `[Назва компанії]`, `[Адреса]`, `[Email для звернень]`. | Shared, `store_id=0` |
| `enable-cookies` | Direct translation of the browser-cookie-settings instructions; refresh the browser list (drop the dead IE link, add Edge). No entity-specific content. | Shared |
| `customer-service` | Rewrite as "Доставка та повернення": real Nova Poshta delivery methods (the `Uho\NovaposhtaCheckout` / `Uho\NovaposhtaShipping` modules already exist in this codebase), 14-day return right per Consumer Rights Law Art. 9. Drop the US pricing table entirely. | Shared |
| `no-route` (404) | Direct translation, generic UI copy, no legal or entity content. | Shared |
| `about-us` | Per-store brand copy. Проросток gets real Сіємо разом voice (warm/practical gardener tone, per `docs/BRAND_GUIDE.md`). Default and Одяг get short, neutral Ukrainian placeholder copy, explicitly flagged in a code comment as "revise once that store's brand work happens." | 3 separate rows, one per store |
| `home` | Body content stays empty (block/layout-driven — real content is in `cms_block` rows, out of scope per above). Only `meta_title` changes to a generic Ukrainian value. | Shared |

## Technical implementation

One new data patch: `Uho\Store\Setup\Patch\Data\LocalizeCmsContentForUkraine.php`,
following the existing `CreateProrostokStore.php` pattern (constructor-injected
resource models implementing `DataPatchInterface`).

**Behavior:**
1. For the four shared pages plus `home`'s meta title: load each `cms_page`
   by identifier via `Magento\Cms\Model\ResourceModel\Page`, update
   `title` / `meta_title` / `meta_description` / `content`, save.
2. For `about-us`: reassign the existing row (page_id 5) to store `default`
   only, with Default's placeholder copy. Create two additional `cms_page`
   rows, also identifier `about-us`, scoped via `cms_page_store` to
   `pr_ua` (real Сіємо разом copy) and `odiag` (placeholder copy)
   respectively.
3. Each page's Ukrainian HTML body lives in its own file under
   `app/code/Uho/Store/Setup/Patch/Data/content/*.html`, loaded with
   `file_get_contents()` in the patch — keeps the patch class readable
   instead of one large inline-string blob.

**Store resolution (Одяг gotcha):** Одяг (`odiag`/`odiag` store) has no
creating data patch — it was set up directly in Admin, unlike Проросток. A
fresh environment (CI, another developer's local, or production before this
patch has run there) may not have that website/store at all yet. The patch
therefore resolves target stores **by code** via `StoreManagerInterface`
(`default`, `pr_ua`, `odiag`) and **skips that store's content gracefully**
(no exception) if its code isn't found, rather than hardcoding store IDs —
so the patch is safe to run in any environment regardless of whether Одяг
exists there.

## Verification

- `warden env exec -T php-fpm bin/magento setup:upgrade` runs clean; patch
  appears applied in `patch_list`.
- Each page renders correctly per store view: title, meta, and content
  correct for that store, no leftover unresolved `{{store url="..."}}`
  directives.
- Footer "Privacy and Cookie Policy" and "Enable Cookies" links still
  resolve correctly (identifiers unchanged, so this should be unaffected,
  but confirm after the patch runs).
- `about-us` shows distinct content on `default`, `odiag`, and `pr_ua` store
  views; no store falls back to a missing/blank page.

## Follow-up (not in this spec)

- Once real legal-entity details exist for each of the three stores,
  replace the bracketed placeholders with real values — likely a small
  follow-up patch, informed by which fields actually need to differ
  per-store at that point.
- `cms_block` content (homepage blocks, category landing blocks like
  `gear-block`/`women-block`) is still stock Luma English content for
  Default and Одяг — a separate future localization pass.
- Одяг lacks a `CreateProrostokStore`-equivalent data patch; if it's meant
  to exist in fresh/production environments, it should get one.
