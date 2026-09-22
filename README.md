# Image Mosaic

Image Mosaic is a small service that builds a mosaic gallery using images indexed by PhotoPrism. This repository contains a Go-based API server and a static web frontend.

Highlights
- Go API server (cmd/api) serving endpoints compatible with the previous PHP implementation
- Static frontend assets in ./public served by the Go server
- OpenAPI documentation available at /swagger/

Quick start (local)

1. Configure environment variables (.env or export):

   - PHOTO_PRISM_BASE_URL (required) — e.g. https://photoprism.example.com
   - PHOTO_PRISM_ACCESS_TOKEN (optional)
   - PHOTO_PRISM_API_KEY (optional)

2. Build and run with Docker Compose:

   docker compose build
   docker compose up -d

3. Open the UI at http://localhost:8080/ and API docs at http://localhost:8080/swagger/

Development

- Build Go binary locally: `go build -o image-mosaic ./cmd/api`
- Run locally: `PHOTO_PRISM_BASE_URL=https://photoprism.example.com ./image-mosaic`
- Generate or refresh OpenAPI docs: this repo includes a minimal docs/swagger.json; to generate via swaggo, install swag (https://github.com/swaggo/swag) and run `swag init` in repo root (optional).

Regenerate OpenAPI docs (swag)

To regenerate the OpenAPI docs from code annotations locally:

1. Install the swag binary:

   go install github.com/swaggo/swag/cmd/swag@latest

2. Make sure `$GOPATH/bin` is in your PATH (the `swag` binary will be installed there).

3. Run swag to generate docs (this writes to ./docs):

   swag init -g cmd/api/main.go -o docs

4. Commit the generated files in `./docs` if you want them versioned.

The CI workflow already runs `swag init` as part of the build if configured; this step is only necessary if you change code annotations locally and want to commit the generated docs.

API

The service exposes REST endpoints under `/api/*`. Key endpoints:

- `GET /api/tiles?category=<name>&limit=<n>&offset=<n>` — returns mosaic tiles for the category (paginated)
- `GET /api/albums?category=<name>` — returns albums for a category
- `GET /api/country-places?category=<name>` — aggregated country/place counts
- `GET /api/photo-count?category=<name>` — photo count for a category/album
- `GET /api/featured?category=<name>` — single featured photo
- `GET /api/debug` — diagnostic info

The OpenAPI UI is available at `/swagger/`.

Portainer deployment

Use the `PORTAINER_WEBHOOK_URL` repository secret to enable automatic redeploys on push to `main`. The `portainer-deploy.yml` workflow will trigger that webhook after publishing a new GHCR image.
