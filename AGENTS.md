# Agent notes

## Design system

- All visual values live in `src/styles/tokens.css`. Component CSS must consume
  them with `var(--…)`; do not add raw colours (`#fff`, `rgb()`, `hsl()`,
  `white`) or fixed `px` values anywhere else.
- If the design genuinely needs a new value, add a role-named token and document
  it in `DESIGN.md` in the same change. Do not "round" an existing value or
  convert `px` to `rem` as a tidy-up; both move the render.
- Run `node scripts/check-tokens.mjs` after touching any stylesheet. It must
  pass. CI runs the same guard in `.github/workflows/design-tokens.yml`.
- Refactors of the stylesheet need before/after full-page screenshots and a
  computed-style comparison. New components need a visual check.
