# React Component Structure

The project has been refactored to use proper React components with a modern build scaffold.

## Project Structure

```
src/
├── index.jsx                    # Main entry point
├── components/
│   ├── Gallery.jsx             # Main gallery container
│   ├── Tile.jsx                # Individual photo tile
│   ├── Lightbox.jsx            # Full-screen lightbox view
│   ├── Caption.jsx             # Photo caption display
│   └── InfoLightbox.jsx        # Photo info popup
├── hooks/                       # Custom React hooks (for future use)
├── utils/                       # Utility functions (for future use)
└── styles/
    └── index.css               # All styles (imported in index.jsx)
```

## Components

### Gallery Component
**File:** `src/components/Gallery.jsx`

Main container component that:
- Manages state for photos, loading, and lightbox views
- Fetches photos from API
- Handles photo count display
- Implements progressive image loading
- Manages skeleton loading state

**Props:** None (standalone component)

```jsx
<Gallery />
```

### Tile Component
**File:** `src/components/Tile.jsx`

Individual photo tile that:
- Displays photo with scattered effect (rotation + offset)
- Generates dynamic shadows based on rotation
- Shows info button on hover
- Handles click events

**Props:**
- `item` (object) - Photo data with src, alt, etc.
- `isLoading` (boolean) - Whether to show skeleton
- `onTileClick` (function) - Called when tile is clicked
- `onInfoClick` (function) - Called when info button is clicked

```jsx
<Tile item={photo} isLoading={false} onTileClick={handleClick} onInfoClick={handleInfo} />
```

### Lightbox Component
**File:** `src/components/Lightbox.jsx`

Full-screen image viewer that:
- Displays large image with navigation
- Shows caption and metadata
- Handles keyboard navigation (arrows, escape)
- Prevents body scroll when open

**Props:**
- `items` (array) - All photos
- `currentIndex` (number) - Index of current photo
- `onClose` (function) - Called when closing
- `onPrev` (function) - Called for previous photo
- `onNext` (function) - Called for next photo

```jsx
<Lightbox
  items={photos}
  currentIndex={0}
  onClose={handleClose}
  onPrev={handlePrev}
  onNext={handleNext}
/>
```

### Caption Component
**File:** `src/components/Caption.jsx`

Photo metadata display showing:
- Photo caption text
- Album pills (if multiple albums)
- Date taken

**Props:**
- `caption` (string) - Photo description
- `albums` (array) - Album names
- `taken` (string) - Date photo was taken

```jsx
<Caption caption="My photo" albums={["Bangkok"]} taken="June 25, 2016" />
```

### InfoLightbox Component
**File:** `src/components/InfoLightbox.jsx`

Photo information modal showing:
- Photo title
- Caption text
- Album list
- Date taken

**Props:**
- `item` (object) - Photo data with metadata
- `onClose` (function) - Called when closing

```jsx
<InfoLightbox item={photo} onClose={handleClose} />
```

## Build Process

### Development
```bash
npm run dev
```
Starts webpack in watch mode with development settings.

### Production Build
```bash
npm run build
```
Creates minified production bundle with:
- JSX transpiled to JavaScript
- CSS extracted and minified
- JavaScript minified with Terser
- Output in `dist/` folder

## Build Configuration

### Webpack Setup
- **Entry:** `src/index.jsx`
- **Output:** `dist/gallery.min.js`, `dist/styles.min.css`
- **Loader:** babel-loader for JSX/ES6
- **Plugins:** MiniCssExtractPlugin, TerserPlugin, CssMinimizerPlugin

### Babel Setup
- **Preset:** @babel/preset-react (with automatic JSX runtime)
- **Preset:** @babel/preset-env (for ES6+ compatibility)
- **Runtime:** automatic (no need to import React)

### Module Aliases
```javascript
'@components' → src/components
'@utils'      → src/utils
'@hooks'      → src/hooks
```

Use aliases for cleaner imports:
```jsx
// Before
import Gallery from '../components/Gallery';

// After
import Gallery from '@components/Gallery';
```

## Dependencies

### Production
- `react@^18.2.0` - React library
- `react-dom@^18.2.0` - React DOM rendering

### Development
- `@babel/core` - Babel compiler
- `@babel/preset-env` - ES6+ transpilation
- `@babel/preset-react` - JSX transpilation
- `babel-loader` - Webpack loader for Babel
- `webpack` - Module bundler
- `webpack-cli` - Webpack CLI
- `css-loader` - CSS loader
- `mini-css-extract-plugin` - CSS extraction
- `terser-webpack-plugin` - JavaScript minification
- `css-minimizer-webpack-plugin` - CSS minification

## Installation

After pulling new changes, install dependencies:

```bash
npm install
```

This installs both React dependencies and build tools.

## Creating New Components

1. **Create component file** in `src/components/`:
```jsx
// src/components/MyComponent.jsx
import React from 'react';

const MyComponent = ({ prop1, prop2 }) => {
  return <div>{prop1} {prop2}</div>;
};

export default MyComponent;
```

2. **Import in parent component**:
```jsx
import MyComponent from '@components/MyComponent';

// Use it
<MyComponent prop1="hello" prop2="world" />
```

3. **Build and deploy**:
```bash
npm run build
```

## Styling Components

Styles are centralized in `src/styles/index.css`. All existing styles are preserved.

To add component-specific styles, add them to `src/styles/index.css` with descriptive class names:

```css
.my-component {
  display: flex;
  justify-content: center;
}

.my-component-item {
  padding: 1rem;
}
```

Then use in component:
```jsx
<div className="my-component">
  <div className="my-component-item">...</div>
</div>
```

## Migration from Buildless Setup

The old buildless setup (loading React from CDN) has been replaced with:
- Proper module system
- JSX support
- Build optimization
- Better code organization
- Import aliases for cleaner code

**Old:**
```html
<script src="https://unpkg.com/react@18/..."></script>
<script src="/public/gallery.js"></script>
```

**New:**
```html
<script src="/dist/gallery.min.js"></script>
```

## Future Improvements

- Add component-level CSS modules
- Add PropTypes or TypeScript
- Add unit tests with Jest
- Add Storybook for component documentation
- Add code splitting for lazy loading
- Add service workers for offline support

## Troubleshooting

### "Module not found" errors
- Check imports use correct paths
- Use `@components`, `@utils`, `@hooks` aliases
- Ensure files have `.jsx` extension

### Babel not transpiling JSX
- Ensure `.babelrc` exists with correct presets
- Run `npm install` to get latest Babel packages
- Restart webpack with `npm run dev`

### Styles not loading
- Check CSS is imported in `src/index.jsx`
- Verify CSS is in `src/styles/`
- Run `npm run build` to generate dist files

### Old gallery.js still being used
- Delete old `/public/gallery.js` (now in `src/components/`)
- Ensure HTML imports from `/dist/gallery.min.js`
- Clear browser cache
