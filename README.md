# Image Mosaic Project

This project functions as a dedicated client layer for integrating with a PhotoPrism instance, enabling the rendering of image mosaics and managing photo metadata via its API.

## Project Structure
- `api.php`: The service entry point responsible for coordinating requests to the PhotoPrism client and handling application logic.
- `photoprism_client.php`: Contains the core business logic for communicating with the PhotoPrism API (e.g., `getPhotoAlbums`, listing photos). It is responsible for network requests and data translation.
- `config.php`: **Configuration File.** This file holds sensitive environment variables, including the PhotoPrism base URL, API keys, and any specific client timeouts or limits. It acts as the primary bridge between the application logic and the external service.
- `index.php`: The main application bootstrap file, used to initialize the system and begin processing requests.
- `.gitignore`: Defines files and directories that should be ignored by version control (e.g., local cache, dependency artifacts).

## Setup and Installation
1. **Prerequisites:** Ensure PHP is installed and running in your environment.
2. **Configuration:** Set up the necessary connection details by populating `config.php` with the PhotoPrism base URL and authentication tokens/keys.
3. **Service Readiness:** Verify that the target PhotoPrism instance is operational and accepting connections at the defined base URL.

## Usage
To successfully run the image mosaic renderer, access `index.php` (or whichever file serves as your main entry point) and rely on the initialization sequence handled by `api.php`.

## Contributing
Contributions are welcome. Please refer to the CONTRIBUTING guide for submission details.

## Running the Project
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
