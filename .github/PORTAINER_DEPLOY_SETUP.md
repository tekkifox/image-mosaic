# Portainer Auto-Deployment from GitHub

Automatically redeploy your application in Portainer when you push to GitHub.

Works with **Portainer Community Edition (CE)** using webhooks.

## Table of Contents

1. [Quick Setup (2 min)](#quick-setup-2-min)
2. [How It Works](#how-it-works)
3. [Verify Deployment](#verify-deployment)
4. [Troubleshooting](#troubleshooting)

---

## Quick Setup (2 min)

### Step 1: Create Webhook in Portainer

1. Open **Portainer CE** UI
2. Go to **Stacks**
3. Click your `image-mosaic` stack
4. Scroll to **Webhooks** section
5. Click **Create webhook**
6. **Copy the webhook URL** (looks like `https://portainer.example.com/api/webhooks/...`)

### Step 2: Add GitHub Secret

In your GitHub repository:

1. Go to **Settings** → **Secrets and variables** → **Actions**
2. Click **New repository secret**
3. Add this secret:

| Secret Name | Value |
|---|---|
| `PORTAINER_WEBHOOK_URL` | Paste the webhook URL from Step 1 |

### Step 3: Test Deployment

1. Push to GitHub:
   ```bash
   git add .
   git commit -m "Test Portainer deployment"
   git push origin main
   ```

2. Check GitHub Actions:
   - Go to **Actions** tab
   - Look for "Deploy to Portainer" workflow
   - Should complete successfully

3. Check Portainer:
   - Go to **Stacks** → **image-mosaic**
   - Check **Logs** tab for recent activity
   - Container should restart with new image

---

## How It Works

```
Git Push (main/develop/tags)
  ↓
GitHub Actions
  ├─ Build Docker Image
  ├─ Push to GHCR
  └─ Call Portainer Webhook
  ↓
Portainer (via webhook)
  ├─ Receives redeploy signal
  ├─ Pulls latest image from GHCR
  ├─ Stops old container
  └─ Starts new container
```

### Workflow File

See `.github/workflows/portainer-deploy.yml`

Triggers on:
- Push to `main` branch
- Push to `develop` branch
- Create git tags (v1.0.0, v2.0.0, etc)
- Manual workflow dispatch

### Portainer Webhook Behavior

When the webhook is called, Portainer automatically:
1. **Pulls** the latest image (forces fresh pull from GHCR)
2. **Stops** the old container
3. **Starts** new container with latest image
4. **Health checks** verify it's working

No manual steps needed!

### Manual Deployment

To redeploy without pushing code:

1. Go to GitHub **Actions** tab
2. Select **Deploy to Portainer** workflow
3. Click **Run workflow** button
4. Click **Run workflow** again

Stack redeploys in ~30 seconds!

---

## Verify Deployment

### Check Portainer Logs

1. Go to Portainer **Stacks**
2. Click `image-mosaic` stack
3. Click **Logs** tab
4. Should see recent activity (e.g., "Pulling image...", "Container created...", "Container started...")

### Check GitHub Actions

1. Go to **Actions** tab
2. Click latest "Deploy to Portainer" run
3. Expand **Trigger Portainer Webhook** step
4. Should see "✅ Webhook triggered successfully"

### Test Application

```bash
# Check container is running
curl http://localhost:8080/api.php?action=photo-count

# Should return JSON:
# {"count": 10000, "debug_info": {...}}
```

---

## Troubleshooting

### Workflow Fails: "Webhook URL not configured"

**Cause:** GitHub secret not added

**Fix:**
1. Go to **Settings** → **Secrets and variables** → **Actions**
2. Verify secret exists: `PORTAINER_WEBHOOK_URL`
3. Check value is correct (no extra spaces)
4. Should be the full webhook URL from Portainer

### Webhook URL is missing

**Cause:** Webhook not created in Portainer

**Fix:**
1. Go to Portainer → **Stacks** → **image-mosaic**
2. Scroll to **Webhooks** section
3. Click **Create webhook**
4. Copy the URL
5. Add to GitHub secret: `PORTAINER_WEBHOOK_URL`

### Webhook returns HTTP error (4xx or 5xx)

**Cause:** Invalid webhook URL or Portainer is down

**Fix:**
1. Test webhook URL manually:
   ```bash
   curl -X POST "YOUR_WEBHOOK_URL"
   ```
2. Should return HTTP 200 or 202
3. Verify Portainer is running and accessible
4. Regenerate webhook in Portainer:
   - Go to **Stacks** → **image-mosaic** → **Webhooks**
   - Delete old webhook
   - Create new webhook
   - Copy new URL
   - Update GitHub secret

### Container doesn't update

**Cause:** Webhook triggered but image pull failed or stack not redeploying

**Fix:**
1. Check Portainer stack logs:
   - **Stacks** → **image-mosaic** → **Logs**
   - Look for errors like "image not found" or "pull failed"
2. Manually pull latest image:
   ```bash
   docker pull ghcr.io/your-username/image-mosaic:latest
   ```
3. In Portainer, click **Recreate** to force fresh pull and restart

### Stack shows old container

**Cause:** Webhook triggered but container not recreated

**Fix:**
1. In Portainer → **Stacks** → **image-mosaic**
2. Click **Recreate** button (top right)
3. This forces Portainer to stop old container and start new one with latest image

---

## Disable Auto-Deployment

To stop automatic deployments:

1. Delete GitHub secret:
   - **Settings** → **Secrets and variables** → **Actions**
   - Delete `PORTAINER_WEBHOOK_URL`

2. Or disable workflow:
   - **Actions** tab
   - Click "Deploy to Portainer"
   - Click **⋯** → **Disable workflow**

---

## Docker Compose Stack Setup

Your Portainer stack should use Docker Compose like this:

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

When webhook is triggered:
1. Portainer re-reads the Docker Compose
2. Pulls latest `image-mosaic:latest` from GHCR
3. Stops old container
4. Starts new container

---

## Testing Webhook Manually

Test webhook without pushing code:

```bash
# Replace with your actual webhook URL
WEBHOOK_URL="https://portainer.example.com/api/webhooks/..."

# Trigger the webhook
curl -X POST "$WEBHOOK_URL"

# Should return HTTP 200 or 202
```

---

## Manual Portainer Redeploy

If webhook doesn't work, you can manually redeploy:

1. In Portainer → **Stacks** → **image-mosaic**
2. Click **Recreate** (forces container restart with latest image)
3. Or **Redeploy** (redeploys entire stack)

---

## Support

- Portainer docs: https://docs.portainer.io
- GitHub Actions: https://docs.github.com/en/actions
- Webhook troubleshooting: Check Portainer logs (Stacks → Logs)

See [PORTAINER_GHCR_SETUP.md](PORTAINER_GHCR_SETUP.md) for GHCR image setup in Portainer.
