# Portainer + GHCR Authentication

Fix "unauthorized" error when pulling from GitHub Container Registry (GHCR).

## Problem

```
Error: error from registry: unauthorized unauthorized
```

This happens when:
- Your GHCR image is **private** (default)
- Portainer tries to pull without credentials

## Solution: 3 Options

Choose one:

### Option 1: Make Image Public (Easiest)

No authentication needed, anyone can pull.

**Steps:**

1. Go to GitHub: https://github.com/your-username/image-mosaic/pkgs/container/image-mosaic
2. Click **Package settings** (right side)
3. Scroll to **Danger Zone**
4. Change **Visibility** to **Public**
5. Click **Change visibility**

**Result:** 
- Image is now public
- No auth needed in Portainer
- Anyone can access it

**Best for:** Public projects or when you don't mind sharing

---

### Option 2: Add GHCR Registry to Portainer (Recommended for Private Images)

Add GitHub credentials to Portainer so it can authenticate with GHCR.

#### Step 1: Create GitHub Personal Access Token

1. Go to https://github.com/settings/tokens?type=beta
2. Click **Generate new token**
3. Name: `portainer-ghcr`
4. Select scopes:
   - ✅ `read:packages`
5. Click **Generate token**
6. **Copy the token** (you won't see it again!)

#### Step 2: Add Registry to Portainer

1. Open **Portainer CE** UI
2. Go to **Admin** → **Registries**
3. Click **Add registry**
4. Fill in:
   - **Registry name:** `ghcr`
   - **Registry URL:** `https://ghcr.io`
   - **Registry type:** Select **Custom registry**
   - **Username:** Your GitHub username
   - **Password:** Your personal access token from Step 1
5. Click **Add registry**

#### Step 3: Update Docker Compose Stack

In Portainer, update your stack to specify the registry:

```yaml
version: '3.8'

services:
  image-mosaic:
    image: ghcr.io/your-username/image-mosaic:latest
    container_name: image-mosaic
    ports:
      - "8080:8080"
    environment:
      PHOTO_PRISM_BASE_URL: https://your-photoprism.com
      PHOTO_PRISM_API_KEY: your-key
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

Then redeploy the stack:

1. Go to **Stacks** → **image-mosaic**
2. Click **Recreate** (forces fresh pull with credentials)
3. Should now pull successfully with authentication

**Best for:** Private images, production deployments

---

### Option 3: Use Docker Config Secret (Advanced)

Create a `.dockercfg` secret in Portainer with GHCR credentials.

#### Step 1: Create Docker Config

Create a base64-encoded Docker config:

```bash
# Create config.json with GHCR credentials
cat > /tmp/config.json << 'EOF'
{
  "auths": {
    "ghcr.io": {
      "username": "your-github-username",
      "password": "your-github-token",
      "auth": "base64-encoded-username-and-token"
    }
  }
}
EOF

# Generate base64 encoded auth (username:token)
echo -n "your-github-username:your-github-token" | base64
# Output: eW91ci1naXRodWItdXNlcm5hbWU6eW91ci1naXRodWItdG9rZW4=
```

#### Step 2: Add to Docker Compose

```yaml
services:
  image-mosaic:
    image: ghcr.io/your-username/image-mosaic:latest
    ...

secrets:
  docker_config:
    external: true
```

#### Step 3: Create Secret in Portainer

1. Go to **Secrets**
2. Click **Add secret**
3. Name: `docker_config`
4. Content: Paste the base64-encoded config
5. Click **Add secret**

**Best for:** Complex setups with multiple registries

---

## Which Option to Choose?

| Option | Pros | Cons | Best For |
|---|---|---|---|
| **Make Public** | Easiest setup, no auth needed | Anyone can see/pull image | Public projects |
| **Add Registry** | Secure, works with private images | Extra setup step | Private projects, production |
| **Docker Config** | Fine-grained control | Complex, requires base64 encoding | Advanced setups |

**Recommendation:** Use **Option 2** (Add Registry) for private images + production use.

---

## Verify It's Working

After choosing an option:

1. In Portainer → **Stacks** → **image-mosaic**
2. Click **Recreate** button
3. Wait for container to restart
4. Check **Logs** for successful pull:
   - Should see: `Pulling from ghcr.io/...`
   - Not: `unauthorized` error

---

## Test Manually

Test GHCR pull with your credentials:

```bash
# Create GitHub token (as above)
GITHUB_TOKEN="your-token"
GITHUB_USER="your-username"

# Log into GHCR
echo $GITHUB_TOKEN | docker login ghcr.io -u $GITHUB_USER --password-stdin

# Pull image
docker pull ghcr.io/your-username/image-mosaic:latest

# Should succeed with no errors
```

---

## Troubleshooting

### Still getting "unauthorized" after adding registry

1. Verify token has `read:packages` scope:
   - GitHub → Settings → Developer settings → Personal access tokens
   - Check the token has `read:packages` ✅

2. Verify credentials in Portainer:
   - Admin → Registries → click registry
   - Check username and password are correct
   - Test connection if available

3. Try regenerating token:
   - Delete old token
   - Create new token with `read:packages`
   - Update in Portainer

### "Invalid registry credentials"

1. Check for extra spaces in username/password
2. Verify token hasn't expired
3. Verify GitHub username is correct

### Image still won't pull

1. Verify image exists:
   - GitHub → your repo → Packages
   - Click `image-mosaic` package
   - Should show available tags

2. Verify image name is correct:
   - Should be: `ghcr.io/your-username/image-mosaic:latest`
   - Check spelling, case sensitivity

3. Try public image first:
   - Make image public temporarily
   - Test if it pulls in Portainer
   - If it works, issue is auth
   - If it still fails, issue is image name

---

## Security Notes

✅ Personal access tokens stored securely in Portainer
✅ Use token with minimal scope (`read:packages` only)
✅ Rotate tokens regularly (delete old, create new)
✅ Never commit token to GitHub

---

## GitHub Token Scopes

For GHCR pull only:

```
read:packages      ← Only this needed for pulling
```

For pushing from CI/CD:

```
write:packages
read:packages
delete:packages
```

---

## Making Image Public vs Private

### Public (Option 1)
```
✅ Anyone can pull without auth
✅ Simpler setup
❌ Image is publicly visible
❌ GitHub action logs might show image path
```

### Private (Option 2)
```
✅ Only authenticated users can pull
✅ Keeps image private
❌ Requires credentials in Portainer
❌ More setup
```

Choose based on your privacy needs!

---

## Next Steps

1. Choose one option above
2. Follow the steps
3. Click **Recreate** in Portainer
4. Verify deployment succeeds
5. Check container logs for successful startup

See [PORTAINER_DEPLOY_SETUP.md](PORTAINER_DEPLOY_SETUP.md) for webhook auto-deployment info.
