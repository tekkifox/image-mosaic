# Design system

This document describes the visual language of `image-mosaic` and how to extend
it without inventing values. The same values were in the stylesheet before the
token layer existed; tokenising them was a refactor, not a redesign.

## Ground rules

- **`src/styles/tokens.css` is the only place values live.** Every colour,
  fixed length, font size, radius, shadow, transition and z-index comes from
  there.
- **Component styles consume tokens.** `src/styles/index.css` may contain
  `var(--…)`, layout structure (grid/flex), percentages, viewport units and
  unitless numbers, but no raw colours and no fixed `px` values.
- **No visual change without a reviewed screenshot diff.** The palette below is
  the current render. Changing a token changes the product.
- **The guard enforces the mechanical part:** `node scripts/check-tokens.mjs`
  fails on raw colours (hex, `rgb()`/`hsl()`, named colours) and fixed
  `px` values outside the token file. CI runs it in `.github/workflows/design-tokens.yml`.

## Which stylesheet is load-bearing

`public/index.html` links `/styles.min.css`. Webpack compiles
`src/styles/index.css` (which imports `tokens.css`) into that file; the Go
server embeds the built `dist/` output. That is the only stylesheet that styles
the app.

The old `public/styles.css` was a drifted duplicate and nothing loaded it:
`index.html` never referenced it, and the service worker that listed it is not
registered (`src/index.jsx` has registration commented out). It has been
deleted. The inert `/styles.css` entry remains in `src/service-worker.js` for a
future owner of that file to remove when offline support is revived.

## Palette

Colours are named by role, not by hue. Several roles intentionally share a
value; they are separate tokens so one role can change without dragging the
other with it.

### Backgrounds

| Token | Value | Role |
| --- | --- | --- |
| `--colour-bg-base` | `#0a0e27` | Page background, gradient start |
| `--colour-bg-base-end` | `#1a1a2e` | Page background, gradient end |
| `--colour-bg-chrome` | `rgba(15, 15, 35, 0.95)` | Sticky navigation bar |
| `--colour-bg-footer` | `rgba(15, 15, 35, 0.8)` | Footer |
| `--colour-bg-hero-start` | `#1a237e` | Hero gradient start |
| `--colour-bg-hero-end` | `#283593` | Hero gradient end |

### Surfaces

| Token | Value | Role |
| --- | --- | --- |
| `--colour-surface-raised` | `rgba(255, 255, 255, 0.04)` | Country cards |
| `--colour-surface-sunken` | `rgba(255, 255, 255, 0.02)` | Gallery section shell |
| `--colour-surface-stat` | `rgba(255, 255, 255, 0.05)` | Hero stat cards |
| `--colour-surface-base` | `#222222` | Tiles, skeletons, info panel |
| `--colour-surface-inset` | `#333333` | Skeleton gradient, info list rows |
| `--colour-surface-contrast` | `#ffffff` | Scattered tile backing |

### Text

| Token | Value | Role |
| --- | --- | --- |
| `--colour-text-primary` | `#e0e0e0` | Body copy and navigation |
| `--colour-text-secondary` | `#b0bec5` | Hero lead, section copy, empty state |
| `--colour-text-muted` | `#90caf9` | Labels, stat captions, footer |
| `--colour-text-body` | `#cfd8dc` | Destination descriptions |
| `--colour-text-strong` | `#ffffff` | Text on dark overlays |
| `--colour-text-soft` | `#eeeeee` | Info panel heading |
| `--colour-text-dim` | `#cccccc` | Info panel subheading |
| `--colour-text-faint` | `#bbbbbb` | Info panel body |

`--colour-text-strong` and `--colour-surface-contrast` are both `#ffffff`;
one is ink, the other is a surface.

### Accents

