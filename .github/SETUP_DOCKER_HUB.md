# Quick Setup: GitHub Container Registry CI/CD

Get your image automatically built and pushed to GitHub Container Registry (GHCR) in 1 minute.

## ✨ No Setup Needed! 🎉

GitHub automatically enables container registry for all repositories. No additional secrets or accounts required!

The workflow uses `GITHUB_TOKEN` which is automatically provided by GitHub Actions.

## Automatic Workflow

Now whenever you:
- **Push to `main` branch** → builds and pushes as `latest`, `main`, and commit SHA
- **Push to `develop` branch** → builds and pushes as `develop`
- **Create a tag** like `v1.0.0` → builds and pushes as `v1.0.0`

Your image automatically appears in GitHub Container Registry!

## Test It

Push a commit:
```bash
git add .
git commit -m "Test GHCR CI/CD"
git push origin main
```

## Watch the Build

1. Go to your GitHub repository
2. Click **Actions** tab
3. Watch "Build and Push to GitHub Container Registry" workflow
4. Build completes in 2-3 minutes

## View Your Image

1. Go to your repository
2. Click **Packages** (right sidebar)
3. Your `image-mosaic` container will appear

Or visit:
```
https://github.com/your-username/image-mosaic/pkgs/container/image-mosaic
```

## Pull Your Image

### Authenticate with GitHub Token

```bash
# Generate personal access token at:
# https://github.com/settings/tokens?type=beta
# Select: read:packages scope

echo YOUR_TOKEN | docker login ghcr.io -u your-username --password-stdin
```

### Pull Image

```bash
docker pull ghcr.io/your-username/image-mosaic:latest
```

### Run Container

```bash
docker run -d \
  -p 8080:8080 \
  -e PHOTO_PRISM_BASE_URL="https://your-photoprism.com" \
  -e PHOTO_PRISM_API_KEY="your-key" \
  ghcr.io/your-username/image-mosaic:latest
```

Visit: http://localhost:8080

## Access Control

The image is **private by default**. To make it public:

1. Go to repository **Packages** → **image-mosaic**
2. Click **Package settings**
3. Scroll to **Danger Zone**
4. Change **Visibility** to **Public**

## Permissions

Your `GITHUB_TOKEN` automatically has permission to:
- Build images
- Push to your registry
- No manual token creation needed!

## Need Help?

- GitHub Actions failing? → Check **Actions** tab for error logs
- Can't pull image? → Make sure to authenticate with `docker login ghcr.io`
- Image not appearing? → Check **Packages** tab (may take 1-2 minutes)
- Permission denied? → Verify your token has `read:packages` scope

See [DEPLOYMENT.md](../DEPLOYMENT.md) for detailed information.
