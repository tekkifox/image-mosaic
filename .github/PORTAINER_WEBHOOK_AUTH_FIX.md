# Fix: Webhook Deployment Auth Issues

Portainer webhooks don't use the registry credentials you set in Admin → Registries.

Solution: Embed authentication directly in Docker Compose using `.dockerconfigjson` secret.

## Quick Fix (5 minutes)

### Step 1: Create Base64 Credentials

```bash
# Replace with your values
GITHUB_USER="your-github-username"
GITHUB_TOKEN="your-github-personal-access-token"

# Create base64 encoded credentials
AUTH=$(echo -n "$GITHUB_USER:$GITHUB_TOKEN" | base64)

# Output will look like: dXNlcm5hbWU6dG9rZW4=
echo $AUTH
```

Copy the output (the base64 string).

### Step 2: Create Docker Config Secret

In Portainer:

1. Go to **Secrets**
2. Click **Add secret**
3. Name: `dockerconfigjson`
4. Content: Paste this JSON (replace AUTH_VALUE with your base64 string from Step 1):

```json
{
  "auths": {
    "ghcr.io": {
      "auth": "AUTH_VALUE"
    }
  }
}
```

Example with actual values:

```json
{
  "auths": {
    "ghcr.io": {
      "auth": "dXNlcm5hbWU6dG9rZW4="
    }
  }
}
```

5. Click **Add secret**

### Step 3: Update Docker Compose Stack

In Portainer Stacks, update your `image-mosaic` stack to this:

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

secrets:
  dockerconfigjson:
    external: true
```

### Step 4: Test

1. Click **Recreate** to test
2. Should pull successfully

Now the webhook will also work because auth is embedded in the stack definition.

## Why This Works

When Docker Compose has the `dockerconfigjson` secret:
- Docker uses it for authentication
- Applies to all `docker pull` commands
- Persists through webhook redeploys
- Works with manual recreate
- Works with automatic webhook triggers

## Complete Example with Explanation

Here's the full Docker Compose with all parts:

```yaml
version: '3.8'

services:
  image-mosaic:
    # Your image from GHCR (private by default)
    image: ghcr.io/your-username/image-mosaic:latest
    
    # Container name
    container_name: image-mosaic
    
    # Port mapping
    ports:
      - "8080:8080"
    
    # Environment variables
    environment:
      PHOTO_PRISM_BASE_URL: https://your-photoprism.com
      PHOTO_PRISM_API_KEY: your-api-key
    
    # Persistent volume for cache
    volumes:
      - image-mosaic-cache:/app/public/cache
    
    # Auto-restart on failure
    restart: unless-stopped
    
    # Health check (optional but recommended)
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api.php?action=photo-count"]
      interval: 30s
      timeout: 10s
      retries: 3

# Named volume for cache persistence
volumes:
  image-mosaic-cache:
    driver: local

# Reference the auth secret created in Portainer
secrets:
  dockerconfigjson:
    external: true
```

## Verifying It Works

After updating:

### Test Manual Recreate
1. Portainer → Stacks → image-mosaic
2. Click **Recreate** button
3. Should pull without "unauthorized" error

### Test Webhook Trigger
1. Manually trigger webhook:
   ```bash
   curl -X POST "https://your-portainer/api/webhooks/abc123..."
   ```
2. Check Portainer → Stacks → image-mosaic → Logs
3. Should see "Pulling from ghcr.io/..." with no auth errors
4. Container should restart successfully

## Troubleshooting

### "Secret not found" error
- Make sure secret name in Compose matches Portainer secret name
- Should be: `dockerconfigjson`
- Go to Portainer → Secrets to verify it exists

### Still getting "unauthorized"
1. Verify base64 encoding:
   ```bash
   echo -n "username:token" | base64
   ```
2. Verify secret content is valid JSON:
   ```bash
   echo '{"auths": {"ghcr.io": {"auth": "..."}}}' | jq .
   ```
3. Create new secret with correct content

### Webhook still fails but manual Recreate works
1. The webhook might be caching old config
2. Delete and recreate webhook:
   - Portainer → Stacks → image-mosaic → Webhooks
   - Delete webhook
   - Create new webhook
   - Copy new URL to GitHub secret

## Alternative: Make Image Public

If you just want to simplify:

1. GitHub → your repo → Packages → image-mosaic
2. Package settings → Change visibility to **Public**
3. Remove the `secrets` section from Docker Compose
4. Delete the `dockerconfigjson` secret from Portainer

No auth needed, anyone can pull.

## GitHub Token Setup

The `GITHUB_TOKEN` you use needs `read:packages` scope:

1. https://github.com/settings/tokens?type=beta
2. Generate new token
3. Name: `portainer-ghcr`
4. Scope: **read:packages** (only)
5. Copy and use in Step 1

## Security Notes

✅ Secret stored in Portainer (not exposed)
✅ Used only during `docker pull`
✅ Base64 encoded (not plaintext)
✅ Only grants `read:packages` permission

## Next Steps

1. Create GitHub token with `read:packages` scope
2. Encode credentials as base64
3. Create `dockerconfigjson` secret in Portainer
4. Update Docker Compose stack
5. Test manual Recreate
6. Test webhook trigger
7. Done! Auto-deployment now works

See [PORTAINER_DEPLOY_SETUP.md](PORTAINER_DEPLOY_SETUP.md) for webhook setup.