| Token | Value | Role |
| --- | --- | --- |
| `--colour-accent-primary` | `#64b5f6` | Links, headings, focus accent |
| `--colour-accent-secondary` | `#81c784` | Country names, hero gradient end, ticks |
| `--colour-accent-wash` | `rgba(100, 181, 246, 0.08)` | Reserved for accent-tinted panels |
| `--colour-accent-tint` | `rgba(100, 181, 246, 0.1)` | Nav hover, metadata pills |
| `--colour-accent-border` | `rgba(100, 181, 246, 0.2)` | Card borders |
| `--colour-accent-border-strong` | `rgba(100, 181, 246, 0.3)` | Metadata pill borders |

### Borders and translucent fills

| Token | Value | Role |
| --- | --- | --- |
| `--colour-border-subtle` | `rgba(255, 255, 255, 0.1)` | Hairline dividers |
| `--colour-border-strong` | `#444444` | Info panel heading divider |
| `--colour-border-contrast` | `rgba(255, 255, 255, 0.25)` | Album pill border |
| `--colour-fill-contrast` | `rgba(255, 255, 255, 0.15)` | Album pill fill |

### Overlays, scrims and shadow tints

| Token | Value | Role |
| --- | --- | --- |
| `--colour-overlay-scrim` | `rgba(0, 0, 0, 0.85)` | Lightbox backdrop |
| `--colour-overlay-panel` | `rgba(0, 0, 0, 0.5)` | Close button, caption |
| `--colour-overlay-panel-strong` | `rgba(0, 0, 0, 0.6)` | Control hover, tile info button |
| `--colour-overlay-panel-soft` | `rgba(0, 0, 0, 0.4)` | Lightbox arrows at rest |
| `--colour-shadow-soft` | `rgba(0, 0, 0, 0.2)` | Card shadow |
| `--colour-shadow-tile` | `rgba(0, 0, 0, 0.35)` | Tile hover shadow |
| `--colour-shadow-image` | `rgba(0, 0, 0, 0.5)` | Destination image, caption shadow |
| `--colour-shadow-overlay` | `rgba(0, 0, 0, 0.6)` | Lightbox image/info panel shadow |

### Decorative

| Token | Value | Role |
| --- | --- | --- |
| `--colour-sheen-highlight` | `rgba(255, 255, 255, 0.05)` | Scattered tile sheen |
| `--colour-sheen-shadow` | `rgba(0, 0, 0, 0.02)` | Scattered tile sheen tail |

The hero's SVG grid line (`rgba(255, 255, 255, 0.03)`) is embedded in a
`url()` data URI and cannot consume a custom property. It is the one deliberate
colour literal in the stylesheet besides `tokens.css`; the guard skips
`url()` contents.

## Spacing

Text-relative spacing uses a rem ladder. The steps are the values the layout
already used, not a rounded 4/8-point grid, so they are preserved exactly.

| Token | Value | Typical use |
| --- | --- | --- |
| `--space-0-25` | `0.25rem` | Tight heading-to-copy gap |
| `--space-0-4` | `0.4rem` | Skeleton title gap |
| `--space-0-5` | `0.5rem` | Icon/label gaps, nav padding |
| `--space-0-65` | `0.65rem` | Place-list row gap |
| `--space-0-75` | `0.75rem` | Section heading gap, pill padding |
| `--space-0-85` | `0.85rem` | Loading list gap |
| `--space-1` | `1rem` | Standard block gap |
| `--space-1-1` | `1.1rem` | Place bullet inset |
| `--space-1-25` | `1.25rem` | Card padding and grid gap |
| `--space-1-5` | `1.5rem` | Stat padding, section gaps |
| `--space-2` | `2rem` | Large gaps |
| `--space-3` | `3rem` | Section rhythm |

Fixed lengths use `--size-<px>` (for example `--size-20` is `20px`). The name
is the value so a review can verify at a glance that nothing moved. The same
scale covers layout maxima (`--size-1200`, `--size-1400`), media frames and
component dimensions.

## Radii, borders and shadows

