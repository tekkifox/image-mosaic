# Deployment Guide: Image Mosaic Gallery

This guide covers building, pushing to GitHub Container Registry, and deploying Image Mosaic Gallery.

## Table of Contents

1. [GitHub Actions Setup](#github-actions-setup)
2. [Manual Docker Build & Push](#manual-docker-build--push)
3. [Docker Compose Deployment](#docker-compose-deployment)
4. [Production Deployment](#production-deployment)

---

## GitHub Actions Setup

### No Configuration Needed! ✨

The GitHub Actions workflow is automatically configured to use GitHub Container Registry (GHCR). Everything works out of the box!

The workflow uses:
- **Registry:** `ghcr.io` (GitHub Container Registry)
- **Authentication:** Built-in `GITHUB_TOKEN` (no secrets needed!)
- **Automatic triggers:** Push to branches or create tags

### Workflow Details

**Triggered on:**
- Push to `main` or `develop` branch
- Create git tags (`v*`)
- Manual workflow dispatch
- Pull requests (build only, no push)

**Auto-generated tags:**
- `latest` - for main branch
- `main` - for main branch commits
- `develop` - for develop branch commits
- `v1.0.0` - for version tags
- `sha-abc123` - for commit hash

### Push to Trigger Build

```bash
git add .
git commit -m "Trigger Docker build"
git push origin main
```

Go to **Actions** tab to watch the build complete in 2-3 minutes.

### View Your Image

1. Go to your GitHub repository
2. Click **Packages** (right sidebar)
3. Click **image-mosaic** container
4. See all available tags

Or visit directly:
```
https://github.com/your-username/image-mosaic/pkgs/container/image-mosaic
```

---

## Manual Docker Build & Push

### 1. Build Locally

```bash
docker build -t image-mosaic:latest .
```

### 2. Tag for GitHub Container Registry

```bash
docker tag image-mosaic:latest ghcr.io/your-username/image-mosaic:latest
docker tag image-mosaic:latest ghcr.io/your-username/image-mosaic:v1.0.0
```

### 3. Create GitHub Personal Access Token

1. Go to https://github.com/settings/tokens?type=beta
2. Click **Generate new token**
3. Name: `ghcr-push`
4. Select scopes:
   - `write:packages`
   - `read:packages`
   - `delete:packages`
5. Click **Generate token**
6. **Copy the token immediately** (you won't see it again!)

### 4. Log into GitHub Container Registry

```bash
echo YOUR_TOKEN | docker login ghcr.io -u your-username --password-stdin
```

### 5. Push to GHCR

```bash
docker push ghcr.io/your-username/image-mosaic:latest
docker push ghcr.io/your-username/image-mosaic:v1.0.0
```

### Verify Push

Visit `https://github.com/your-username/image-mosaic/pkgs/container/image-mosaic` to see your image.

---

## Docker Compose Deployment

### 1. Create .env file

```bash
cp .env.example .env
```

Edit `.env` and add your PhotoPrism configuration:

```env
PHOTO_PRISM_BASE_URL=https://your-photoprism.com
PHOTO_PRISM_API_KEY=your-api-key
```

### 2. Local Development

Build and run locally:

```bash
docker-compose up -d
```

Access at: `http://localhost:8080`

View logs:

```bash
docker-compose logs -f image-mosaic
```

Stop services:

```bash
docker-compose down
```

### 3. Production Deployment

```bash
# Pull latest image from Docker Hub
docker-compose pull

# Start in production
docker-compose up -d

# View status
docker-compose ps

# View logs
docker-compose logs
```

---

## Production Deployment

### Using Docker Directly

First, authenticate with GitHub Container Registry:

```bash
echo YOUR_GITHUB_TOKEN | docker login ghcr.io -u your-username --password-stdin
```

Then run the container:

```bash
docker run -d \
  --name image-mosaic \
  --restart always \
  -p 8080:8080 \
  -e PHOTO_PRISM_BASE_URL="https://your-photoprism.com" \
  -e PHOTO_PRISM_API_KEY="your-api-key" \
  -v image-mosaic-cache:/app/public/cache \
  -v /etc/letsencrypt:/etc/letsencrypt:ro \
  --memory=1g \
  --cpus=2 \
  ghcr.io/your-username/image-mosaic:latest
```

### Behind Nginx Reverse Proxy

**Nginx config example:**

```nginx
upstream image-mosaic {
    server 127.0.0.1:8080;
}

server {
    listen 443 ssl http2;
    server_name gallery.example.com;

    ssl_certificate /etc/letsencrypt/live/gallery.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gallery.example.com/privkey.pem;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        proxy_pass http://image-mosaic;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### With Docker Swarm

```bash
# Authenticate with GitHub Container Registry
echo YOUR_GITHUB_TOKEN | docker login ghcr.io -u your-username --password-stdin

# Pull image
docker pull ghcr.io/your-username/image-mosaic:latest

# Deploy service
docker service create \
  --name image-mosaic \
  --publish 8080:8080 \
  --env PHOTO_PRISM_BASE_URL="https://your-photoprism.com" \
  --env PHOTO_PRISM_API_KEY="your-api-key" \
  --mount type=volume,source=mosaic-cache,target=/app/public/cache \
  --limit-memory=1g \
  ghcr.io/your-username/image-mosaic:latest
```

### With Kubernetes

**deployment.yaml:**

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: image-mosaic
spec:
  replicas: 2
  selector:
    matchLabels:
      app: image-mosaic
  template:
    metadata:
      labels:
        app: image-mosaic
    spec:
      imagePullSecrets:
      - name: ghcr-secret
      containers:
      - name: image-mosaic
        image: ghcr.io/your-username/image-mosaic:latest
        imagePullPolicy: Always
        ports:
        - containerPort: 8080
        env:
        - name: PHOTO_PRISM_BASE_URL
          valueFrom:
            configMapKeyRef:
              name: image-mosaic-config
              key: photoprism-url
        - name: PHOTO_PRISM_API_KEY
          valueFrom:
            secretKeyRef:
              name: image-mosaic-secrets
              key: api-key
        volumeMounts:
        - name: cache
          mountPath: /app/public/cache
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "1Gi"
            cpu: "1000m"
        livenessProbe:
          httpGet:
            path: /api.php?action=photo-count
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
      volumes:
      - name: cache
        persistentVolumeClaim:
          claimName: image-mosaic-cache
---
apiVersion: v1
kind: Service
metadata:
  name: image-mosaic-service
spec:
  selector:
    app: image-mosaic
  ports:
  - protocol: TCP
    port: 80
    targetPort: 8080
  type: LoadBalancer
```

Create secret for GitHub Container Registry access:

```bash
kubectl create secret docker-registry ghcr-secret \
  --docker-server=ghcr.io \
  --docker-username=your-username \
  --docker-password=YOUR_GITHUB_TOKEN \
  --docker-email=your-email@example.com
```

Deploy:

```bash
kubectl apply -f deployment.yaml
```

---

## Monitoring

### Health Check

```bash
curl http://localhost:8080/api.php?action=photo-count
```

### Docker Stats

```bash
docker stats image-mosaic
```

### Container Logs

```bash
docker logs -f image-mosaic
```

### Performance Metrics

```bash
docker exec image-mosaic curl -s http://localhost:8080/api.php?action=photo-count
```

---

## Troubleshooting

### Image won't start

```bash
# Check logs
docker logs image-mosaic

# Verify environment variables
docker inspect image-mosaic | grep Env

# Test PhotoPrism connection
docker exec image-mosaic curl -v https://your-photoprism.com/api/v1/albums
```

### Can't pull image from GHCR

```bash
# Authenticate with GitHub Container Registry
echo YOUR_GITHUB_TOKEN | docker login ghcr.io -u your-username --password-stdin

# Then pull
docker pull ghcr.io/your-username/image-mosaic:latest
```

Make sure your GitHub token has `read:packages` scope.

### API returns 404

Check PhotoPrism is reachable:

```bash
docker exec image-mosaic \
  curl -v https://your-photoprism.com/api/v1/albums \
  -H "X-API-Key: your-api-key"
```

### Cache not persisting

Verify volume mount:

```bash
docker inspect image-mosaic | grep -A 10 Mounts
```

### High memory usage

Reduce PHP-FPM pool size in `docker/php-fpm.conf`:

```ini
pm.max_children = 5
pm.start_servers = 2
```

Then rebuild:

```bash
docker build -t your-username/image-mosaic:latest .
docker push your-username/image-mosaic:latest
```

---

## Updates

### Pull Latest Image

```bash
docker pull your-username/image-mosaic:latest
docker stop image-mosaic
docker rm image-mosaic
docker run -d ... your-username/image-mosaic:latest
```

### Or with Docker Compose

```bash
docker-compose pull
docker-compose up -d
```

---

## Security Checklist

- ✅ Use HTTPS in production
- ✅ Store API keys in secrets management, not .env
- ✅ Set memory limits (`--memory=1g`)
- ✅ Enable health checks
- ✅ Use read-only mounts where possible
- ✅ Keep image updated regularly
- ✅ Run security scans (Trivy)
- ✅ Use non-root user (www-data)

---

## CI/CD Pipeline Summary

```
Git Push / Create Tag
  ↓
GitHub Actions (Automatic - no setup needed!)
  ├─ Build Docker Image
  ├─ Run Trivy Security Scan
  ├─ Push to GitHub Container Registry
  └─ Tag with version/branch
  ↓
GitHub Container Registry (ghcr.io)
  ├─ image-mosaic:latest
  ├─ image-mosaic:main
  ├─ image-mosaic:v1.0.0
  └─ image-mosaic:sha-abc123
  ↓
Production Deployment
  ├─ Authenticate with GitHub token
  ├─ Pull latest image
  ├─ Stop old container
  └─ Start new container
```

---

## Support

For issues:
1. Check logs: `docker logs image-mosaic`
2. Review [DOCKER.md](DOCKER.md)
3. Check [GitHub Issues](https://github.com/your-username/image-mosaic/issues)
