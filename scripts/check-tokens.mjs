#!/usr/bin/env node
/**
 * Design-token guard.
 *
 * Fails when a stylesheet outside src/styles/tokens.css contains a raw colour,
 * an rgb()/hsl() literal, or a fixed px value. Component styles must consume
 * the custom properties documented in DESIGN.md.
 *
 * Deliberately dependency-free: run it with `node scripts/check-tokens.mjs`.
 *
 * Allowed outside the token file:
 *   - 0, percentages, vh/vw, em/rem, unitless numbers
 *   - colours inside url(...) data URIs
 *   - px values in at-rule preludes such as @media (max-width: 768px),
 *     because custom properties are not valid there
 */

import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const TOKEN_FILE = path.join(ROOT, 'src', 'styles', 'tokens.css');
const SKIP_DIRS = new Set(['.git', 'node_modules', 'dist', 'vendor']);
const BUILD_OUTPUT = path.join(ROOT, 'cmd', 'api', 'public');
const SKIP_PATHS = new Set([TOKEN_FILE]);
const HEX = /#[0-9a-fA-F]{3,8}\b/g;
const COLOUR_FUNCTION = /\b(?:rgba?|hsla?)\s*\(/gi;
const PX = /-?(?:\d+|\d*\.\d+)px\b/g;
const NAMED_COLOURS = new Set(
  'aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen'.split(/\s+/)
);
const DECLARATION = /([-\w]+)\s*:\s*([^;{}]+);/g;

async function collectStylesheets(dir) {
  const entries = await readdir(dir, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (SKIP_DIRS.has(entry.name) || full === BUILD_OUTPUT) continue;
      files.push(...(await collectStylesheets(full)));
    } else if (entry.isFile() && entry.name.endsWith('.css') && !SKIP_PATHS.has(full)) {
      files.push(full);
    }
  }
  return files;
}

function lineAt(text, index) {
  let line = 1;
  for (let i = 0; i < index; i += 1) {
    if (text.charCodeAt(i) === 10) line += 1;
  }
  return line;
}

function excerpt(value) {
  return value.replace(/\s+/g, ' ').trim().slice(0, 80);
}

// Remove comments and neutralise url()/quoted strings so their contents are
// not mistaken for declarations (data-URI SVGs legitimately contain colours).
function sanitise(css) {
  return css
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/url\((?:[^()"']|"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')*\)/gi, 'url()')
    .replace(/"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'/g, '""');
}

async function main() {
  let stylesheets;
  try {
    stylesheets = await collectStylesheets(ROOT);
  } catch (error) {
    console.error(`Design token guard: could not scan stylesheets: ${error.message}`);
    process.exit(1);
  }

  const violations = [];

  for (const file of stylesheets) {
    const css = sanitise(await readFile(file, 'utf8'));
    const relative = path.relative(ROOT, file).split(path.sep).join('/');

    for (const match of css.matchAll(DECLARATION)) {
      const [declaration, , rawValue] = match;
      const valueOffset = match.index + declaration.indexOf(rawValue);
      const valueLine = lineAt(css, valueOffset);

      for (const found of rawValue.matchAll(HEX)) {
        violations.push({
          file: relative,
          line: valueLine,
          message: `raw colour ${found[0]} in "${excerpt(declaration)}"`,
        });
      }
      for (const found of rawValue.matchAll(COLOUR_FUNCTION)) {
        violations.push({
          file: relative,
          line: valueLine,
          message: `raw colour function ${found[0].replace(/\s+/g, '')}) in "${excerpt(declaration)}"`,
        });
      }
      for (const found of rawValue.matchAll(PX)) {
        violations.push({
          file: relative,
          line: valueLine,
          message: `fixed value ${found[0]} in "${excerpt(declaration)}" (use a token)`,
        });
      }
      for (const found of rawValue.matchAll(/\b[a-zA-Z]+\b/g)) {
        if (!NAMED_COLOURS.has(found[0].toLowerCase())) continue;
        violations.push({
          file: relative,
          line: valueLine,
          message: `raw named colour ${found[0]} in "${excerpt(declaration)}"`,
        });
      }
    }
  }

  if (violations.length > 0) {
    console.error('Design token guard failed:\n');
    for (const violation of violations) {
      console.error(`  ${violation.file}:${violation.line}  ${violation.message}`);
    }
    console.error(
      `\n${violations.length} violation(s). Use variables from src/styles/tokens.css;` +
        ' add a token there (and document it in DESIGN.md) instead of a raw value.'
    );
    process.exit(1);
  }

  console.log(
    `Design token guard passed: ${stylesheets.length} stylesheet(s) outside ` +
      'src/styles/tokens.css contain no raw colours or fixed px values.'
  );
}

main();
