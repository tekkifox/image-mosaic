#!/bin/bash

# Image Mosaic Setup Script
echo "🎬 Setting up Image Mosaic..."

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js is not installed. Please install it from https://nodejs.org/"
    exit 1
fi

echo "✓ Node.js found: $(node --version)"

# Check if pnpm is installed
if ! command -v pnpm &> /dev/null; then
    echo "❌ pnpm is not installed. Enable it with: corepack enable pnpm"
    exit 1
fi

echo "✓ pnpm found: $(pnpm --version)"

# Install dependencies
echo ""
echo "📦 Installing dependencies..."
pnpm install

# Build the assets
echo ""
echo "🔨 Building minified assets..."
pnpm run build

echo ""
echo "✅ Setup complete!"
echo ""
echo "📋 Next steps:"
echo "  1. Configure PhotoPrism credentials in .env"
echo "  2. Run: pnpm run dev (for development with watch mode)"
echo "  3. Or run: pnpm run build (for production build)"
echo ""
echo "📖 For more information, see BUILD.md"
