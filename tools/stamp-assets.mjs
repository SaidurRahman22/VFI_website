// Stamps every root *.html reference to css/*.css, js/*.js and assets/ images with
// ?v=<hash of the file's bytes>. nginx serves all of them from one location block with
// a 7-day public cache (deploy/nginx/production.conf:75) and none of them carry a
// fingerprint in the filename, so without this a returning browser keeps the old asset
// while getting new html — the exact split that broke partner-applications.
//
// Run: node tools/stamp-assets.mjs   (after ANY change under css/, js/ or assets/)

import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

// Only css/, js/ and assets/ are versioned; anything else (absolute, protocol-relative,
// or a backend route like /api/content/bootstrap.js) is left exactly as the author wrote
// it. The extension list for assets/ is the one nginx caches, so nothing gets stamped
// that is not also being held for a week.
const LOCAL_ASSET =
  /^(?:(?:css|js)\/[^?#]+\.(?:css|js)|assets\/[^?#]+\.(?:png|jpe?g|svg|webp|gif|ico|avif))$/;
// A reference is any quoted string whose whole value is a local asset path. Keying off
// the value rather than href=/src= is deliberate: admin.html loads js/admin.js only by
// handing that path as a plain string to a dynamic <script> builder, so an attribute-only
// match would leave the admin panel's main script permanently on the 7-day cache.
//
// The cost of matching on the value: a `data-*` attribute holding a bare asset path would
// be stamped too, and js/store.js proves such paths are used as LOOKUP KEYS (imgId,
// resolved through VFI.getImage). No HTML attribute holds one today - checked - but if
// one is ever added it must not be a plain quoted asset path.
const REFERENCE = /("|')((?:css|js|assets)\/[^"']+)\1/g;
// Inline background images: style="background-image:url('assets/img/x.jpg')". There are
// 149 of those against 63 quoted attribute values, so the quoted form alone would have
// left most of the site's imagery unstamped. CSS makes the quote optional, hence the
// backreference on a group that is allowed to be empty.
const INLINE_URL = /url\(\s*("|'|)((?:css|js|assets)\/[^"')]+)\1\s*\)/g;

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

  // One rewriting rule, reached through two syntaxes. Returns null when the value
  // should be left exactly as the author wrote it.
  const restamp = (value) => {
    const split = value.indexOf('?');
    const assetPath = split === -1 ? value : value.slice(0, split);
    const query = split === -1 ? '' : value.slice(split + 1);
    if (!LOCAL_ASSET.test(assetPath)) return null;

    const hash = hashOf(assetPath);
    if (hash === null) {
      missing.add(assetPath);
      return null;
    }

    // Drop any previous ?v= but keep other params, so re-running only moves the hash.
    const kept = query
      .split('&')
      .filter((p) => p && !p.startsWith('v='))
      .join('&');
    const next = assetPath + '?' + (kept ? kept + '&' : '') + 'v=' + hash;
    return next === value ? null : next;
  };

  let after = before.replace(REFERENCE, (whole, quote, value) => {
    const next = restamp(value);
    if (next === null) return whole;
    stamped += 1;
    return quote + next + quote;
  });

  after = after.replace(INLINE_URL, (whole, quote, value) => {
    const next = restamp(value);
    if (next === null) return whole;
    stamped += 1;
    return 'url(' + quote + next + quote + ')';
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

// css/ is NOT scanned for url(...) image references, and that gap has a reason:
// rewriting a stylesheet changes its bytes, so the ?v= every HTML page carries for THAT
// stylesheet would have to be recomputed after the rewrite rather than in the same pass.
// There are zero such references today, so the ordering was not built for a case that
// does not exist - but silence would turn it into a stale-image bug that looks like
// nothing at all, so it shouts instead.
const IMAGE_URL_IN_CSS =
  /url\(\s*["']?([^"')]+\.(?:png|jpe?g|svg|webp|gif|ico|avif))["']?\s*\)/gi;
const cssImageRefs = [];
try {
  for (const entry of readdirSync(join(ROOT, 'css'), { withFileTypes: true })) {
    if (!entry.isFile() || !entry.name.toLowerCase().endsWith('.css')) continue;
    const text = readFileSync(join(ROOT, 'css', entry.name), 'utf8');
    for (const match of text.matchAll(IMAGE_URL_IN_CSS)) {
      const ref = match[1];
      // Remote and inline images are nobody's cache problem here.
      if (/^(?:https?:)?\/\//.test(ref) || ref.startsWith('data:')) continue;
      cssImageRefs.push(entry.name + ' → ' + ref);
    }
  }
} catch {
  // No css/ directory: nothing to warn about.
}
if (cssImageRefs.length) {
  console.log(
    'stamp-assets: WARNING — ' + plural(cssImageRefs.length, 'image url() reference') +
    ' inside css/ is NOT stamped, and will be served stale for up to 7 days:'
  );
  for (const ref of cssImageRefs) console.log('  ' + ref);
  console.log('  Fixing that means rewriting the stylesheet, then re-hashing it for the HTML.');
}

// Say what is NOT covered, every run. "212 references restamped" on its own reads as
// "the images are fingerprinted", and the ones reached through js/ are not. Measured on
// the live home page: the .fev__media and .news__media cards that JS builds carry 10
// unstamped background URLs. Informational rather than a warning, because these are
// decisions and not surprises - a warning on every run is a warning nobody reads.
//
// There are TWO distinct reasons, and they are not interchangeable:
//
//   js/store.js  - its paths are imgId LOOKUP KEYS, handed to VFI.getImage() to resolve
//                  a managed override (admin.js:327, render.js:565). Stamping one breaks
//                  resolution, not caching. This one cannot simply be stamped.
//   every other  - plain URLs (universities.js SEASON[].img; the emblem in site.js and
//                  portal.js markup). Safe to stamp in principle, but doing so rewrites
//                  a .js file whose own ?v= every HTML page already carries, so it needs
//                  a pre-pass over js/ BEFORE the page loop - not a rule inside it.
const KEYED_SOURCES = new Set(['store.js']);
const jsUrlRefs = new Set();
const jsKeyedRefs = new Set();
try {
  for (const entry of readdirSync(join(ROOT, 'js'), { withFileTypes: true })) {
    if (!entry.isFile() || !entry.name.toLowerCase().endsWith('.js')) continue;
    const text = readFileSync(join(ROOT, 'js', entry.name), 'utf8');
    if (!/assets\/[^"'`)\s]+\.(?:png|jpe?g|svg|webp|gif|ico|avif)/i.test(text)) continue;
    (KEYED_SOURCES.has(entry.name) ? jsKeyedRefs : jsUrlRefs).add(entry.name);
  }
} catch {
  // No js/ directory: nothing to report.
}
if (jsKeyedRefs.size || jsUrlRefs.size) {
  console.log('stamp-assets: image paths in js/ are NOT stamped — they keep the 7-day window.');
  if (jsKeyedRefs.size) {
    console.log(
      '  ' + [...jsKeyedRefs].sort().join(', ') +
      ': imgId lookup keys. A stamp breaks resolution, not caching — cannot be stamped.'
    );
  }
  if (jsUrlRefs.size) {
    console.log(
      '  ' + [...jsUrlRefs].sort().join(', ') +
      ': plain URLs. Stampable, but rewriting a .js file invalidates the ?v= HTML carries'
    );
    console.log('    for it, so it needs a pre-pass over js/ before the page loop.');
  }
}
