# Build Setup Guide

This project uses webpack to manage and minify CSS and JavaScript assets.

## Prerequisites

- Node.js (v14 or higher)
- npm (comes with Node.js)

## Installation

1. Install dependencies:
```bash
npm install
```

## Build Commands

### Production Build (Minified)
Creates optimized minified files in the `dist/` folder:
```bash
npm run build
```

This generates:
- `dist/styles.min.css` - Minified CSS
- `dist/gallery.min.js` - Minified JavaScript

### Development Build (Watch Mode)
Watch for file changes and rebuild automatically:
```bash
npm run dev
```
or
```bash
npm run watch
```

## Project Structure

```
project-root/
├── index.php                 # Main HTML file (links minified CSS)
├── public/
│   ├── styles.css           # Source CSS file
│   └── gallery.js           # Gallery JavaScript
├── dist/                    # Generated minified output (created on build)
│   ├── styles.min.css       # Minified CSS
│   └── gallery.min.js       # Minified JavaScript
├── webpack.config.js        # Webpack configuration
└── package.json             # Dependencies and build scripts
```

## How It Works

1. **CSS Extraction**: The webpack configuration extracts CSS from `public/styles.css` and minifies it using `mini-css-extract-plugin`
2. **File Processing**: CSS is processed through `css-loader` for optimization
3. **Output**: Minified files are generated in the `dist/` folder
4. **HTML Integration**: `index.php` links to the minified CSS at `/dist/styles.min.css`

## File Changes Workflow

1. **Edit Source Files**: Modify CSS in `public/styles.css` or JavaScript in `public/gallery.js`
2. **Run Build**: Execute `npm run build` (or use `npm run dev` to watch for changes)
3. **Deploy**: The minified files in `dist/` are ready for production

## Development Workflow

For active development:
```bash
npm run watch
```

This watches for changes and automatically rebuilds. Simply:
1. Edit your CSS or JS files
2. Save the file
3. Webpack automatically rebuilds the minified versions
4. Refresh your browser to see changes

## Minification Benefits

- **Smaller File Size**: Reduced bandwidth usage and faster page loads
- **Better Performance**: Less CSS/JS to parse and execute
- **Production Ready**: Minified assets are optimized for deployment

## Troubleshooting

### Command not found: npm
Install Node.js from https://nodejs.org/

### Missing node_modules
Run `npm install` to install dependencies

### Changes not reflecting
- Make sure you ran `npm run build` after changes
- Clear browser cache (Ctrl+Shift+Delete or Cmd+Shift+Delete)
- Check that `dist/styles.min.css` has been updated with latest modifications

## Next Steps

- Run `npm install` to set up the build system
- Run `npm run build` to create the first minified files
- Deploy the `dist/` folder to your web server
