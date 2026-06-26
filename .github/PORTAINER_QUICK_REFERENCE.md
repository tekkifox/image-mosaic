# Portainer Auto-Deployment - Quick Reference

**For Portainer Community Edition (CE)**

## 2-Minute Setup Checklist

- [ ] Create webhook in Portainer (Stacks → image-mosaic → Webhooks → Create)
- [ ] Copy webhook URL
- [ ] Add 1 GitHub secret (Settings → Secrets and variables → Actions)
- [ ] Test by pushing to main branch

## GitHub Secret to Add

```
PORTAINER_WEBHOOK_URL = https://portainer.example.com/api/webhooks/...
```

(Get this URL from Portainer → Stacks → image-mosaic → Webhooks → Create webhook)

## How It Works

```
git push → GitHub Actions → Portainer Webhook → Container Redeploys
```

## Verify It's Working

1. **Make a test commit:**
   ```bash
   git commit --allow-empty -m "Test Portainer deployment"
   git push origin main
   ```

2. **Check GitHub Actions:**
   - Go to Actions tab
   - Watch "Deploy to Portainer" workflow
   - Should show "✅ Webhook triggered successfully"

3. **Check Portainer:**
   - Go to Stacks → image-mosaic → Logs
   - Should see recent activity (pulling image, restarting container)
   - Container will restart within 30 seconds

## Trigger Manual Deployment

No code changes needed:

1. Go to GitHub **Actions** tab
2. Select **Deploy to Portainer** workflow
3. Click **Run workflow** button
4. Watch it trigger the webhook!

## Stop Auto-Deployment

Delete the GitHub secret:
- `PORTAINER_WEBHOOK_URL`

## Troubleshooting Commands

Test webhook manually (no code push):

```bash
# Replace with your actual webhook URL from Portainer
curl -X POST "https://portainer.example.com/api/webhooks/abc123..."

# Should return HTTP 200 or 202
```

Check GitHub Actions logs:

1. Go to **Actions** tab
2. Click **Deploy to Portainer** workflow
3. Click latest run
4. Expand "Trigger Portainer Webhook" step to see logs

Check Portainer logs:

1. Go to **Stacks**
2. Click `image-mosaic` stack
3. Click **Logs** tab
4. Should see recent activity (pull, restart, etc)

## Common Issues

| Problem | Solution |
|---|---|
| "Webhook URL not configured" | Add `PORTAINER_WEBHOOK_URL` secret to GitHub |
| Webhook URL is blank | Create webhook in Portainer: Stacks → image-mosaic → Webhooks |
| Webhook returns 4xx/5xx error | Check webhook URL is correct, test with curl |
| Container not updating | Manual redeploy: Portainer → Stacks → Recreate |

## Files Reference

- **Workflow:** `.github/workflows/portainer-deploy.yml`
- **Setup guide:** `.github/PORTAINER_DEPLOY_SETUP.md`
- **GHCR in Portainer:** `.github/PORTAINER_GHCR_SETUP.md`

## What Gets Deployed

✅ Every push to `main` branch
✅ Every push to `develop` branch
✅ Every git tag (v1.0.0, v2.0.0, etc)
✅ Manual workflow dispatch

## Environment Flow

```
Local Development
    ↓
git push origin main
    ↓
GitHub Actions
  ├─ Build Docker image
  ├─ Push to GHCR
  └─ Call Portainer webhook
    ↓
Portainer (via webhook)
  ├─ Pulls latest image from GHCR
  ├─ Stops old container
  └─ Starts new container
    ↓
Live Application
    (ready in ~30 seconds)
```
