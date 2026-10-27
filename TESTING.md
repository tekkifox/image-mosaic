# Testing

The suite has three layers, all runnable locally and in
`.github/workflows/test.yml`.

```bash
corepack pnpm@12.15.0 install --frozen-lockfile
corepack pnpm@12.15.0 exec playwright install chromium   # once, for e2e
corepack pnpm@12.15.0 test                              # unit + Go + e2e
```

Individual layers:

```bash
pnpm test:unit   # Vitest + jsdom + Testing Library
pnpm test:go     # go test ./...
pnpm test:e2e    # webpack build, Go server, Playwright/Chromium
```

## Frontend (`tests/unit`)

Vitest with jsdom and Testing Library. The tests focus on behaviour that can
regress during a React upgrade:

- `Gallery` requests the first page with the expected category/limit/offset,
  renders the returned tiles, maps `thumbUrl`/`thumb`/`full` fallbacks, and
  writes `/api/photo-count` into the hero stat.
- The skeleton grid is shown while the first page is in flight and disappears
  after a failed request.
- Scrolling near the bottom requests `offset=18` and appends the second page;
  no request is made when `hasMore` is false.
- Clicking a tile opens the lightbox, and arrow/Escape keys navigate/close it.
- `Lightbox` and `Caption` cover keyboard handling, body-scroll locking and
  conditional caption/album rendering.
- `CountryPlaces` covers loading placeholders, country/place rendering, the
  empty state and the request-failure path.

`IntersectionObserver` and `requestAnimationFrame` are stubbed in
`tests/unit/setup.js`; the gallery tests exercise its scroll fallback rather
than pretending to implement layout.

## Go (`cmd/api/main_test.go`)

`httptest` drives the handlers directly. A small in-process fake PhotoPrism
server handles `/api/v1/photos` and `/api/v1/photos/view`, so no external
service or network access is needed.

- `/api/tiles`: happy path (window, pagination, tile metadata, cache headers,
  ETag), missing category (400 JSON), invalid `limit`/`offset` fallbacks,
  empty category (36 placeholder slots) and an unavailable PhotoPrism.
- `/api/photo-count`: category, omitted category, unknown category and
  unavailable PhotoPrism.
- Supporting logic: security headers, base-URL normalization and tile URL
  strategy (viewer thumbnails, tokenized sizes, download fallbacks).

## Browser smoke (`tests/e2e`)

`tests/e2e/server.mjs` copies `dist/` into `cmd/api/public`, builds the Go
binary, starts a fake PhotoPrism and a same-origin reverse proxy, then serves
the embedded production bundle through the real Go server. Playwright loads it
in Chromium and asserts:

- the tile API returned data and React mounted both roots;
- real tiles replace the skeleton and tile images actually load;
- the hero photo count comes from the API;
- scrolling reaches the second page (at least 24 tiles);
- no `console.error`, no page errors and no failed requests.

The proxy exists only so PhotoPrism image URLs stay on the page-origin. That
keeps the application's real `img-src 'self'` CSP in force instead of weakening
it for the test.

## Test-only seam and React 19 build fix

`//go:embed all:public all:docs` plus the tracked `cmd/api/public/.keep` lets
`go test` and `go build` compile on a clean checkout. Previously the embed
pattern matched nothing until the build copied `dist/` into
`cmd/api/public`. In a real build the directory is populated exactly as before;
the only extra embedded file is the ignored `.keep`.

After #28 (React 19 + Babel 8) landed on `main`, the production bundle rendered
nothing: Babel 8's `preset-react` emitted `jsxDEV` calls, and React 19's
production `react-jsx-dev-runtime` exports `jsxDEV` as `undefined`. The smoke
test caught it as a page error. `webpack.config.js` and `.babelrc` now set
`development: false` on `@babel/preset-react`, which emits the normal
`react/jsx-runtime` calls. This is a build configuration fix, not a runtime
behaviour change.

## Known gaps

- The gallery has no distinct empty state: when the API returns no tiles the
  current UI keeps showing the skeleton grid. The test documents that
  behaviour rather than asserting an invented empty screen. In practice the Go
  API fills an empty category with 36 "Empty slot" placeholders.
- On React 19, if the IntersectionObserver trigger and the scroll fallback fire
  together, the gallery can request `offset=18` twice and append the second page
  twice. The unit test covers a single pagination step, and the smoke test
  asserts that pagination happened (`>= 24` tiles) rather than pinning the
  duplicate count. This is a pre-existing `Gallery` race, not a test-suite
  failure.
- `/api/country-places` is covered through the browser smoke test, not by a Go
  unit test, because it calls the translation helper. The e2e server provides a
  local translation endpoint so the test stays offline.
- No visual/CSS assertions. Styles are owned separately.
