# Brand Guide — Сіємо разом

Brand identity for the seed/gardening storefront theme. This is the source of
truth for palette, typography, and logo usage — theme LESS variables and
templates should derive from these values, not the other way around.

- **Store name:** Сіємо разом ("Let's sow together")
- **Market / locale:** Ukraine, `uk_UA`, currency `UAH`
- **Theme code:** `Uho/seed-store` (Magento Luma child theme)

## Palette — Сад і Ліс (Garden Classic)

| Role | Hex | Usage |
|---|---|---|
| Primary | `#2F5233` | Primary buttons, header background, logo ink |
| Ink | `#2A2620` | Body text on light surfaces |
| Surface | `#F4EDE0` | Page background, cards |
| Accent (links / secondary) | `#A6502F` | Links, secondary/outline buttons, sale price |
| Highlight | `#E8B23A` | Badges ("нове", "сезонне"), small accents |

All text/button pairs below are checked against WCAG AA for normal text (≥4.5:1):

| Pair | Ratio |
|---|---|
| White text on Primary button | 8.8 : 1 |
| Accent text/links on Surface | 4.7 : 1 |
| White text on Accent (outline-filled) button | 5.5 : 1 |
| Ink text on Highlight badge | 7.8 : 1 |
| Ink text on Surface (body copy) | 12.9 : 1 |

Two alternate directions (Насіннєва Крамниця / vintage-rust, and Modern
Grow / brighter leaf-green) were proposed and rejected in favor of this one —
see the Phase 1 brand-directions review for reference if the palette ever
needs revisiting.

## Typography

| Role | Typeface | Weight | Notes |
|---|---|---|---|
| Display / headings (H1–H3) | **Comfortaa** | 700 | Rounded, organic sans. `cyrillic` + `cyrillic-ext` subsets. |
| Body / UI text | **PT Sans** | 400, 700 | Purpose-built full Cyrillic coverage, high legibility at small sizes. |
| Logo wordmark only | Marck Script | 400 | Authentic Cyrillic script — reserved for the logo/hero flourish, never for site body or nav text (legibility drops sharply below ~28px and in long strings). |

Both Comfortaa and PT Sans were verified against Google Fonts' Cyrillic
subset data and rendered directly against Ukrainian-specific glyphs
(і, ї, є, ґ) before being chosen — see the sample sentence used for
verification: *"Сіємо разом: насіння овочів, квітів і зелені для їжі —
ґрунт та щедрий врожай."*

Google Fonts embed (add to `default_head_blocks.xml` in Phase 2):

```html
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;700&family=PT+Sans:wght@400;700&display=swap">
```

## Logo

Files live in `docs/brand/logo/`:

| File | Use |
|---|---|
| `logo-primary.svg` / `.png` | Full lockup (sprout icon + wordmark), transparent background — default header logo |
| `logo-primary-on-bg.svg` / `.png` | Same lockup, pre-composited on the Surface `#F4EDE0` background — for contexts that need a solid backing |
| `icon-only.svg` | Sprout mark alone, transparent — for compact/mobile placements |
| `favicon-512.png`, `favicon-180.png`, `favicon-32.png`, `favicon-16.png` | Favicon / apple-touch-icon sizes, pre-composited on Surface with rounded corners |

**Construction:** the icon is a hand-built vector sprout (stem, two leaves,
seed) and the wordmark text is pre-converted to path outlines (not a live
webfont reference), so the SVG renders identically regardless of which fonts
are installed on the viewing device.

**Usage rules:**
- Minimum clear space around the lockup: half the icon's height, on all sides.
- Minimum width: 120px (below that, use `icon-only.svg` alone instead of shrinking the full lockup).
- Do not recolor the logo outside the approved palette (Primary `#2F5233` is
  the default ink; white-on-Primary is the only approved reverse variant —
  generate it the same way as `build-logo.js` did, by swapping the fill, if needed).
- Do not stretch, skew, or add drop shadows/outlines.

## Tone of voice

- Warm, direct, practical — speak like an experienced Ukrainian gardener
  giving straightforward advice, not a marketing department.
- Prefer concrete claims over hype: state germination rate, sowing depth,
  harvest window — let the specifics sell the product rather than
  superlatives ("найкраще", "унікальне").
- CTAs are short verbs in the imperative/first-person-plural spirit of the
  name itself: *"Обрати насіння"*, *"До каталогу"* — action, not slogan.
- Ukrainian is the only voice for now; no code-switching to Russian or
  English in storefront copy.

## Localization (Phase 5)

- `mageplaza/magento-2-ukrainian-language-pack` is installed via composer and
  registered as the `uk_UA` language package — confirmed core Luma/Blank
  strings (nav, search, sort, breadcrumbs, filters, reviews) render in
  Ukrainian with no hand-rolled overrides needed.
- Theme-introduced strings that go through the translation dictionary (not
  CMS block content, which is already stored as Ukrainian) live in
  `app/design/frontend/Uho/seed-store/i18n/uk_UA.csv`. Currently just the
  featured-categories heading and the "нове"/"розпродаж" badge labels — both
  already Ukrainian-sourced, so the file is an identity mapping today. It
  becomes load-bearing the moment a second locale (e.g. `en_US.csv`) is
  added, mapping these same Ukrainian source strings to their translation.
- A few native Magento strings this theme's `product/list.phtml` override
  necessarily carries forward verbatim from core (e.g. "Regular Price" on a
  struck-through price) aren't in the language pack's coverage — that's a
  gap in the third-party pack, not something to patch in this theme's CSV.
- **Currency:** confirmed `uk_UA` + `UAH` render correctly — thousands
  separator is a space, decimal separator a comma, symbol trails the number
  (e.g. `1 411,00 ₴`), matching Ukrainian convention, via Magento's own
  locale-aware formatting (no theme code needed).
- **Gotcha hit during setup:** `currency/options/base` must stay at the
  *global* scope matching how the catalog's prices are actually entered
  (USD, for the Luma sample data used to test this theme) — setting it to
  UAH at store-view scope desyncs display from the stored price and forces
  Magento through a currency *conversion* using `directory_currency_rate`
  instead of just relabeling the symbol. Only `currency/options/default` and
  `currency/options/allow` were changed at store scope for Default Store
  View; `base` was left alone. A **placeholder** USD→UAH rate (41.5) was
  inserted directly into `directory_currency_rate` so converted prices are
  approximately right for local testing — replace it with a real rate
  (Admin > System > Currency > Rates, or an import) before this matters for
  real money. Проросток's `currency/options/base` was already `UAH` before
  this session (untouched here) — that's fine there since it's a separate
  website meant to hold its own UAH-denominated catalog, not a converted one.

## Open for Phase 2+

- Second store view (e.g. `ru_UA` or `en_US`) is not built yet, but nothing
  here blocks adding one later — palette/typography/logo are locale-agnostic.
- Category-specific accent usage (e.g. does "Насіння овочів" get a different
  highlight tint than "Насіння квітів") is not decided — revisit in Phase 4
  when the catalog attribute layout is designed.