| Token | Value | Role |
| --- | --- | --- |
| `--radius-xs` | `4px` | Links, small controls, lightbox image |
| `--radius-sm` | `6px` | Caption |
| `--radius-md` | `8px` | Stat card, info panel |
| `--radius-lg` | `12px` | Destination image, gallery shell |
| `--radius-xl` | `16px` | Country card, album pill |
| `--radius-2xl` | `20px` | Metadata pill |
| `--radius-pill` | `999px` | Skeleton lines |
| `--radius-circle` | `50%` | Round icon buttons |

`--border-width-hairline` is `1px`; `--border-width-accent` is `2px` (hero
underline).

Shadows are composite tokens: `--shadow-card`, `--shadow-image`,
`--shadow-tile-hover`, `--shadow-overlay-image` and `--shadow-caption`.

## Typography

| Token | Value | Role |
| --- | --- | --- |
| `--font-family-base` | `'Segoe UI', Tahoma, Geneva, Verdana, sans-serif` | Everything |
| `--font-size-display` | `3.5rem` | Hero title |
| `--font-size-display-mobile` | `2rem` | Hero title below 768px |
| `--font-size-heading` | `2.5rem` | Section and destination headings |
| `--font-size-heading-mobile` | `1.8rem` | Destination headings below 768px |
| `--font-size-stat` | `2rem` | Stat numbers |
| `--font-size-lead` | `1.3rem` | Hero lead, country names |
| `--font-size-small` | `0.95rem` | Navigation, metadata, card captions |
| `--font-size-label` | `0.9rem` | Stat labels |
| `--font-size-bullet` | `1.1rem` | Place-list bullet |
| `--font-size-control` | `14px` | Tile info button |
| `--font-size-caption` | `13px` | Lightbox caption |
| `--font-size-pill` | `12px` | Album pill |
| `--font-size-icon` | `24px` | Lightbox close icon |
| `--font-size-info-heading` | `1.5em` | Info panel heading |
| `--font-size-info-body` | `0.9em` | Info panel body |

Weight: `--font-weight-bold` (`700`).
Line heights: `--line-height-base` (`1.6`), `--line-height-description`
(`1.8`), `--line-height-caption` (`1.4`), `--line-height-flat` (`1`).

## Motion, blur and stacking

- Transitions: `--transition-colour`, `--transition-tile`,
  `--transition-tile-scattered`, `--transition-control`, `--transition-fade`.
- Skeleton animation: `--animation-skeleton` (`skeleton-loading 1.5s infinite`).
- Blur: `--blur-chrome` (`10px`, sticky nav), `--blur-caption` (`4px`).
- z-index, low to high: `--z-raised` (1), `--z-tile-sheen` (2),
  `--z-caption` (3), `--z-tile-control` (10), `--z-nav` (100),
  `--z-lightbox-control` (1000), `--z-lightbox-close` (1001),
  `--z-lightbox` (9999).

The only breakpoint is `768px` (tablet and below). CSS custom properties are
not valid in `@media` preludes, so that number stays literal; the guard does not
inspect at-rule preludes.

## Adding a component

1. **Look for an existing token.** Colours and spacing almost certainly exist.
   Reuse the role, not a near match.
2. **If a value is genuinely new, add a token to `src/styles/tokens.css`** and
   add its role to this document in the same pull request. Name colours by
   role (`--colour-surface-…`, `--colour-text-…`), never by hue or shade.
3. **Consume it with `var(--…)`.** Layout (grid/flex, percentages, viewport
   units) is still written inline in the rule; do not tokenise structure.
4. **Keep declarations on one line each, four-space indent**, grouped with the
   component it belongs to. Put hover/focus states in the State section.
5. **Run `node scripts/check-tokens.mjs`.** It must pass.
6. **Prove no visual drift** for refactors: screenshot and computed-style
   comparison of the affected pages before and after.

Do not introduce `rgb()`/`hsl()` in component files, even for a one-off. Do not
convert `px` to rem to "clean up" a value: that changes the render when the
user's root font size differs.
