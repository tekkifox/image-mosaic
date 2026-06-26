# Docker Setup for Image Mosaic Gallery

This guide explains how to run Image Mosaic Gallery in Docker (including Portainer).

## Quick Start

### 1. Build the Docker Image

```bash
docker build -t image-mosaic:latest .
```

### 2. Run the Container

**With environment variables:**

```bash
docker run -d \
  -p 8080:8080 \
  -e PHOTO_PRISM_BASE_URL="https://photoprism.example.com" \
  -e PHOTO_PRISM_API_KEY="your-api-key" \
  -v image-mosaic-cache:/app/public/cache \
  --name image-mosaic \
  image-mosaic:latest
```

**Or with .env file:**

```bash
docker run -d \
  -p 8080:8080 \
  --env-file .env \
  -v image-mosaic-cache:/app/public/cache \
  --name image-mosaic \
  image-mosaic:latest
```

### 3. Access the Gallery

Visit `http://localhost:8080` in your browser.

## Environment Variables

Configure PhotoPrism connection with these environment variables:

```env
# Required
PHOTO_PRISM_BASE_URL=https://photoprism.example.com

# Authentication (choose one method)

# Option 1: API Key
PHOTO_PRISM_API_KEY=your-api-key

# Option 2: Access Token
PHOTO_PRISM_ACCESS_TOKEN=your-access-token

# Option 3: Basic Auth
PHOTO_PRISM_USE_BASIC_AUTH=true
PHOTO_PRISM_USERNAME=your-username
PHOTO_PRISM_PASSWORD=your-password

# Option 4: OAuth (for user authorization)
PHOTO_PRISM_OAUTH_CLIENT_ID=your-client-id
PHOTO_PRISM_OAUTH_CLIENT_SECRET=your-client-secret
```

## Portainer Setup

### Using Portainer UI

1. **Open Portainer** → Navigate to your environment
2. **Containers** → **Add Container**
3. **Name:** `image-mosaic`
4. **Image:** `image-mosaic:latest`
5. **Ports:**
   - Container: `8080`
   - Host: `8080`
6. **Volumes:**
   - Container: `/app/public/cache`
   - Volume: `image-mosaic-cache` (or create new)
7. **Environment Variables:**
   - `PHOTO_PRISM_BASE_URL` = `https://photoprism.example.com`
   - `PHOTO_PRISM_API_KEY` = `your-api-key`
8. **Deploy Container**

### Using Docker Compose with Portainer

Create `docker-compose.yml`:

```yaml
version: '3.8'

services:
  image-mosaic:
    image: image-mosaic:latest
    container_name: image-mosaic
    ports:
      - "8080:8080"
    environment:
      PHOTO_PRISM_BASE_URL: https://photoprism.example.com
      PHOTO_PRISM_API_KEY: your-api-key
    volumes:
      - image-mosaic-cache:/app/public/cache
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api.php?action=photo-count"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 5s

volumes:
  image-mosaic-cache:
    driver: local
```

Then in Portainer:
1. **Stacks** → **Add Stack**
2. Paste the `docker-compose.yml` content
3. **Deploy Stack**

## Docker Images & Performance

### Multi-Stage Build Benefits

- **Stage 1 (Builder):** Builds React bundle with Node.js
- **Stage 2 (Runtime):** PHP+Nginx environment (no Node.js)
- **Final size:** ~350MB (much smaller than including Node.js in final image)

### Layer Caching

```
npm ci        → cached if package.json unchanged
npm run build → runs if dependencies changed
```

## Volumes & Persistence

### Cache Volume

```bash
-v image-mosaic-cache:/app/public/cache
```

**What it stores:**
- API response cache (5-minute TTL)
- Downloaded image cache
- Photo details cache

**Clearing cache:**
```bash
docker exec image-mosaic \
  find /app/public/cache -name ".*" -type f -delete
```

## Networking

### Default Port

- **Container:** 8080 (internal Nginx)
- **Host:** 8080 (or any port you map)

### Behind a Reverse Proxy

If using Traefik, Caddy, or Nginx reverse proxy:

```yaml
services:
  image-mosaic:
    image: image-mosaic:latest
    # Don't expose port 8080 to host
    networks:
      - traefik
    labels:
      - traefik.enable=true
      - traefik.http.routers.mosaic.rule=Host(`gallery.example.com`)
      - traefik.http.services.mosaic.loadbalancer.server.port=8080
```

## Logs & Debugging

### View Container Logs

```bash
docker logs image-mosaic
docker logs -f image-mosaic  # follow logs
```

### Execute Commands in Container

```bash
# Check if API is working
docker exec image-mosaic curl http://localhost:8080/api.php?action=photo-count

# Clear cache
docker exec image-mosaic find /app/public/cache -name ".*" -type f -delete

# Open shell
docker exec -it image-mosaic sh
```

## Performance Tuning

### Increase Worker Processes

Edit `docker/nginx.conf`:
```nginx
worker_processes auto;  # auto scales with CPU count
```

### Adjust PHP-FPM Pool

Edit `docker/php-fpm.conf`:
```ini
pm.max_children = 20      # increase for more concurrent requests
pm.start_servers = 5      # increase for faster response
```

Then rebuild:
```bash
docker build -t image-mosaic:latest .
```

## Health Checks

The container includes a built-in health check:

```bash
curl http://localhost:8080/api.php?action=photo-count
```

Check container health:
```bash
docker ps | grep image-mosaic
# Status will show "healthy" or "unhealthy"
```

## Troubleshooting

### API Returns 404

Verify PhotoPrism connection:
```bash
docker exec image-mosaic curl -v https://photoprism.example.com/api/v1/albums
```

### Cache Issues

Clear cache and restart:
```bash
docker exec image-mosaic find /app/public/cache -delete
docker restart image-mosaic
```

### Memory Issues

Increase memory limit:
```bash
docker run ... --memory=1g --memory-swap=1g ...
```

### Permission Denied on Cache

Rebuild image (permissions are set in Dockerfile):
```bash
docker rmi image-mosaic:latest
docker build -t image-mosaic:latest .
```

## Production Deployment

### Docker Compose with PostgreSQL (future enhancement)

```yaml
version: '3.8'
services:
  image-mosaic:
    image: image-mosaic:latest
    ports:
      - "8080:8080"
    environment:
      PHOTO_PRISM_BASE_URL: https://photoprism.example.com
      PHOTO_PRISM_API_KEY: ${PHOTO_PRISM_API_KEY}
    volumes:
      - cache:/app/public/cache
    restart: always
    depends_on:
      - photoprism
    
  photoprism:
    # PhotoPrism service (optional, if self-hosting)
    image: photoprism/photoprism:latest
    # ... configuration ...

volumes:
  cache:
```

## Security Best Practices

✅ **Enabled in Dockerfile:**
- Non-root PHP-FPM user (www-data)
- Security headers in Nginx
- Function disabling in PHP
- No sensitive files in image

⚠️ **You should also:**
- Use secrets management (Portainer Secrets, Docker Secrets)
- Run behind HTTPS reverse proxy
- Set resource limits (`--memory`, `--cpus`)
- Regular image updates
- Network isolation with custom networks

## License

MIT - Same as Image Mosaic Gallery
