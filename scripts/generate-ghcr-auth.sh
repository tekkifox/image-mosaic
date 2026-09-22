#!/bin/bash

# Generate GHCR authentication for Portainer Docker Compose
# Usage: ./scripts/generate-ghcr-auth.sh

echo "=== GHCR Authentication Generator for Portainer ==="
echo ""

# Get GitHub username
read -p "GitHub username: " GITHUB_USER

# Get GitHub token
read -sp "GitHub personal access token (read:packages scope): " GITHUB_TOKEN
echo ""

if [ -z "$GITHUB_USER" ] || [ -z "$GITHUB_TOKEN" ]; then
    echo "❌ Error: Username and token are required"
    exit 1
fi

# Create base64 auth
AUTH=$(echo -n "$GITHUB_USER:$GITHUB_TOKEN" | base64)

echo ""
echo "✅ Base64 encoded credentials:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "$AUTH"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📋 Copy this value to use in Portainer secret"
echo ""
echo "Steps to add to Portainer:"
echo "1. Go to Portainer → Secrets"
echo "2. Click 'Add secret'"
echo "3. Name: dockerconfigjson"
echo "4. Content: Paste the JSON below (replace AUTH_VALUE with the base64 string)"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "JSON for Portainer secret:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
cat << JSON
{
  "auths": {
    "ghcr.io": {
      "auth": "$AUTH"
    }
  }
}
JSON
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Then update your Docker Compose stack with:"
echo ""
echo "secrets:"
echo "  dockerconfigjson:"
echo "    external: true"
echo ""
echo "✅ Done! Now your webhook deployments will work."
