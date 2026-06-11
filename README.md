# Image Mosaic PHP App

A small PHP web app that renders a 12×12 image mosaic using PhotoPrism as the backend image source.

## Files

- `index.php` — frontend page and mosaic UI
- `api.php` — backend JSON endpoint for tile data
- `photoprism_client.php` — simple PhotoPrism API client
- `config.php` — local PhotoPrism configuration values

## Setup

1. Copy or update `config.php` values, or export environment variables:

```bash
export PHOTO_PRISM_BASE_URL="http://localhost:2342"
export PHOTO_PRISM_API_KEY="your-api-key"
export PHOTO_PRISM_ACCESS_TOKEN="your-access-token"
export PHOTO_PRISM_USE_BASIC_AUTH=true
export PHOTO_PRISM_OAUTH_CLIENT_ID="your-client-id"
export PHOTO_PRISM_OAUTH_CLIENT_SECRET="your-client-secret"
export PHOTO_PRISM_USERNAME="your-username"
export PHOTO_PRISM_PASSWORD="your-password"
```

2. Run a PHP server from this project directory:

```bash
php -S localhost:8000
```

3. Open `http://localhost:8000/index.php` in your browser.

## PhotoPrism Notes

### Authentication

The app supports multiple authentication methods with the following precedence:

1. **Access Token**: Static bearer token set in `PHOTO_PRISM_ACCESS_TOKEN`
2. **API Key**: Static API key set in `PHOTO_PRISM_API_KEY`
3. **OAuth Password Grant** (recommended): Uses `PHOTO_PRISM_USERNAME` and `PHOTO_PRISM_PASSWORD` with optional OAuth client credentials:
   - `PHOTO_PRISM_OAUTH_CLIENT_ID`
   - `PHOTO_PRISM_OAUTH_CLIENT_SECRET`
4. **Basic Auth**: Set `PHOTO_PRISM_USE_BASIC_AUTH=true` to use HTTP Basic Authentication with username/password
5. **Session Login**: Falls back to session-based authentication if other methods fail

### Endpoints

- `PhotoPrismClient::listPhotos()` fetches up to 144 photos from the `/api/v1/photos` endpoint.
- `api.php?action=tiles` returns the mosaic tile metadata.
- Thumbnails are served from PhotoPrism via `/api/v1/photos/{uuid}/thumb`.

### Known Issues

The `/api/v1/photos` endpoint on some PhotoPrism instances may return HTTP 400 "Unable to do that" or HTTP 401 errors regardless of the authentication method used. This appears to be a server-side access restriction or account permission issue:

- Successfully authenticated endpoints (e.g., `/api/v1/config`, `/api/v1/echo`) return 200
- Photo endpoints return 400/401 even with valid, authenticated tokens

If you encounter this issue, verify:
- The PhotoPrism user account has photo read permissions
- The PhotoPrism instance allows photo API access for your account
- Check PhotoPrism server logs for specific error details
- Consider contacting your PhotoPrism administrator

## Customization

- Change mosaic dimensions in `config.php` (`mosaic_columns`, `mosaic_rows`).
- Add additional endpoints or search parameters in `photoprism_client.php`.
