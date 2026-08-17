// Stamps every root *.html reference to css/*.css and js/*.js with ?v=<hash of the
// file's bytes>. nginx serves static assets with a 7-day public cache and the assets
// have no fingerprint in their names, so without this a returning browser keeps the
// old css/js while getting new html — the exact split that broke partner-applications.
//
// Run: node tools/stamp-assets.mjs   (after ANY change under css/ or js/)

import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

// Only css/ and js/ are versioned; anything else (absolute, protocol-relative, or a
// backend route like /api/content/bootstrap.js) is left exactly as the author wrote it.
const LOCAL_ASSET = /^(?:css|js)\/[^?#]+\.(?:css|js)$/;
// A reference is any quoted string whose whole value is a local asset path. Keying off
// the value rather than href=/src= is deliberate: admin.html loads js/admin.js only by
// handing that path as a plain string to a dynamic <script> builder, so an attribute-only
// match would leave the admin panel's main script permanently on the 7-day cache.
const REFERENCE = /("|')((?:css|js)\/[^"']+)\1/g;

const hashes = new Map();

// Hash once per asset, not once per reference — style.css is on all 57 pages.
function hashOf(ref) {
  if (hashes.has(ref)) return hashes.get(ref);
  let hash = null;
  const file = join(ROOT, ...ref.split('/'));
  try {
    if (statSync(file).isFile()) {
      hash = createHash('sha256').update(readFileSync(file)).digest('hex').slice(0, 8);
    }
  } catch {
    // Missing asset: leave the reference untouched so a broken link stays visible
    // as a 404 rather than being disguised by a stamp.
  }
  hashes.set(ref, hash);
  return hash;
}

const pages = readdirSync(ROOT, { withFileTypes: true })
  .filter((e) => e.isFile() && e.name.toLowerCase().endsWith('.html'))
  .map((e) => e.name)
  .sort();

let filesChanged = 0;
let refsStamped = 0;
const missing = new Set();

for (const page of pages) {
  const file = join(ROOT, page);
  const before = readFileSync(file, 'utf8');
  let stamped = 0;

  const after = before.replace(REFERENCE, (whole, quote, value) => {
    const split = value.indexOf('?');
    const assetPath = split === -1 ? value : value.slice(0, split);
    const query = split === -1 ? '' : value.slice(split + 1);
    if (!LOCAL_ASSET.test(assetPath)) return whole;

    const hash = hashOf(assetPath);
    if (hash === null) {
      missing.add(assetPath);
      return whole;
    }

    // Drop any previous ?v= but keep other params, so re-running only moves the hash.
    const kept = query
      .split('&')
      .filter((p) => p && !p.startsWith('v='))
      .join('&');
    const next = assetPath + '?' + (kept ? kept + '&' : '') + 'v=' + hash;
    if (next === value) return whole;

    stamped += 1;
    return quote + next + quote;
  });

  if (after !== before) {
    writeFileSync(file, after);
    filesChanged += 1;
    refsStamped += stamped;
    console.log('  ' + page + ' — ' + stamped + ' reference' + (stamped === 1 ? '' : 's'));
  }
}

const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');

console.log(
  filesChanged === 0
    ? 'stamp-assets: up to date — ' + plural(pages.length, 'page') + ' scanned, nothing to change.'
    : 'stamp-assets: ' + plural(refsStamped, 'reference') + ' restamped across ' +
      filesChanged + ' of ' + plural(pages.length, 'page') + '.'
);

if (missing.size) {
  console.log('stamp-assets: referenced but not on disk (left alone): ' + [...missing].join(', '));
}
