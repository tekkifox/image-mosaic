import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const distDir = path.join(root, 'dist');
const publicDir = path.join(root, 'cmd/api/public');
const binDir = path.join(root, '.e2e');
const binPath = path.join(binDir, 'image-mosaic');
const host = '127.0.0.1';
const proxyPort = Number(process.env.E2E_PORT || 4173);

const PLACES = ['Tokyo', 'Kyoto', 'Osaka'];
const IMAGES = [
  '/images/destinations/japan.jpg',
  '/images/destinations/south-korea.jpg',
  '/images/destinations/hong-kong.jpg',
  '/images/destinations/australia.jpg',
  '/images/destinations/southeast-asia.jpg',
];

const photo = (index) => {
  const place = PLACES[index % PLACES.length];
  const image = IMAGES[index % IMAGES.length];
  return {
    UID: `uid${String(index).padStart(3, '0')}`,
    Hash: `hash${String(index).padStart(3, '0')}`,
    Title: `Smoke photo ${String(index + 1).padStart(2, '0')}`,
    TakenAt: `2016-0${(index % 9) + 1}-15`,
    Caption: `${place} on the trip`,
    Path: `travel/Travelling/${place.toLowerCase()}.jpg`,
    Albums: [{ Title: 'Asia Pacific 2016' }],
    Place: { Label: `${place}, Japan`, Country: 'JP' },
    Thumbs: {
      tile_224: { src: image },
      fit_720: { src: image },
      fit_1280: { src: image },
    },
  };
};

const photos = Array.from({ length: 24 }, (_, index) => photo(index));

function json(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(payload),
  });
  res.end(payload);
}

function startUpstream() {
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, `http://${host}`);
    const pathname = url.pathname;

    if (pathname === '/api/v1/photos/view' || pathname === '/api/v1/photos') {
      const requested = Number(url.searchParams.get('count') || photos.length);
      const count = Number.isFinite(requested) && requested > 0 ? Math.min(requested, photos.length) : photos.length;
      json(res, 200, photos.slice(0, count));
      return;
    }

    if (pathname === '/translate' && req.method === 'POST') {
      let body = '';
      req.setEncoding('utf8');
      req.on('data', (chunk) => {
        body += chunk;
      });
      req.on('end', () => {
        let translatedText = '';
        try {
          translatedText = JSON.parse(body).q || '';
        } catch {
          translatedText = '';
        }
        json(res, 200, { translatedText });
      });
      return;
    }

    json(res, 404, { error: `unexpected upstream request: ${pathname}` });
  });

  return new Promise((resolve) => {
    server.listen(0, host, () => {
      resolve({ server, port: server.address().port });
    });
  });
}

function forward(req, res, port) {
  const headers = { ...req.headers, host: `${host}:${port}` };
  const upstream = http.request(
    { host, port, method: req.method, path: req.url, headers },
    (upstreamResponse) => {
      res.writeHead(upstreamResponse.statusCode || 502, upstreamResponse.headers);
      upstreamResponse.pipe(res);
    }
  );
  upstream.on('error', () => {
    if (!res.headersSent) {
      res.writeHead(502, { 'Content-Type': 'text/plain' });
    }
    res.end('e2e upstream unavailable');
  });
  req.pipe(upstream);
}

async function freePort() {
  const server = net.createServer();
  await new Promise((resolve) => server.listen(0, host, resolve));
  const port = server.address().port;
  await new Promise((resolve) => server.close(resolve));
  return port;
}

async function waitFor(url, timeoutMs = 20_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(url);
      if (response.ok) return;
    } catch {
      // Server not listening yet.
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error(`timed out waiting for ${url}`);
}

function prepareEmbeddedAssets() {
  if (!fs.existsSync(path.join(distDir, 'index.html'))) {
    throw new Error('dist/index.html is missing; run `pnpm run build` before the e2e test');
  }

  fs.mkdirSync(publicDir, { recursive: true });
  for (const entry of fs.readdirSync(publicDir)) {
    if (entry !== '.keep') {
      fs.rmSync(path.join(publicDir, entry), { recursive: true, force: true });
    }
  }
  fs.cpSync(distDir, publicDir, { recursive: true });
}

function buildGoBinary() {
  fs.mkdirSync(binDir, { recursive: true });
  const result = spawnSync('go', ['build', '-o', binPath, './cmd/api'], {
    cwd: root,
    stdio: 'inherit',
    env: process.env,
  });
  if (result.status !== 0) {
    throw new Error('go build failed');
  }
}

async function main() {
  prepareEmbeddedAssets();
  buildGoBinary();

  const { server: upstream, port: upstreamPort } = await startUpstream();
  const goPort = await freePort();
  const proxyOrigin = `http://${host}:${proxyPort}`;

  let goProcess;
  // The browser talks to the proxy on one origin. That keeps PhotoPrism image
  // URLs same-origin so the app's CSP (`img-src 'self'`) is exercised as-is.
  const proxy = http.createServer((req, res) => {
    if (req.url.startsWith('/api/v1/') || req.url.startsWith('/translate')) {
      forward(req, res, upstreamPort);
      return;
    }
    forward(req, res, goPort);
  });

  const shutdown = (code = 0) => {
    proxy.close();
    upstream.close();
    if (goProcess && !goProcess.killed) {
      goProcess.kill('SIGTERM');
    }
    setTimeout(() => process.exit(code), 100).unref();
  };

  process.on('SIGTERM', () => shutdown(0));
  process.on('SIGINT', () => shutdown(0));
  process.on('exit', () => {
    if (goProcess && !goProcess.killed) goProcess.kill('SIGTERM');
  });

  goProcess = spawn(binPath, [], {
    cwd: root,
    env: {
      ...process.env,
      PORT: String(goPort),
      PHOTO_PRISM_BASE_URL: proxyOrigin,
      TRANSLATE_API_URL: `${proxyOrigin}/translate`,
    },
    stdio: ['ignore', 'inherit', 'inherit'],
  });

  goProcess.on('exit', (code) => {
    if (code !== null && code !== 0) {
      console.error(`e2e Go server exited with code ${code}`);
      shutdown(1);
    }
  });

  await waitFor(`http://${host}:${goPort}/api/debug`);

  await new Promise((resolve, reject) => {
    proxy.once('error', reject);
    proxy.listen(proxyPort, host, resolve);
  });

  console.log(`e2e server ready at ${proxyOrigin}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
