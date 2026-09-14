# Image Mosaic

Live demo: https://travelling.rossmoney.me/

PhotoPrism-backed image mosaic app with a privacy-preserving API layer and a client-side gallery.

## Current State
- The app is scoped to the `Travelling` category.
- `api.php` rejects requests without `action` and blocks non-allowed categories.
- Image URLs are mapped to hashes server-side before being exposed to the frontend.
- The gallery is rendered from `index.php` and `public/gallery.js`.
- A service worker caches static assets and API responses.

## Key Files
- `index.php`: Main page and gallery mount point.
- `public/gallery.js`: Frontend gallery logic.
- `api.php`: API entry point for tiles, counts, locations, featured photo, and cache access.
- `photoprism_client.php`: PhotoPrism API client and data translation.
- `image_url_mapper.php`: Maps PhotoPrism image URLs to hashes for privacy.
- `cache_manager.php`: Handles cached image responses.
- `src/service-worker.js`: Offline and response caching.
- `scripts/verify-privacy.php`: Basic privacy verification script.

## Setup
1. Configure `config.php` or environment variables for PhotoPrism access.
2. Ensure PHP is installed and serving the project.
3. Open `index.php` in a browser.

## Notes
- The project is currently tuned for the `Travelling` category only.
- API consumers should use `category=Travelling` for image-facing requests.
