# Using GHCR Image in Portainer

Deploy your GitHub Container Registry image using Portainer UI.

## Prerequisites

- Portainer installed and running
- GitHub Personal Access Token with `read:packages` scope
- Your GHCR image available: `ghcr.io/your-username/image-mosaic`

## Step 1: Create GitHub Personal Access Token

1. Go to https://github.com/settings/tokens?type=beta
2. Click **Generate new token**
3. Name: `portainer-ghcr`
4. Select scopes:
   - ✅ `read:packages`
5. Click **Generate token**
6. **Copy the token** (you won't see it again!)

## Step 2: Add Registry to Portainer

1. Open **Portainer** UI
2. Go to **Admin** → **Registries**
3. Click **Add registry**
4. Fill in:
   - **Registry name:** `ghcr`
   - **Registry URL:** `https://ghcr.io`
   - **Registry type:** `GitHub Container Registry`
   - **Username:** `your-github-username`
   - **Password:** Your GitHub token from Step 1
5. Click **Add registry**

## Step 3: Deploy from Portainer

### Option A: Using UI (Easy)

1. Go to **Containers** → **Add container**
2. In **Image** field, enter:
   ```
   ghcr.io/your-username/image-mosaic:latest
   ```
3. Select registry: `ghcr`
4. **Port mapping:**
   - Container: `8080`
   - Host: `8080` (or your preference)
5. **Environment variables** → Add:
   - `PHOTO_PRISM_BASE_URL` = `https://your-photoprism.com`
   - `PHOTO_PRISM_API_KEY` = `your-api-key`
6. **Volumes** (optional):
   - Container path: `/app/public/cache`
   - Bind: `image-mosaic-cache` (persistent volume)
7. Click **Deploy the container**

### Option B: Using Docker Compose (Advanced)

1. Go to **Stacks**
2. Click **Add stack**
3. Name: `image-mosaic`
4. Paste this Docker Compose:

```yaml
version: '3.8'

services:
  image-mosaic:
    image: ghcr.io/your-username/image-mosaic:latest
    container_name: image-mosaic
    registry: ghcr
    ports:
      - "8080:8080"
    environment:
      PHOTO_PRISM_BASE_URL: https://your-photoprism.com
      PHOTO_PRISM_API_KEY: your-api-key
    volumes:
      - image-mosaic-cache:/app/public/cache
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api.php?action=photo-count"]
      interval: 30s
      timeout: 10s
      retries: 3

volumes:
  image-mosaic-cache:
    driver: local
```

5. Click **Deploy the stack**

## Step 4: Verify Deployment

After clicking Deploy:

1. Check container status: **Containers** tab
2. Look for `image-mosaic` (should show `Running`)
3. Click on it to see logs
4. Open browser: `http://localhost:8080`

## Troubleshooting

### "Image pull failed" Error

**Cause:** Registry authentication failed

**Fix:**
1. Go to **Registries**
2. Click the registry to edit
3. Verify username and token are correct
4. Test token at: https://github.com/settings/tokens?type=beta
5. Recreate registry with correct credentials

### "Image not found"

**Cause:** Image name is incorrect

**Fix:**
1. Check your GitHub username
2. Verify image exists: https://github.com/your-username/image-mosaic/pkgs/container/image-mosaic
3. Use exact URL: `ghcr.io/your-username/image-mosaic:latest`

### Container starts then stops

**Cause:** Missing environment variables or bad configuration

**Fix:**
1. Click container → **Logs**
2. Check error messages
3. Most common: missing `PHOTO_PRISM_BASE_URL`
4. Edit container → Add environment variables
5. Restart container

### Authentication works but slow pulls

**Cause:** First pull downloads full image (~350MB)

**Fix:**
- Wait 5-10 minutes for first deployment
- Subsequent pulls/updates are faster (layer caching)
- Check **Logs** for progress

## Environment Variables Reference

**Required:**
- `PHOTO_PRISM_BASE_URL` - Your PhotoPrism URL

**Choose ONE authentication method:**
- `PHOTO_PRISM_API_KEY` - API key (recommended)
- `PHOTO_PRISM_ACCESS_TOKEN` - Access token
- `PHOTO_PRISM_USERNAME` + `PHOTO_PRISM_PASSWORD` - Basic auth

**Optional:**
- `PHOTO_PRISM_OAUTH_CLIENT_ID` - OAuth client ID
- `PHOTO_PRISM_OAUTH_CLIENT_SECRET` - OAuth secret

## Update Image in Portainer

### When new version is pushed to GHCR:

1. Go to **Containers**
2. Click `image-mosaic`
3. Click **Recreate** (top right)
4. Portainer will pull latest image automatically
5. Container restarts with new version

### Or manually via Docker Compose:

1. Go to **Stacks**
2. Click `image-mosaic`
3. Click **Editor**
4. Change image tag: `latest` → `v1.0.0` (or new tag)
5. Click **Update the stack**

## Image Tags Available

```
ghcr.io/your-username/image-mosaic:latest     # Main branch
ghcr.io/your-username/image-mosaic:main       # Main branch commits
ghcr.io/your-username/image-mosaic:develop    # Develop branch
ghcr.io/your-username/image-mosaic:v1.0.0     # Version tags
ghcr.io/your-username/image-mosaic:sha-abc123 # Commit hash
```

## Health Checks

Portainer will automatically check if container is healthy:

```
GET http://localhost:8080/api.php?action=photo-count
```

If returns 200 → ✅ Healthy

If fails 3 times → Container restarts automatically

## Tips

✅ Always use specific tags in production (`v1.0.0` not `latest`)
✅ Enable health checks for automatic restarts
✅ Set resource limits to prevent runaway processes
✅ Use named volumes for persistent cache data
✅ Keep registry credentials secure (use Portainer's secrets feature)

## Making Image Public (Optional)

If you want anyone to pull without authentication:

1. Go to GitHub: https://github.com/your-username/image-mosaic/pkgs/container/image-mosaic
2. Click **Package settings**
3. Scroll to **Danger Zone**
4. Change **Visibility** to **Public**

Then in Portainer, you don't need registry authentication for this image.

## Support

- Portainer docs: https://docs.portainer.io
- GHCR docs: https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry
- Check [DEPLOYMENT.md](../DEPLOYMENT.md) for more deployment options
