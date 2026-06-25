# React Component Build Scaffold - Quick Start

The project now has a modern React component build scaffold with proper module organization.

## Installation & Setup

### 1. Install Dependencies
```bash
npm install
```

Installs:
- React 18.2.0
- React DOM 18.2.0
- Babel with JSX support
- Webpack with plugins
- CSS processors and minifiers

### 2. Build for Development
```bash
npm run dev
```

Watches for file changes and rebuilds automatically. Output goes to `dist/` folder.

### 3. Build for Production
```bash
npm run build
```

Creates minified, optimized bundle ready for deployment.

## Directory Structure

```
project-root/
├── src/                          # Source code
│   ├── index.jsx                # React app entry point
│   ├── components/              # React components
│   │   ├── Gallery.jsx         # Main gallery
│   │   ├── Tile.jsx            # Photo tile
│   │   ├── Lightbox.jsx        # Full-screen viewer
│   │   ├── Caption.jsx         # Photo metadata
│   │   └── InfoLightbox.jsx    # Info popup
│   ├── hooks/                   # Custom hooks (empty, ready for use)
│   ├── utils/                   # Utility functions (empty, ready for use)
│   └── styles/
│       └── index.css           # All styles
├── dist/                        # Compiled output (generated)
│   ├── gallery.min.js
│   ├── styles.min.css
│   └── gallery.min.js.map      # (if source maps enabled)
├── webpack.config.js            # Webpack configuration
├── .babelrc                     # Babel configuration
├── package.json                 # Dependencies and scripts
└── public/                      # Existing public files
    ├── images/                 # Destination photos
    └── gallery.js              # Old buildless file (can be removed)
```

## Workflow

### Adding a New Component

1. **Create component file:**
```jsx
// src/components/MyNewComponent.jsx
import React from 'react';

const MyNewComponent = () => {
  return <div className="my-new-component">Hello</div>;
};

export default MyNewComponent;
```

2. **Import in parent component:**
```jsx
import MyNewComponent from '@components/MyNewComponent';

// In JSX
<MyNewComponent />
```

3. **Add styles (if needed):**
```css
/* src/styles/index.css */
.my-new-component {
  padding: 1rem;
  background: blue;
}
```

4. **Build and test:**
```bash
npm run dev
```

### Modifying Existing Components

1. Edit component in `src/components/`
2. Changes auto-reload in watch mode (`npm run dev`)
3. Run `npm run build` for production

### Using Module Aliases

Clean imports with aliases:
```jsx
// ✅ Clean
import Gallery from '@components/Gallery';
import { someUtil } from '@utils/myUtils';

// ❌ Messy
import Gallery from '../components/Gallery';
import { someUtil } from '../utils/myUtils';
```

## Available Components

### Gallery
Main photo gallery container.
- Fetches photos from API
- Manages lightbox state
- Implements progressive image loading
- Shows loading skeleton

### Tile
Individual photo tile.
- Scattered layout with rotation
- Dynamic shadows
- Info button
- Hover effects

### Lightbox
Full-screen photo viewer.
- Navigation arrows
- Keyboard support (arrows, escape)
- Shows caption and albums
- Prevents body scroll

### Caption
Photo metadata display.
- Shows photo description
- Lists albums as pills
- Displays date taken

### InfoLightbox
Photo information modal.
- Shows all photo metadata
- Lists albums
- Keyboard close support

## Babel Configuration

Automatic JSX transformation (no need to import React):
```jsx
// Works without importing React
const Component = () => <div>Hello</div>;
```

## Building & Deployment

### Development
```bash
npm run dev
# Watches files, rebuilds on changes
# Output: dist/gallery.min.js, dist/styles.min.css
```

### Production
```bash
npm run build
# One-time optimized build
# Minified and optimized for deployment
```

### Deployment
1. Build with `npm run build`
2. Deploy `dist/` folder contents to web server
3. HTML links to `/dist/gallery.min.js` (already configured)

## Performance

- **Code splitting:** Ready to add lazy loading
- **Tree shaking:** Unused code removed during build
- **Minification:** JavaScript and CSS minified
- **Progressive loading:** Images load on scroll

## File Sizes

Expected output sizes:
- `gallery.min.js` - ~50-100 KB (includes React)
- `styles.min.css` - ~3-5 KB

Both gzip to ~30-50% of original size.

## Troubleshooting

### Styles not updating
```bash
npm run build  # Rebuild CSS
# Clear browser cache (Ctrl+Shift+Delete)
```

### Component not found
- Check filename matches import (case-sensitive)
- Ensure `.jsx` extension
- Verify path uses alias: `@components/ComponentName`

### JSX not transpiling
- Check `.babelrc` exists
- Run `npm install` to update Babel
- Restart `npm run dev`

### Old bundle still loading
- Clear browser cache completely
- Delete old `dist/` folder
- Run `npm run build` again

## Next Steps

1. **Run setup:**
   ```bash
   npm install
   npm run dev
   ```

2. **Start developing:**
   - Edit components in `src/components/`
   - Changes auto-reload
   - Open browser to see updates

3. **Deploy:**
   ```bash
   npm run build
   ```

For detailed component documentation, see `REACT_COMPONENTS.md`.
