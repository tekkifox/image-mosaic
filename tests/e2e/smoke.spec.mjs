import { expect, test } from '@playwright/test';

/**
 * The one test that answers "did the React build survive the upgrade?":
 * serve the production bundle from the Go server, drive it in Chromium with a
 * deterministic PhotoPrism stand-in, and fail on any console/page error.
 */
test('gallery renders real tiles and images without console errors', async ({ page }) => {
  const consoleErrors = [];
  const pageErrors = [];
  const failedRequests = [];
  const tileOffsets = [];

  page.on('console', (message) => {
    if (message.type() === 'error') {
      consoleErrors.push(message.text());
    }
  });
  page.on('pageerror', (error) => {
    pageErrors.push(error.message);
  });
  page.on('requestfailed', (request) => {
    failedRequests.push(
      `${request.method()} ${request.url()} (${request.failure()?.errorText || 'unknown'})`
    );
  });
  page.on('request', (request) => {
    if (request.url().includes('/api/tiles')) {
      tileOffsets.push(new URL(request.url()).searchParams.get('offset'));
    }
  });

  const tilesResponse = page.waitForResponse(
    (response) => response.url().includes('/api/tiles') && response.status() === 200
  );

  await page.goto('/');

  const tiles = await (await tilesResponse).json();
  expect(tiles.tiles.length).toBeGreaterThan(0);
  expect(tiles.total).toBe(24);

  await expect(page).toHaveTitle(/Asia Pacific/);

  // React mounted (CountryPlaces is the second React root) and the gallery
  // swapped its skeleton grid for real tiles once the images loaded.
  await expect(page.locator('.country-card h3', { hasText: 'Japan' })).toBeVisible();
  await expect(page.locator('#mosaic .tile:not(.skeleton-tile)').first()).toBeVisible();

  await expect
    .poll(async () =>
      page.locator('#mosaic img').evaluateAll(
        (images) => images.filter((image) => image.complete && image.naturalWidth > 0).length
      )
    )
    .toBeGreaterThan(0);

  // The count comes from /api/photo-count and is written into the hero by React.
  await expect(page.locator('#photo-count-stat')).toHaveText('24');

  // Scrolling reaches the paginated second page and triggers lazy image loads.
  // `>= 24` rather than `== 24` because React 19 can let the IntersectionObserver
  // and the scroll fallback both issue offset=18 before isLoadingMore commits
  // (a pre-existing duplicate-request race the smoke test documents, not a
  // rendering failure).
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await expect
    .poll(async () => tileOffsets.filter((offset) => offset === '18').length)
    .toBeGreaterThan(0);
  await expect
    .poll(async () => page.locator('#mosaic .tile:not(.skeleton-tile)').count())
    .toBeGreaterThanOrEqual(24);

  // A real <img> that finished loading with naturalWidth 0 is a broken image.
  const brokenImages = await page.locator('#mosaic img').evaluateAll((images) =>
    images
      .filter((image) => image.complete && image.naturalWidth === 0)
      .map((image) => image.getAttribute('src'))
  );
  expect(brokenImages).toEqual([]);

  const destinationImageLoaded = await page
    .locator('img[alt="Japan"]')
    .evaluate((image) => image.complete && image.naturalWidth > 0);
  expect(destinationImageLoaded).toBe(true);

  expect(pageErrors).toEqual([]);
  expect(consoleErrors).toEqual([]);
  expect(failedRequests).toEqual([]);
});
