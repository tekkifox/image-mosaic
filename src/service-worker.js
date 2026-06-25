/**
 * Service Worker for offline caching and performance optimization
 * Caches images and API responses for offline support and faster loading
 */

const CACHE_VERSION = 'v1';
const CACHE_NAME = `image-mosaic-${CACHE_VERSION}`;
const IMAGE_CACHE_NAME = `image-mosaic-images-${CACHE_VERSION}`;
const API_CACHE_NAME = `image-mosaic-api-${CACHE_VERSION}`;

// Assets to cache on install
const CRITICAL_ASSETS = [
  '/',
  '/index.php',
  '/dist/gallery.min.js',
  '/dist/gallery.min.css',
  '/dist/styles.min.css',
];

/**
 * Install event - cache critical assets
 */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[SW] Caching critical assets');
      return cache.addAll(CRITICAL_ASSETS).catch((err) => {
        console.warn('[SW] Failed to cache some critical assets:', err);
      });
    }).then(() => {
      console.log('[SW] Installed successfully');
      return self.skipWaiting(); // Activate immediately
    })
  );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((name) => {
          if (!name.includes(CACHE_VERSION)) {
            console.log('[SW] Deleting old cache:', name);
            return caches.delete(name);
          }
        })
      );
    }).then(() => {
      console.log('[SW] Activated successfully');
      return self.clients.claim();
    })
  );
});

/**
 * Fetch event - intercept requests and serve from cache
 */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Skip cross-origin requests
  if (url.origin !== self.location.origin) {
    return;
  }

  // Cache strategy based on request type
  if (url.pathname.includes('/api.php?action=cache')) {
    // Image cache endpoint - cache-first with network fallback
    event.respondWith(cacheImageRequest(request));
  } else if (url.pathname.includes('/api.php')) {
    // Other API requests - network-first with cache fallback
    event.respondWith(cacheApiRequest(request));
  } else {
    // Static assets - cache-first
    event.respondWith(cacheStaticRequest(request));
  }
});

/**
 * Cache image requests (cache-first strategy)
 */
function cacheImageRequest(request) {
  return caches.open(IMAGE_CACHE_NAME).then((cache) => {
    return cache.match(request).then((response) => {
      if (response) {
        console.log('[SW] Image served from cache:', request.url);
        return response;
      }

      // Not in cache, fetch from network
      return fetch(request).then((response) => {
        // Cache successful responses
        if (response && response.status === 200) {
          const responseToCache = response.clone();
          cache.put(request, responseToCache);
          console.log('[SW] Image cached:', request.url);
        }
        return response;
      }).catch((err) => {
        console.warn('[SW] Failed to fetch image:', request.url, err);
        // Return blank image if offline and not cached
        return createBlankImage();
      });
    });
  });
}

/**
 * Cache API requests (network-first strategy)
 */
function cacheApiRequest(request) {
  return fetch(request)
    .then((response) => {
      if (response && response.status === 200) {
        const responseToCache = response.clone();
        caches.open(API_CACHE_NAME).then((cache) => {
          cache.put(request, responseToCache);
        });
      }
      return response;
    })
    .catch((err) => {
      console.warn('[SW] Network error, trying cache:', request.url, err);
      return caches.open(API_CACHE_NAME).then((cache) => {
        return cache.match(request).then((response) => {
          if (response) {
            console.log('[SW] API response served from cache:', request.url);
            return response;
          }
          throw new Error('No cached response available');
        });
      }).catch(() => {
        return new Response(
          JSON.stringify({ error: 'Offline - API not available' }),
          { status: 503, headers: { 'Content-Type': 'application/json' } }
        );
      });
    });
}

/**
 * Cache static assets (cache-first strategy)
 */
function cacheStaticRequest(request) {
  return caches.open(CACHE_NAME).then((cache) => {
    return cache.match(request).then((response) => {
      if (response) {
        console.log('[SW] Static asset served from cache:', request.url);
        return response;
      }

      // Not in cache, fetch from network
      return fetch(request).then((response) => {
        if (response && response.status === 200) {
          const responseToCache = response.clone();
          cache.put(request, responseToCache);
        }
        return response;
      }).catch((err) => {
        console.warn('[SW] Failed to fetch static asset:', request.url, err);
        throw err;
      });
    });
  });
}

/**
 * Create a blank 1x1 transparent PNG for offline fallback
 */
function createBlankImage() {
  const png = new Uint8Array([
    0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d,
    0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
    0x08, 0x06, 0x00, 0x00, 0x00, 0x1f, 0x15, 0xc4, 0x89, 0x00, 0x00, 0x00,
    0x0a, 0x49, 0x44, 0x41, 0x54, 0x78, 0x9c, 0x63, 0x00, 0x01, 0x00, 0x00,
    0x05, 0x00, 0x01, 0x0d, 0x0a, 0x2d, 0xb4, 0x00, 0x00, 0x00, 0x00, 0x49,
    0x45, 0x4e, 0x44, 0xae, 0x42, 0x60, 0x82,
  ]);

  return new Response(png, {
    headers: { 'Content-Type': 'image/png' },
  });
}

/**
 * Message handler for cache management from client
 */
self.addEventListener('message', (event) => {
  const { type, url } = event.data;

  if (type === 'CACHE_IMAGE') {
    // Proactively cache an image
    caches.open(IMAGE_CACHE_NAME).then((cache) => {
      fetch(url)
        .then((response) => {
          if (response && response.status === 200) {
            cache.put(url, response.clone());
            console.log('[SW] Image precached:', url);
            event.ports[0].postMessage({ status: 'cached' });
          }
        })
        .catch(() => {
          event.ports[0].postMessage({ status: 'error' });
        });
    });
  } else if (type === 'CLEAR_CACHE') {
    // Clear all caches
    caches.keys().then((names) => {
      Promise.all(names.map((name) => caches.delete(name))).then(() => {
        console.log('[SW] All caches cleared');
        event.ports[0].postMessage({ status: 'cleared' });
      });
    });
  }
});

console.log('[SW] Service Worker loaded');
