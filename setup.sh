#!/bin/bash

# Image Mosaic Setup Script
echo "🎬 Setting up Image Mosaic..."

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js is not installed. Please install it from https://nodejs.org/"
    exit 1
fi

echo "✓ Node.js found: $(node --version)"

# Check if npm is installed
if ! command -v npm &> /dev/null; then
    echo "❌ npm is not installed."
    exit 1
fi

echo "✓ npm found: $(npm --version)"

# Install dependencies
echo ""
echo "📦 Installing npm dependencies..."
npm install

# Build the assets
echo ""
echo "🔨 Building minified assets..."
npm run build

echo ""
echo "✅ Setup complete!"
echo ""
echo "📋 Next steps:"
echo "  1. Configure PhotoPrism credentials in .env"
echo "  2. Run: npm run dev (for development with watch mode)"
echo "  3. Or run: npm run build (for production build)"
echo ""
echo "📖 For more information, see BUILD.md"
