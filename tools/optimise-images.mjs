// Recompresses everything under assets/ in place, with no npm packages and no external
// binaries: the VPS has no cwebp/jpegoptim/pngquant/imagemagick and we cannot assume a
// developer machine has them either. So the PNG and JPEG work below is done on node:zlib and
// raw buffers. That is why this file is long — the alternative was depending on a toolchain
// the project does not own. (On Windows, `convert` is the NTFS filesystem tool, not
// ImageMagick, which is one more reason not to shell out on a guess.)
//
// Run: node tools/optimise-images.mjs            in place, safe to re-run
//      node tools/optimise-images.mjs --dry-run  report only, writes nothing
//
// Two rules make re-running safe. PNG work is strictly lossless, so a second pass reproduces
// the same bytes and reports no further saving. JPEG work coarsens the quantisation tables
// only up to a fixed target quality and never past it, so a file already at or below the
// target is left alone rather than degraded again — see buildNewQuantTables().
//
// Scope is hard-limited to assets/ on purpose. backend/storage holds student passports and
// transcripts that are sha256-hashed for dedupe and covered by an append-only access log;
// re-encoding one would invalidate the hash and break the audit trail. requireInsideAssets()
// enforces that boundary rather than trusting the caller to remember it.

import { readdirSync, readFileSync, writeFileSync, statSync, realpathSync } from 'node:fs';
import { join, dirname, relative, resolve, basename, isAbsolute, sep, delimiter } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import zlib from 'node:zlib';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const ASSETS = join(ROOT, 'assets');
const MANIFEST = join(ASSETS, '.image-optimised.json');
const TOOL_VERSION = 1;

// ============================================================ CLI

const HELP = `Usage: node tools/optimise-images.mjs [options]

  --dry-run           report what would change, write nothing
  --force             ignore the skip manifest and re-examine every file
  --jpeg-quality=N    JPEG target quality, 30..100 (default 80)
  --no-verify         skip the decode-back check (not recommended)
  --webp              also emit .webp siblings, if a cwebp binary is on PATH
  --dir=PATH          limit the run to a subdirectory of assets/
`;

function parseArgs(argv) {
  const opts = { dryRun: false, force: false, jpegQuality: 80, verify: true, webp: false, dir: ASSETS, help: false };
  for (const arg of argv) {
    if (arg === '--dry-run') opts.dryRun = true;
    else if (arg === '--force') opts.force = true;
    else if (arg === '--no-verify') opts.verify = false;
    else if (arg === '--webp') opts.webp = true;
    else if (arg === '--help' || arg === '-h') opts.help = true;
    else if (arg.startsWith('--jpeg-quality=')) opts.jpegQuality = Number(arg.slice(15));
    else if (arg.startsWith('--dir=')) opts.dir = resolve(ROOT, arg.slice(6));
    else throw new Error(`unknown option ${arg}`);
  }
  if (!Number.isInteger(opts.jpegQuality) || opts.jpegQuality < 30 || opts.jpegQuality > 100) {
    throw new Error('--jpeg-quality must be an integer between 30 and 100');
  }
  return opts;
}

// A --dir outside assets/ is refused rather than clamped: the caller asked for something this
// tool must not do, and quietly rewriting their argument would hide that.
function requireInsideAssets(dir) {
  // Links are resolved before the comparison, because a junction or symlink sitting inside
  // assets/ makes a path that is lexically contained and physically anywhere — including
  // backend/storage. A path that does not exist yet keeps its lexical form so a typo still
  // gets the plain "refusing to touch" message rather than an ENOENT.
  const real = (p) => { try { return realpathSync(p); } catch { return resolve(p); } };
  // relative() returns an absolute path when the two sides live on different Windows drives, so
  // a `--dir=C:/Users/...` from a repo on D: produces no leading `..` and would sail through a
  // startsWith('..') test. isAbsolute() is what catches that case.
  const rel = relative(real(ASSETS), real(dir));
  const escapes = isAbsolute(rel) || rel === '..' || rel.startsWith(`..${sep}`);
  if (rel !== '' && escapes) {
    throw new Error(`refusing to touch ${dir}: this tool only writes inside ${ASSETS}`);
  }
}

// ============================================================ small helpers

function sha256(buf) {
  return createHash('sha256').update(buf).digest('hex');
}

function walk(dir) {
  const out = [];
  const entries = readdirSync(dir, { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1));
  for (const entry of entries) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...walk(full));
    else if (entry.isFile()) out.push(full);
  }
  return out;
}

function fmtBytes(n) {
  if (n < 1024) return `${n} B`;
  if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / 1048576).toFixed(2)} MB`;
}

// ============================================================ PNG

const PNG_MAGIC = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
const PNG_CHANNELS = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 };
// The ancillary chunks worth keeping are the ones that change how the image renders.
// Everything else — tEXt/iTXt/zTXt/tIME/eXIf — is authoring residue or camera metadata.
const PNG_KEEP = new Set(['PLTE', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs']);

const CRC_TABLE = (() => {
  const t = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();

function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

function pngChunk(type, data) {
  const out = Buffer.alloc(12 + data.length);
  out.writeUInt32BE(data.length, 0);
  out.write(type, 4, 'latin1');
  data.copy(out, 8);
  out.writeUInt32BE(crc32(out.subarray(4, 8 + data.length)), 8 + data.length);
  return out;
}

function parsePng(buf) {
  if (buf.length < 8 || !buf.subarray(0, 8).equals(PNG_MAGIC)) throw new Error('not a PNG');
  const chunks = [];
  const idat = [];
  let o = 8;
  while (o + 8 <= buf.length) {
    const len = buf.readUInt32BE(o);
    const type = buf.toString('latin1', o + 4, o + 8);
    if (o + 12 + len > buf.length) throw new Error('truncated PNG chunk');
    const data = buf.subarray(o + 8, o + 8 + len);
    if (type === 'IDAT') idat.push(data);
    else if (type !== 'IEND') chunks.push({ type, data });
    o += 12 + len;
    if (type === 'IEND') break;
  }
  const ihdr = chunks.find((c) => c.type === 'IHDR');
  if (!ihdr || ihdr.data.length < 13) throw new Error('PNG has no usable IHDR');
  const head = {
    width: ihdr.data.readUInt32BE(0),
    height: ihdr.data.readUInt32BE(4),
    depth: ihdr.data[8],
    colorType: ihdr.data[9],
    compression: ihdr.data[10],
    filter: ihdr.data[11],
    interlace: ihdr.data[12],
  };
  if (!head.width || !head.height) throw new Error('PNG has zero dimensions');
  return { head, chunks, idat: Buffer.concat(idat) };
}

function bytesPerLine(width, channels, depth) {
  return Math.ceil((width * channels * depth) / 8);
}

function bytesPerPixel(channels, depth) {
  return Math.max(1, (channels * depth) >> 3);
}

// A PNG's zlib stream holds exactly one filter byte plus one scanline per row, so IHDR already
// states the only legal inflated size. Passing it as a ceiling means a crafted 3 MB file cannot
// make us allocate gigabytes before the length check downstream notices — the guard has to sit
// here, on the inflate itself, because by the time we can measure the output it is already in
// memory. MAX_RAW_BYTES then stops an absurd IHDR turning the ceiling itself into the attack.
const MAX_RAW_BYTES = 512 * 1024 * 1024;

function inflateScanlines(idat, height, bpl) {
  const expected = height * (bpl + 1);
  if (expected > MAX_RAW_BYTES) {
    throw new Error(`PNG would unpack to ${fmtBytes(expected)}, over the ${fmtBytes(MAX_RAW_BYTES)} ceiling`);
  }
  try {
    return zlib.inflateSync(idat, { maxOutputLength: expected });
  } catch (err) {
    // Node's own wording here is about a Buffer limit, which reads like a bug in this tool
    // rather than what it is: an image whose compressed stream does not match its own header.
    if (err.code === 'ERR_BUFFER_TOO_LARGE') {
      throw new Error(`PNG data unpacks past the ${expected} bytes its header declares`);
    }
    throw err;
  }
}

function unfilter(raw, height, bpp, bpl) {
  if (raw.length !== height * (bpl + 1)) throw new Error('PNG scanline length mismatch');
  const out = Buffer.alloc(height * bpl);
  let p = 0;
  for (let y = 0; y < height; y++) {
    const type = raw[p++];
    const row = y * bpl;
    const prev = row - bpl;
    for (let x = 0; x < bpl; x++) {
      const a = x >= bpp ? out[row + x - bpp] : 0;
      const b = y > 0 ? out[prev + x] : 0;
      const c = y > 0 && x >= bpp ? out[prev + x - bpp] : 0;
      let v = raw[p + x];
      if (type === 1) v += a;
      else if (type === 2) v += b;
      else if (type === 3) v += (a + b) >> 1;
      else if (type === 4) {
        const q = a + b - c;
        const pa = Math.abs(q - a), pb = Math.abs(q - b), pc = Math.abs(q - c);
        v += pa <= pb && pa <= pc ? a : pb <= pc ? b : c;
      } else if (type !== 0) throw new Error(`bad PNG filter type ${type}`);
      out[row + x] = v & 0xff;
    }
    p += bpl;
  }
  return out;
}

function filterRow(dst, data, row, prev, bpp, bpl, type, hasPrev) {
  dst[0] = type;
  for (let x = 0; x < bpl; x++) {
    const raw = data[row + x];
    const a = x >= bpp ? data[row + x - bpp] : 0;
    const b = hasPrev ? data[prev + x] : 0;
    const c = hasPrev && x >= bpp ? data[prev + x - bpp] : 0;
    let v;
    if (type === 0) v = raw;
    else if (type === 1) v = raw - a;
    else if (type === 2) v = raw - b;
    else if (type === 3) v = raw - ((a + b) >> 1);
    else {
      const q = a + b - c;
      const pa = Math.abs(q - a), pb = Math.abs(q - b), pc = Math.abs(q - c);
      v = raw - (pa <= pb && pa <= pc ? a : pb <= pc ? b : c);
    }
    dst[1 + x] = v & 0xff;
  }
}

const FILTER_STRATEGIES = ['none', 'sub', 'up', 'avg', 'paeth', 'minsum'];
const FIXED_FILTER = { none: 0, sub: 1, up: 2, avg: 3, paeth: 4 };

function applyFilters(data, height, bpp, bpl, strategy) {
  const out = Buffer.alloc(height * (bpl + 1));
  const cand = Buffer.alloc(bpl + 1);
  const fixed = FIXED_FILTER[strategy];
  for (let y = 0; y < height; y++) {
    const row = y * bpl;
    const prev = row - bpl;
    const dst = out.subarray(y * (bpl + 1), (y + 1) * (bpl + 1));
    if (fixed !== undefined) {
      filterRow(dst, data, row, prev, bpp, bpl, fixed, y > 0);
      continue;
    }
    // minsum: the conventional heuristic — take the filter whose output has the smallest sum
    // of absolute signed bytes, which tracks how well deflate will then do on the row.
    let best = 0, bestScore = Infinity;
    for (let type = 0; type <= 4; type++) {
      filterRow(cand, data, row, prev, bpp, bpl, type, y > 0);
      let score = 0;
      for (let x = 1; x <= bpl; x++) {
        const v = cand[x];
        score += v < 128 ? v : 256 - v;
      }
      if (score < bestScore) { bestScore = score; best = type; }
    }
    filterRow(dst, data, row, prev, bpp, bpl, best, y > 0);
  }
  return out;
}

const DEFLATE_SETTINGS = [
  { level: 9, strategy: zlib.constants.Z_DEFAULT_STRATEGY },
  { level: 9, strategy: zlib.constants.Z_FILTERED },
  { level: 9, strategy: zlib.constants.Z_RLE },
];

function packIndices(idx, width, height, depth) {
  const per = 8 / depth;
  const bpl = Math.ceil(width / per);
  const out = Buffer.alloc(bpl * height);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const o = y * bpl + Math.floor(x / per);
      out[o] |= idx[y * width + x] << (8 - depth - (x % per) * depth);
    }
  }
  return out;
}

// Lossless colour-type reductions. A logo exported as RGBA when nothing is transparent carries
// a whole redundant channel, and a flat-colour mark with <=256 distinct pixels is a palette
// image stored as truecolour. Both convert with zero change to any rendered pixel.
function reduceCandidates(head, pixels) {
  const { width, height, colorType } = head;
  const ch = PNG_CHANNELS[colorType];
  const n = width * height;
  const out = [{ colorType, depth: 8, data: pixels, plte: null, trns: null, label: 'as-is' }];
  let data = pixels, cur = colorType;

  if (colorType === 6 || colorType === 4) {
    let opaque = true;
    for (let i = 0; i < n && opaque; i++) if (pixels[i * ch + ch - 1] !== 255) opaque = false;
    if (opaque) {
      const nc = ch - 1;
      const stripped = Buffer.alloc(n * nc);
      for (let i = 0; i < n; i++) for (let c = 0; c < nc; c++) stripped[i * nc + c] = pixels[i * ch + c];
      cur = colorType === 6 ? 2 : 0;
      data = stripped;
      out.push({ colorType: cur, depth: 8, data, plte: null, trns: null, label: 'alpha dropped' });
    }
  }

  if (cur === 2) {
    let gray = true;
    for (let i = 0; i < n && gray; i++) {
      const o = i * 3;
      if (data[o] !== data[o + 1] || data[o + 1] !== data[o + 2]) gray = false;
    }
    if (gray) {
      const g = Buffer.alloc(n);
      for (let i = 0; i < n; i++) g[i] = data[i * 3];
      out.push({ colorType: 0, depth: 8, data: g, plte: null, trns: null, label: 'greyscale' });
    }
  }

  // The palette attempt runs on the original pixels so alpha can ride along in tRNS.
  if (colorType === 2 || colorType === 6) {
    const key = (o) => ((pixels[o] << 24) | (pixels[o + 1] << 16) | (pixels[o + 2] << 8) | (ch === 4 ? pixels[o + 3] : 255)) >>> 0;
    const seen = new Map();
    let ok = true;
    for (let i = 0; i < n; i++) {
      const k = key(i * ch);
      if (!seen.has(k)) {
        if (seen.size === 256) { ok = false; break; }
        const o = i * ch;
        seen.set(k, [pixels[o], pixels[o + 1], pixels[o + 2], ch === 4 ? pixels[o + 3] : 255]);
      }
    }
    if (ok && seen.size > 0) {
      // Transparent entries first so tRNS can stop after the last non-opaque index.
      const palette = [...seen.values()].sort((a, b) => a[3] - b[3]);
      const index = new Map();
      palette.forEach((c, i) => index.set(((c[0] << 24) | (c[1] << 16) | (c[2] << 8) | c[3]) >>> 0, i));
      const idx = Buffer.alloc(n);
      for (let i = 0; i < n; i++) idx[i] = index.get(key(i * ch));
      const plte = Buffer.alloc(palette.length * 3);
      palette.forEach((c, i) => { plte[i * 3] = c[0]; plte[i * 3 + 1] = c[1]; plte[i * 3 + 2] = c[2]; });
      let lastTrans = -1;
      palette.forEach((c, i) => { if (c[3] !== 255) lastTrans = i; });
      const trns = lastTrans >= 0 ? Buffer.from(palette.slice(0, lastTrans + 1).map((c) => c[3])) : null;
      for (const d of [1, 2, 4, 8]) {
        if (palette.length > 1 << d) continue;
        const packed = d === 8 ? idx : packIndices(idx, width, height, d);
        out.push({ colorType: 3, depth: d, data: packed, plte, trns, label: `palette ${palette.length}c ${d}bpp` });
        break;
      }
    }
  }
  return out;
}

function optimisePng(buf) {
  const { head, chunks, idat } = parsePng(buf);
  if (head.interlace !== 0) return { skip: 'interlaced PNG, left alone' };
  if (head.compression !== 0 || head.filter !== 0) return { skip: 'non-standard PNG compression, left alone' };
  const ch = PNG_CHANNELS[head.colorType];
  if (!ch) return { skip: `unsupported PNG colour type ${head.colorType}` };

  const bpl = bytesPerLine(head.width, ch, head.depth);
  const pixels = unfilter(inflateScanlines(idat, head.height, bpl), head.height, bytesPerPixel(ch, head.depth), bpl);

  const stripped = [];
  const kept = chunks.filter((c) => {
    if (c.type === 'IHDR') return false;
    if (PNG_KEEP.has(c.type)) return true;
    stripped.push(c.type);
    return false;
  });

  // A tRNS on truecolour is a colour key — "this exact RGB is transparent" — and it does not
  // survive palettising or a channel drop, so those images are left at their own colour type
  // rather than silently turned opaque. Colour types 4 and 6 carry a real alpha channel and the
  // spec forbids tRNS there, so only colour type 2 needs the exclusion.
  const colourKeyed = head.colorType === 2 && chunks.some((c) => c.type === 'tRNS');
  const reducible = head.depth === 8 && !colourKeyed && (head.colorType === 2 || head.colorType === 4 || head.colorType === 6);
  const candidates = reducible
    ? reduceCandidates(head, pixels)
    : [{ colorType: head.colorType, depth: head.depth, data: pixels, plte: null, trns: null, label: 'as-is' }];

  let best = null;
  for (const cand of candidates) {
    const cch = PNG_CHANNELS[cand.colorType];
    const cbpl = bytesPerLine(head.width, cch, cand.depth);
    const cbpp = bytesPerPixel(cch, cand.depth);
    for (const strategy of FILTER_STRATEGIES) {
      const filtered = applyFilters(cand.data, head.height, cbpp, cbpl, strategy);
      for (const dz of DEFLATE_SETTINGS) {
        const z = zlib.deflateSync(filtered, { level: dz.level, strategy: dz.strategy, memLevel: 9, windowBits: 15 });
        if (!best || z.length < best.z.length) best = { cand, z, strategy };
      }
    }
  }

  const c = best.cand;
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(head.width, 0);
  ihdr.writeUInt32BE(head.height, 4);
  ihdr[8] = c.depth;
  ihdr[9] = c.colorType;
  const parts = [PNG_MAGIC, pngChunk('IHDR', ihdr)];
  // A greyscale or palette result invalidates an RGB ICC profile and any sBIT written for the
  // old channel count, so those leave with the colour type instead of being carried forward.
  const changedType = c.colorType !== head.colorType || c.depth !== head.depth;
  for (const k of kept) {
    if (k.type === 'PLTE' || k.type === 'tRNS') continue;
    if (changedType && (k.type === 'sBIT' || (k.type === 'iCCP' && c.colorType !== 2))) {
      stripped.push(k.type);
      continue;
    }
    parts.push(pngChunk(k.type, k.data));
  }
  const oldPlte = kept.find((k) => k.type === 'PLTE');
  const oldTrns = kept.find((k) => k.type === 'tRNS');
  if (c.plte) parts.push(pngChunk('PLTE', c.plte));
  else if (oldPlte) parts.push(pngChunk('PLTE', oldPlte.data));
  if (c.trns) parts.push(pngChunk('tRNS', c.trns));
  else if (!c.plte && oldTrns) parts.push(pngChunk('tRNS', oldTrns.data));
  parts.push(pngChunk('IDAT', best.z), pngChunk('IEND', Buffer.alloc(0)));

  const bits = [c.label === 'as-is' ? null : c.label, `filter:${best.strategy}`].filter(Boolean);
  return {
    out: Buffer.concat(parts),
    note: bits.join(', '),
    stripped,
    pixels,
    srcHead: head,
    srcPlte: oldPlte && oldPlte.data,
    srcTrns: oldTrns && oldTrns.data,
  };
}

// Resolves pixel i of unfiltered data to [r, g, b, a] the way a decoder would. Both sides of
// the losslessness comparison go through this, so an indexed or sub-byte-depth image is read as
// colour rather than as raw index bytes — reading indices as RGBA is what made every palette
// PNG fail verification and get skipped.
function pixelReader(head, raw, plte, trns) {
  const ch = PNG_CHANNELS[head.colorType];
  const bpl = bytesPerLine(head.width, ch, head.depth);
  const opaque = head.depth === 16 ? 65535 : 255;

  const sample = (i, s) => {
    const row = Math.floor(i / head.width) * bpl;
    const x = i % head.width;
    if (head.depth === 16) return raw.readUInt16BE(row + (x * ch + s) * 2);
    if (head.depth === 8) return raw[row + x * ch + s];
    const per = 8 / head.depth;
    const byte = raw[row + Math.floor(x / per)];
    return (byte >> (8 - head.depth - (x % per) * head.depth)) & ((1 << head.depth) - 1);
  };

  // On colour types 0 and 2 a tRNS is a colour key rather than a table, so the alpha of a pixel
  // depends on its own value. Reading that here means losing a key shows up as an alpha
  // difference instead of passing as "pixels unchanged".
  const key = trns && (head.colorType === 0 || head.colorType === 2) && trns.length >= (head.colorType === 0 ? 2 : 6)
    ? (head.colorType === 0 ? [trns.readUInt16BE(0)] : [trns.readUInt16BE(0), trns.readUInt16BE(2), trns.readUInt16BE(4)])
    : null;

  return (i) => {
    if (head.colorType === 3) {
      const v = sample(i, 0);
      return [plte[v * 3], plte[v * 3 + 1], plte[v * 3 + 2], trns && v < trns.length ? trns[v] : 255];
    }
    if (head.colorType === 0) {
      const g = sample(i, 0);
      return [g, g, g, key && g === key[0] ? 0 : opaque];
    }
    if (head.colorType === 2) {
      const r = sample(i, 0), g = sample(i, 1), b = sample(i, 2);
      const clear = key && r === key[0] && g === key[1] && b === key[2];
      return [r, g, b, clear ? 0 : opaque];
    }
    if (head.colorType === 4) { const g = sample(i, 0); return [g, g, g, sample(i, 1)]; }
    return [sample(i, 0), sample(i, 1), sample(i, 2), sample(i, 3)];
  };
}

// Proof of losslessness: decode the file we are about to write and compare every pixel with
// the source. A palette or channel reduction that got its mapping wrong dies here, not in a
// browser. Fully transparent pixels compare on alpha alone — their RGB is never rendered.
function verifyPngLossless(out, srcHead, srcPixels, srcPlte, srcTrns) {
  const parsed = parsePng(out);
  const head = parsed.head;
  if (head.width !== srcHead.width || head.height !== srcHead.height) {
    throw new Error(`PNG dimensions changed: ${srcHead.width}x${srcHead.height} -> ${head.width}x${head.height}`);
  }
  const ch = PNG_CHANNELS[head.colorType];
  const bpl = bytesPerLine(head.width, ch, head.depth);
  const raw = unfilter(inflateScanlines(parsed.idat, head.height, bpl), head.height, bytesPerPixel(ch, head.depth), bpl);
  const plte = parsed.chunks.find((c) => c.type === 'PLTE');
  const trns = parsed.chunks.find((c) => c.type === 'tRNS');

  const readOut = pixelReader(head, raw, plte && plte.data, trns && trns.data);
  const readSrc = pixelReader(srcHead, srcPixels, srcPlte, srcTrns);

  for (let i = 0; i < head.width * head.height; i++) {
    const a = readOut(i), b = readSrc(i);
    if (a[3] !== b[3]) throw new Error(`PNG alpha differs at pixel ${i}`);
    if (a[3] === 0) continue;
    if (a[0] !== b[0] || a[1] !== b[1] || a[2] !== b[2]) throw new Error(`PNG colour differs at pixel ${i}`);
  }
  return { width: head.width, height: head.height, colorType: head.colorType, depth: head.depth };
}

// ============================================================ JPEG

// Zigzag position -> natural position. Everything below keeps coefficients and quantisation
// tables in zigzag order, which is the order DQT and the entropy coder use, so there is no
// transposition step to get wrong.
const ZIGZAG = [
  0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5,
  12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21, 28,
  35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51,
  58, 59, 52, 45, 38, 31, 39, 46, 53, 60, 61, 54, 47, 55, 62, 63,
];

const STD_LUMA = [
  16, 11, 10, 16, 24, 40, 51, 61, 12, 12, 14, 19, 26, 58, 60, 55,
  14, 13, 16, 24, 40, 57, 69, 56, 14, 17, 22, 29, 51, 87, 80, 62,
  18, 22, 37, 56, 68, 109, 103, 77, 24, 35, 55, 64, 81, 104, 113, 92,
  49, 64, 78, 87, 103, 121, 120, 101, 72, 92, 95, 98, 112, 100, 103, 99,
];

const STD_CHROMA = [
  17, 18, 24, 47, 99, 99, 99, 99, 18, 21, 26, 66, 99, 99, 99, 99,
  24, 26, 56, 99, 99, 99, 99, 99, 47, 66, 99, 99, 99, 99, 99, 99,
  99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99,
  99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99, 99,
];

function toZigzag(natural) {
  const out = new Int32Array(64);
  for (let k = 0; k < 64; k++) out[k] = natural[ZIGZAG[k]];
  return out;
}
const STD_LUMA_ZZ = toZigzag(STD_LUMA);
const STD_CHROMA_ZZ = toZigzag(STD_CHROMA);

// libjpeg's quality -> table scaling, so "quality 80" means the same thing here as it does in
// every other tool the client might compare against.
function targetTable(std, quality) {
  const scale = quality < 50 ? Math.floor(5000 / quality) : 200 - quality * 2;
  const out = new Int32Array(64);
  for (let k = 0; k < 64; k++) {
    out[k] = Math.min(255, Math.max(1, Math.floor((std[k] * scale + 50) / 100)));
  }
  return out;
}

function buildHuffDecoder(counts, values) {
  const mincode = new Int32Array(17);
  const maxcode = new Int32Array(17).fill(-1);
  const valptr = new Int32Array(17);
  let code = 0, k = 0;
  for (let l = 1; l <= 16; l++) {
    valptr[l] = k;
    mincode[l] = code;
    code += counts[l - 1];
    k += counts[l - 1];
    maxcode[l] = counts[l - 1] ? code - 1 : -1;
    code <<= 1;
  }
  return { mincode, maxcode, valptr, values };
}

class BitReader {
  constructor(buf, pos) {
    this.buf = buf;
    this.pos = pos;
    this.byte = 0;
    this.n = 0;
    this.hitMarker = false;
  }

  readBit() {
    if (this.n === 0) {
      if (this.pos >= this.buf.length) { this.hitMarker = true; return 0; }
      let b = this.buf[this.pos++];
      if (b === 0xff) {
        const next = this.buf[this.pos];
        if (next === 0x00) this.pos++;
        else { this.pos--; this.hitMarker = true; return 0; }
      }
      this.byte = b;
      this.n = 8;
    }
    this.n--;
    return (this.byte >> this.n) & 1;
  }

  align() { this.n = 0; }
}

function decodeHuff(br, tbl) {
  if (!tbl) throw new Error('scan references an undefined Huffman table');
  let code = br.readBit();
  let l = 1;
  while (code > tbl.maxcode[l]) {
    code = (code << 1) | br.readBit();
    l++;
    if (l > 16) throw new Error('bad Huffman code in scan');
  }
  const v = tbl.values[tbl.valptr[l] + code - tbl.mincode[l]];
  if (v === undefined) throw new Error('Huffman code outside table');
  return v;
}

function readDQT(seg, quant) {
  let p = 0;
  while (p < seg.length) {
    const pq = seg[p] >> 4, tq = seg[p] & 15;
    p++;
    const t = new Int32Array(64);
    for (let k = 0; k < 64; k++) t[k] = pq ? seg.readUInt16BE(p + k * 2) : seg[p + k];
    p += pq ? 128 : 64;
    quant[tq] = t;
  }
}

function readDHT(seg, huffDC, huffAC) {
  let p = 0;
  while (p < seg.length) {
    const tc = seg[p] >> 4, th = seg[p] & 15;
    p++;
    const counts = seg.subarray(p, p + 16);
    p += 16;
    let total = 0;
    for (let i = 0; i < 16; i++) total += counts[i];
    const values = seg.subarray(p, p + total);
    p += total;
    const table = buildHuffDecoder(counts, values);
    if (tc === 0) huffDC[th] = table;
    else huffAC[th] = table;
  }
}

function readSOF(seg, progressive) {
  const precision = seg[0];
  if (precision !== 8) throw new Error(`unsupported sample precision ${precision}`);
  const height = seg.readUInt16BE(1);
  const width = seg.readUInt16BE(3);
  const count = seg[5];
  if (!height || !width || !count) throw new Error('bad SOF dimensions');
  const components = [];
  let maxH = 1, maxV = 1;
  for (let i = 0; i < count; i++) {
    const id = seg[6 + i * 3];
    const h = seg[7 + i * 3] >> 4;
    const v = seg[7 + i * 3] & 15;
    if (!h || !v) throw new Error('bad sampling factors');
    maxH = Math.max(maxH, h);
    maxV = Math.max(maxV, v);
    components.push({ id, h, v, tq: seg[8 + i * 3] });
  }
  const mcusPerLine = Math.ceil(width / (8 * maxH));
  const mcusPerColumn = Math.ceil(height / (8 * maxV));
  for (const c of components) {
    c.blocksPerLine = Math.ceil(Math.ceil((width * c.h) / maxH) / 8);
    c.blocksPerColumn = Math.ceil(Math.ceil((height * c.v) / maxV) / 8);
    c.blocksPerLineForMcu = mcusPerLine * c.h;
    c.blocksPerColumnForMcu = mcusPerColumn * c.v;
    c.coefs = new Int16Array(c.blocksPerLineForMcu * c.blocksPerColumnForMcu * 64);
  }
  return { progressive, precision, width, height, components, maxH, maxV, mcusPerLine, mcusPerColumn };
}

function decodeScanData(buf, start, frame, scan, huffDC, huffAC, resetInterval) {
  const items = scan.comps.map((sc, idx) => ({ ...sc, idx, comp: frame.components[sc.ci] }));
  const single = items.length === 1;
  const { Ss, Se, Ah, Al } = scan;
  const br = new BitReader(buf, start);
  const pred = new Int32Array(items.length);
  let eobrun = 0;
  let acState = 0;
  let acNext = 0;

  const receive = (n) => {
    let v = 0;
    for (let i = 0; i < n; i++) v = (v << 1) | br.readBit();
    return v;
  };
  const receiveAndExtend = (n) => {
    if (n === 0) return 0;
    if (n === 1) return br.readBit() ? 1 : -1;
    const v = receive(n);
    return v < 1 << (n - 1) ? v - (1 << n) + 1 : v;
  };

  function baseline(it, coefs, off) {
    const t = decodeHuff(br, huffDC[it.dc]);
    pred[it.idx] += t === 0 ? 0 : receiveAndExtend(t);
    coefs[off] = pred[it.idx];
    let k = 1;
    while (k < 64) {
      const rs = decodeHuff(br, huffAC[it.ac]);
      const s = rs & 15, r = rs >> 4;
      if (s === 0) {
        if (r < 15) break;
        k += 16;
        continue;
      }
      k += r;
      if (k > 63) break;
      coefs[off + k] = receiveAndExtend(s);
      k++;
    }
  }

  function dcFirst(it, coefs, off) {
    const t = decodeHuff(br, huffDC[it.dc]);
    pred[it.idx] += t === 0 ? 0 : receiveAndExtend(t);
    coefs[off] = pred[it.idx] << Al;
  }

  function dcRefine(it, coefs, off) {
    if (br.readBit()) coefs[off] |= 1 << Al;
  }

  function acFirst(it, coefs, off) {
    if (eobrun > 0) { eobrun--; return; }
    let k = Ss;
    while (k <= Se) {
      const rs = decodeHuff(br, huffAC[it.ac]);
      const s = rs & 15, r = rs >> 4;
      if (s === 0) {
        if (r < 15) { eobrun = receive(r) + (1 << r) - 1; break; }
        k += 16;
        continue;
      }
      k += r;
      if (k > Se) break;
      coefs[off + k] = receiveAndExtend(s) * (1 << Al);
      k++;
    }
  }

  function acRefine(it, coefs, off) {
    let k = Ss;
    let r = 0;
    while (k <= Se) {
      const z = off + k;
      const sign = coefs[z] < 0 ? -1 : 1;
      if (acState === 0) {
        const rs = decodeHuff(br, huffAC[it.ac]);
        const s = rs & 15;
        r = rs >> 4;
        if (s === 0) {
          if (r < 15) { eobrun = receive(r) + (1 << r); acState = 4; }
          else { r = 16; acState = 1; }
        } else {
          if (s !== 1) throw new Error('invalid AC refinement encoding');
          acNext = br.readBit() ? 1 << Al : -(1 << Al);
          acState = r ? 2 : 3;
        }
        continue;
      }
      if (acState === 1 || acState === 2) {
        if (coefs[z]) coefs[z] += sign * (br.readBit() << Al);
        else {
          r--;
          if (r === 0) acState = acState === 2 ? 3 : 0;
        }
      } else if (acState === 3) {
        if (coefs[z]) coefs[z] += sign * (br.readBit() << Al);
        else { coefs[z] = acNext; acState = 0; }
      } else if (coefs[z]) {
        coefs[z] += sign * (br.readBit() << Al);
      }
      k++;
    }
    if (acState === 4) {
      eobrun--;
      if (eobrun === 0) acState = 0;
    }
  }

  let decodeBlock;
  if (!frame.progressive) decodeBlock = baseline;
  else if (Ss === 0) decodeBlock = Ah === 0 ? dcFirst : dcRefine;
  else decodeBlock = Ah === 0 ? acFirst : acRefine;

  if (frame.progressive && Ss > 0 && !single) throw new Error('progressive AC scan with multiple components');

  const total = single
    ? items[0].comp.blocksPerLine * items[0].comp.blocksPerColumn
    : frame.mcusPerLine * frame.mcusPerColumn;
  const interval = resetInterval > 0 ? resetInterval : total;
  let done = 0;
  while (done < total) {
    pred.fill(0);
    eobrun = 0;
    acState = 0;
    const stop = Math.min(total, done + interval);
    for (; done < stop; done++) {
      if (single) {
        const it = items[0], comp = it.comp;
        const row = Math.floor(done / comp.blocksPerLine);
        const col = done % comp.blocksPerLine;
        decodeBlock(it, comp.coefs, 64 * (row * comp.blocksPerLineForMcu + col));
      } else {
        const mcuRow = Math.floor(done / frame.mcusPerLine);
        const mcuCol = done % frame.mcusPerLine;
        for (const it of items) {
          const comp = it.comp;
          for (let j = 0; j < comp.v; j++) {
            for (let h = 0; h < comp.h; h++) {
              const row = mcuRow * comp.v + j;
              const col = mcuCol * comp.h + h;
              decodeBlock(it, comp.coefs, 64 * (row * comp.blocksPerLineForMcu + col));
            }
          }
        }
      }
    }
    br.align();
    if (done < total) {
      let p = br.pos;
      while (p < buf.length - 1 && !(buf[p] === 0xff && buf[p + 1] >= 0xd0 && buf[p + 1] <= 0xd7)) p++;
      if (p >= buf.length - 1) break;
      br.pos = p + 2;
      br.n = 0;
      br.hitMarker = false;
    }
  }

  // Walk to the next real marker; RSTn and stuffed FF00 belong to the scan we just read.
  let p = br.pos;
  while (p < buf.length - 1) {
    if (buf[p] === 0xff) {
      const m = buf[p + 1];
      if (m !== 0x00 && m !== 0xff && !(m >= 0xd0 && m <= 0xd7)) break;
    }
    p++;
  }
  return p;
}

function decodeJpeg(buf) {
  if (buf.length < 4 || buf[0] !== 0xff || buf[1] !== 0xd8) throw new Error('not a JPEG');
  const quant = [];
  const huffDC = [];
  const huffAC = [];
  const appSegments = [];
  let frame = null;
  let resetInterval = 0;
  let scanCount = 0;
  let o = 2;
  while (o < buf.length - 1) {
    if (buf[o] !== 0xff) { o++; continue; }
    const m = buf[o + 1];
    if (m === 0xff) { o++; continue; }
    if (m === 0xd8 || m === 0x01 || (m >= 0xd0 && m <= 0xd7)) { o += 2; continue; }
    if (m === 0xd9) break;
    if (o + 4 > buf.length) break;
    const len = buf.readUInt16BE(o + 2);
    if (len < 2 || o + 2 + len > buf.length) throw new Error('truncated JPEG segment');
    const seg = buf.subarray(o + 4, o + 2 + len);
    if (m >= 0xe0 && m <= 0xef) appSegments.push({ marker: m, data: seg });
    else if (m === 0xdb) readDQT(seg, quant);
    else if (m === 0xc4) readDHT(seg, huffDC, huffAC);
    else if (m === 0xdd) resetInterval = seg.readUInt16BE(0);
    else if (m === 0xc0 || m === 0xc1 || m === 0xc2) {
      if (frame) throw new Error('multi-frame JPEG');
      frame = readSOF(seg, m === 0xc2);
    } else if (m === 0xda) {
      if (!frame) throw new Error('scan before frame header');
      const ns = seg[0];
      const comps = [];
      for (let i = 0; i < ns; i++) {
        const id = seg[1 + i * 2];
        const tbl = seg[2 + i * 2];
        const ci = frame.components.findIndex((c) => c.id === id);
        if (ci < 0) throw new Error(`scan names unknown component ${id}`);
        comps.push({ ci, id, dc: tbl >> 4, ac: tbl & 15 });
      }
      const scan = { comps, Ss: seg[1 + ns * 2], Se: seg[2 + ns * 2], Ah: seg[3 + ns * 2] >> 4, Al: seg[3 + ns * 2] & 15 };
      o = decodeScanData(buf, o + 2 + len, frame, scan, huffDC, huffAC, resetInterval);
      scanCount++;
      continue;
    } else if (m === 0xfe) {
      // COM: authoring comment, dropped.
    } else if (m >= 0xc3 && m <= 0xcf) {
      throw new Error(`unsupported JPEG coding process (marker FF${m.toString(16).toUpperCase()})`);
    }
    o += 2 + len;
  }
  if (!frame) throw new Error('JPEG has no frame header');
  if (!scanCount) throw new Error('JPEG has no scan');
  for (const c of frame.components) if (!quant[c.tq]) throw new Error(`missing quantisation table ${c.tq}`);
  return { frame, quant, appSegments };
}

// --- ICC handling -----------------------------------------------------------
// An sRGB profile is what a browser assumes anyway, so dropping it costs nothing and saves
// ~3.1 KB a file. Adobe RGB and Display P3 are a different matter: strip those and the image
// is reinterpreted as sRGB and visibly shifts. So the rule is strip only what we can identify.

function iccDescription(profile) {
  if (profile.length < 132) return '';
  const count = profile.readUInt32BE(128);
  for (let i = 0; i < count; i++) {
    const o = 132 + i * 12;
    if (o + 12 > profile.length) break;
    if (profile.toString('latin1', o, o + 4) !== 'desc') continue;
    const off = profile.readUInt32BE(o + 4);
    const size = profile.readUInt32BE(o + 8);
    if (off + size > profile.length) break;
    const tag = profile.subarray(off, off + size);
    const type = tag.toString('latin1', 0, 4);
    if (type === 'desc' && tag.length > 12) {
      const len = tag.readUInt32BE(8);
      return tag.toString('latin1', 12, Math.min(tag.length, 12 + Math.max(0, len - 1)));
    }
    if (type === 'mluc' && tag.length > 28) {
      const len = tag.readUInt32BE(20);
      const strOff = tag.readUInt32BE(24);
      if (strOff + len <= tag.length && len % 2 === 0) {
        return Buffer.from(tag.subarray(strOff, strOff + len)).swap16().toString('utf16le').replace(/\0+$/, '');
      }
    }
    break;
  }
  return '';
}

function partitionAppSegments(appSegments) {
  const kept = [];
  const stripped = [];
  let iccDesc = '';
  const icc = appSegments.filter((s) => s.marker === 0xe2 && s.data.length > 14 && s.data.toString('latin1', 0, 11) === 'ICC_PROFILE');
  let dropIcc = false;
  if (icc.length) {
    const ordered = icc.slice().sort((a, b) => a.data[12] - b.data[12]);
    iccDesc = iccDescription(Buffer.concat(ordered.map((s) => s.data.subarray(14))));
    dropIcc = /\bsrgb\b/i.test(iccDesc) || /^srgb/i.test(iccDesc.trim());
  }
  for (const s of appSegments) {
    // APP0 JFIF is 14 bytes of density info and maximises decoder compatibility; APP14 Adobe
    // declares the colour transform, and dropping it can flip RGB/YCbCr interpretation.
    const isJfif = s.marker === 0xe0 && s.data.toString('latin1', 0, 4) === 'JFIF';
    const isAdobe = s.marker === 0xee && s.data.toString('latin1', 0, 5) === 'Adobe';
    const isIcc = icc.includes(s);
    if (isJfif || isAdobe || (isIcc && !dropIcc)) kept.push(s);
    else stripped.push(`APP${s.marker - 0xe0}${isIcc ? ' (ICC sRGB)' : ''}`);
  }
  return { kept, stripped, iccDesc, iccKept: icc.length > 0 && !dropIcc };
}

// --- requantisation ---------------------------------------------------------

// max(existing, target) per coefficient is what makes a re-run a no-op: the table can only
// move towards the target and never past it, so once a file is at the target nothing changes.
function buildNewQuantTables(frame, quant, quality) {
  const luma = targetTable(STD_LUMA_ZZ, quality);
  const chroma = targetTable(STD_CHROMA_ZZ, quality);
  const kind = new Map();
  frame.components.forEach((c, i) => {
    if (i === 0 || kind.get(c.tq) === 'luma') kind.set(c.tq, 'luma');
    else if (!kind.has(c.tq)) kind.set(c.tq, 'chroma');
  });
  const tables = new Map();
  let coarsened = false;
  for (const [tq, k] of kind) {
    const old = quant[tq];
    const target = k === 'luma' ? luma : chroma;
    const next = new Int32Array(64);
    for (let i = 0; i < 64; i++) {
      next[i] = Math.max(old[i], target[i]);
      if (next[i] !== old[i]) coarsened = true;
    }
    tables.set(tq, next);
  }
  return { tables, coarsened };
}

// Rescaling in the DCT domain, so there is no inverse/forward transform and therefore none of
// the generational blur a decode-and-re-encode would add. Positions where the table is
// unchanged keep their coefficient bit for bit.
function requantise(frame, quant, tables) {
  for (const c of frame.components) {
    const old = quant[c.tq];
    const next = tables.get(c.tq);
    const coefs = c.coefs;
    for (let k = 0; k < 64; k++) {
      const oq = old[k], nq = next[k];
      if (oq === nq) continue;
      for (let b = k; b < coefs.length; b += 64) {
        const v = coefs[b];
        if (v === 0) continue;
        const scaled = (v * oq) / nq;
        coefs[b] = scaled < 0 ? -Math.round(-scaled) : Math.round(scaled);
      }
    }
  }
}

// --- entropy encoding ------------------------------------------------------

class BitWriter {
  constructor() {
    this.b = Buffer.alloc(1 << 16);
    this.len = 0;
    this.acc = 0;
    this.nb = 0;
  }

  putBits(v, n) {
    if (n <= 0) return;
    this.acc = ((this.acc << n) | (v & ((1 << n) - 1))) >>> 0;
    this.nb += n;
    while (this.nb >= 8) {
      this.nb -= 8;
      const byte = (this.acc >>> this.nb) & 0xff;
      if (this.len + 2 > this.b.length) {
        const grown = Buffer.alloc(this.b.length * 2);
        this.b.copy(grown, 0, 0, this.len);
        this.b = grown;
      }
      this.b[this.len++] = byte;
      if (byte === 0xff) this.b[this.len++] = 0x00;
    }
    this.acc &= (1 << this.nb) - 1;
  }

  // JPEG pads the final byte of a scan with 1 bits.
  flush() {
    if (this.nb > 0) this.putBits((1 << (8 - this.nb)) - 1, 8 - this.nb);
  }

  buffer() { return this.b.subarray(0, this.len); }
}

// libjpeg's jpeg_gen_optimal_table: a package-merge style code-length assignment, then the
// standard reshuffle that forces every code to 16 bits or fewer. freq[256] is a reserved slot
// so the all-ones codeword is never handed to a real symbol (some decoders choke on it).
function buildOptimalHuffTable(freqIn) {
  let used = 0;
  for (let i = 0; i < 256; i++) if (freqIn[i]) used++;
  if (used === 0) {
    const counts = new Uint8Array(16);
    counts[0] = 1;
    return { counts, values: Uint8Array.from([0]) };
  }
  const freq = new Int32Array(257);
  for (let i = 0; i < 256; i++) freq[i] = freqIn[i];
  freq[256] = 1;
  const codesize = new Int32Array(257);
  const others = new Int32Array(257).fill(-1);
  for (;;) {
    let v1 = -1, v1f = Infinity;
    for (let i = 0; i <= 256; i++) if (freq[i] && freq[i] <= v1f) { v1f = freq[i]; v1 = i; }
    let v2 = -1, v2f = Infinity;
    for (let i = 0; i <= 256; i++) if (freq[i] && i !== v1 && freq[i] <= v2f) { v2f = freq[i]; v2 = i; }
    if (v2 < 0) break;
    freq[v1] += freq[v2];
    freq[v2] = 0;
    codesize[v1]++;
    while (others[v1] >= 0) { v1 = others[v1]; codesize[v1]++; }
    others[v1] = v2;
    codesize[v2]++;
    while (others[v2] >= 0) { v2 = others[v2]; codesize[v2]++; }
  }
  const bits = new Int32Array(33);
  for (let i = 0; i <= 256; i++) {
    if (!codesize[i]) continue;
    if (codesize[i] > 32) throw new Error('Huffman code length overflow');
    bits[codesize[i]]++;
  }
  for (let i = 32; i > 16; i--) {
    while (bits[i] > 0) {
      let j = i - 2;
      while (bits[j] === 0) j--;
      bits[i] -= 2;
      bits[i - 1] += 1;
      bits[j + 1] += 2;
      bits[j] -= 1;
    }
  }
  let last = 16;
  while (last > 0 && bits[last] === 0) last--;
  bits[last]--;
  const counts = new Uint8Array(16);
  for (let l = 1; l <= 16; l++) counts[l - 1] = bits[l];
  const values = [];
  for (let l = 1; l <= 32; l++) for (let v = 0; v < 256; v++) if (codesize[v] === l) values.push(v);
  let total = 0;
  for (let l = 0; l < 16; l++) total += counts[l];
  if (total !== values.length) throw new Error('Huffman table symbol count mismatch');
  return { counts, values: Uint8Array.from(values) };
}

function buildEncodeTable(counts, values) {
  const enc = new Array(256).fill(null);
  let code = 0, k = 0;
  for (let l = 1; l <= 16; l++) {
    for (let i = 0; i < counts[l - 1]; i++) enc[values[k++]] = [code++, l];
    code <<= 1;
  }
  return enc;
}

function jpegMarker(m, data) {
  const out = Buffer.alloc(4 + data.length);
  out[0] = 0xff;
  out[1] = m;
  out.writeUInt16BE(2 + data.length, 2);
  data.copy(out, 4);
  return out;
}

function dqtSegment(entries) {
  const parts = [];
  for (const e of entries) {
    const d = Buffer.alloc(65);
    d[0] = e.tq;
    for (let k = 0; k < 64; k++) d[1 + k] = Math.min(255, Math.max(1, e.table[k]));
    parts.push(d);
  }
  return jpegMarker(0xdb, Buffer.concat(parts));
}

function sofSegment(frame) {
  const d = Buffer.alloc(6 + frame.components.length * 3);
  d[0] = 8;
  d.writeUInt16BE(frame.height, 1);
  d.writeUInt16BE(frame.width, 3);
  d[5] = frame.components.length;
  frame.components.forEach((c, i) => {
    d[6 + i * 3] = c.id;
    d[7 + i * 3] = (c.h << 4) | c.v;
    d[8 + i * 3] = c.tq;
  });
  return jpegMarker(0xc2, d);
}

function sosSegment(scan) {
  const n = scan.comps.length;
  const d = Buffer.alloc(4 + n * 2);
  d[0] = n;
  scan.comps.forEach((c, i) => {
    d[1 + i * 2] = c.id;
    d[2 + i * 2] = (c.dc << 4) | c.ac;
  });
  d[1 + n * 2] = scan.Ss;
  d[2 + n * 2] = scan.Se;
  d[3 + n * 2] = (scan.Ah << 4) | scan.Al;
  return jpegMarker(0xda, d);
}

function dhtSegment(tc, th, counts, values) {
  const d = Buffer.alloc(17 + values.length);
  d[0] = (tc << 4) | th;
  for (let i = 0; i < 16; i++) d[1 + i] = counts[i];
  Buffer.from(values).copy(d, 17);
  return jpegMarker(0xc4, d);
}

// One pass over a scan's blocks. Called twice: once with a counting sink to gather Huffman
// statistics, once with a writing sink. The two passes must walk identically, which is why the
// EOB-run and correction-bit bookkeeping lives here rather than in the sinks.
function runScan(frame, scan, sink) {
  const items = scan.comps.map((sc, idx) => ({ ...sc, idx, comp: frame.components[sc.ci] }));
  const single = items.length === 1;
  const { Ss, Se, Ah, Al } = scan;
  const pred = new Int32Array(items.length);
  const corr = [];
  let eobrun = 0;

  function emitEobrun(it) {
    if (eobrun <= 0) return;
    let t = eobrun, nbits = 0;
    while ((t >>= 1)) nbits++;
    sink.sym(it, nbits << 4);
    if (nbits) sink.bits(eobrun, nbits);
    eobrun = 0;
    for (const b of corr) sink.bits(b, 1);
    corr.length = 0;
  }

  function dcFirst(it, coefs, off) {
    const v = coefs[off] >> Al;
    let temp = v - pred[it.idx];
    let temp2 = temp;
    pred[it.idx] = v;
    if (temp < 0) { temp = -temp; temp2--; }
    let nbits = 0;
    while (temp) { nbits++; temp >>= 1; }
    sink.sym(it, nbits);
    if (nbits) sink.bits(temp2, nbits);
  }

  function dcRefine(it, coefs, off) {
    sink.bits((coefs[off] >> Al) & 1, 1);
  }

  function acFirst(it, coefs, off) {
    let r = 0;
    for (let k = Ss; k <= Se; k++) {
      let temp = coefs[off + k];
      if (temp === 0) { r++; continue; }
      let temp2;
      if (temp < 0) { temp = -temp; temp >>= Al; temp2 = ~temp; }
      else { temp >>= Al; temp2 = temp; }
      if (temp === 0) { r++; continue; }
      emitEobrun(it);
      while (r > 15) { sink.sym(it, 0xf0); r -= 16; }
      let nbits = 1;
      while ((temp >>= 1)) nbits++;
      sink.sym(it, (r << 4) + nbits);
      sink.bits(temp2 & ((1 << nbits) - 1), nbits);
      r = 0;
    }
    if (r > 0) {
      eobrun++;
      if (eobrun === 0x7fff) emitEobrun(it);
    }
  }

  const absv = new Int32Array(64);
  function acRefine(it, coefs, off) {
    let eob = 0;
    for (let k = Ss; k <= Se; k++) {
      let t = coefs[off + k];
      if (t < 0) t = -t;
      t >>= Al;
      absv[k] = t;
      if (t === 1) eob = k;
    }
    const fresh = [];
    let r = 0;
    for (let k = Ss; k <= Se; k++) {
      const temp = absv[k];
      if (temp === 0) { r++; continue; }
      while (r > 15 && k <= eob) {
        emitEobrun(it);
        sink.sym(it, 0xf0);
        r -= 16;
        for (const b of fresh) sink.bits(b, 1);
        fresh.length = 0;
      }
      // Already-significant coefficients only need one more bit of their magnitude, and that
      // bit is buffered until a real code goes out — the decoder reads them in that order.
      if (temp > 1) { fresh.push(temp & 1); continue; }
      emitEobrun(it);
      sink.sym(it, (r << 4) + 1);
      sink.bits(coefs[off + k] < 0 ? 0 : 1, 1);
      for (const b of fresh) sink.bits(b, 1);
      fresh.length = 0;
      r = 0;
    }
    if (r > 0 || fresh.length > 0) {
      eobrun++;
      for (const b of fresh) corr.push(b);
      if (eobrun === 0x7fff || corr.length > 937) emitEobrun(it);
    }
  }

  let encodeBlock;
  if (Ss === 0) encodeBlock = Ah === 0 ? dcFirst : dcRefine;
  else encodeBlock = Ah === 0 ? acFirst : acRefine;

  const total = single
    ? items[0].comp.blocksPerLine * items[0].comp.blocksPerColumn
    : frame.mcusPerLine * frame.mcusPerColumn;
  for (let u = 0; u < total; u++) {
    if (single) {
      const it = items[0], comp = it.comp;
      const row = Math.floor(u / comp.blocksPerLine);
      const col = u % comp.blocksPerLine;
      encodeBlock(it, comp.coefs, 64 * (row * comp.blocksPerLineForMcu + col));
    } else {
      const mcuRow = Math.floor(u / frame.mcusPerLine);
      const mcuCol = u % frame.mcusPerLine;
      for (const it of items) {
        const comp = it.comp;
        for (let j = 0; j < comp.v; j++) {
          for (let h = 0; h < comp.h; h++) {
            const row = mcuRow * comp.v + j;
            const col = mcuCol * comp.h + h;
            encodeBlock(it, comp.coefs, 64 * (row * comp.blocksPerLineForMcu + col));
          }
        }
      }
    }
  }
  emitEobrun(items[0]);
}

function encodeScan(frame, scan) {
  const bw = new BitWriter();
  const isDc = scan.Ss === 0;

  // A DC refinement scan is raw bits with no Huffman coding at all, so it needs no table.
  if (isDc && scan.Ah > 0) {
    runScan(frame, scan, {
      sym() { throw new Error('unexpected Huffman symbol in DC refinement scan'); },
      bits: (v, n) => bw.putBits(v, n),
    });
    bw.flush();
    return [sosSegment(scan), Buffer.from(bw.buffer())];
  }

  const freqs = scan.comps.map(() => new Int32Array(257));
  runScan(frame, scan, { sym: (it, s) => { freqs[it.idx][s]++; }, bits() {} });

  // Components sharing a table slot share statistics — the slot is what the decoder sees.
  const perSlot = new Map();
  scan.comps.forEach((c, i) => {
    const slot = isDc ? c.dc : c.ac;
    if (!perSlot.has(slot)) perSlot.set(slot, new Int32Array(257));
    const acc = perSlot.get(slot);
    for (let s = 0; s < 257; s++) acc[s] += freqs[i][s];
  });

  const parts = [];
  const encBySlot = new Map();
  for (const [slot, freq] of perSlot) {
    const t = buildOptimalHuffTable(freq);
    parts.push(dhtSegment(isDc ? 0 : 1, slot, t.counts, t.values));
    encBySlot.set(slot, buildEncodeTable(t.counts, t.values));
  }
  const encs = scan.comps.map((c) => encBySlot.get(isDc ? c.dc : c.ac));

  parts.push(sosSegment(scan));
  runScan(frame, scan, {
    sym(it, s) {
      const e = encs[it.idx][s];
      if (!e) throw new Error(`symbol ${s} absent from generated Huffman table`);
      bw.putBits(e[0], e[1]);
    },
    bits: (v, n) => bw.putBits(v, n),
  });
  bw.flush();
  parts.push(Buffer.from(bw.buffer()));
  return parts;
}

// Two scan scripts. "sa" is libjpeg's default progression (successive approximation), which is
// what these photos were originally encoded with; "ss" is spectral selection only, which needs
// no refinement scans. Both are tried and the smaller output wins.
function progressiveScript(frame, successive) {
  const n = frame.components.length;
  const mk = (cis, Ss, Se, Ah, Al) => ({
    comps: cis.map((ci) => ({ ci, id: frame.components[ci].id, dc: ci === 0 ? 0 : 1, ac: ci === 0 ? 0 : 1 })),
    Ss, Se, Ah, Al,
  });
  if (n === 1) {
    return successive
      ? [mk([0], 0, 0, 0, 1), mk([0], 1, 5, 0, 2), mk([0], 6, 63, 0, 2), mk([0], 1, 63, 2, 1), mk([0], 0, 0, 1, 0), mk([0], 1, 63, 1, 0)]
      : [mk([0], 0, 0, 0, 0), mk([0], 1, 5, 0, 0), mk([0], 6, 63, 0, 0)];
  }
  const all = frame.components.map((_, i) => i);
  if (!successive) {
    const s = [mk(all, 0, 0, 0, 0), mk([0], 1, 5, 0, 0), mk([0], 6, 63, 0, 0)];
    for (let i = 1; i < n; i++) s.push(mk([i], 1, 63, 0, 0));
    return s;
  }
  const s = [mk(all, 0, 0, 0, 1), mk([0], 1, 5, 0, 2)];
  for (let i = n - 1; i >= 1; i--) s.push(mk([i], 1, 63, 0, 1));
  s.push(mk([0], 6, 63, 0, 2), mk([0], 1, 63, 2, 1), mk(all, 0, 0, 1, 0));
  for (let i = n - 1; i >= 1; i--) s.push(mk([i], 1, 63, 1, 0));
  s.push(mk([0], 1, 63, 1, 0));
  return s;
}

function encodeJpeg(frame, tables, kept, script) {
  const parts = [Buffer.from([0xff, 0xd8])];
  for (const app of kept) parts.push(jpegMarker(app.marker, app.data));
  const slots = [...new Set(frame.components.map((c) => c.tq))].sort((a, b) => a - b);
  parts.push(dqtSegment(slots.map((tq) => ({ tq, table: tables.get(tq) }))));
  parts.push(sofSegment(frame));
  for (const scan of script) parts.push(...encodeScan(frame, scan));
  parts.push(Buffer.from([0xff, 0xd9]));
  return Buffer.concat(parts);
}

function optimiseJpeg(buf, quality) {
  const { frame, quant, appSegments } = decodeJpeg(buf);
  const { tables, coarsened } = buildNewQuantTables(frame, quant, quality);
  if (coarsened) requantise(frame, quant, tables);
  const apps = partitionAppSegments(appSegments);

  let best = null;
  for (const successive of [true, false]) {
    const out = encodeJpeg(frame, tables, apps.kept, progressiveScript(frame, successive));
    if (!best || out.length < best.out.length) best = { out, successive };
  }

  const notes = [coarsened ? `requantised to q<=${quality}` : 'quantisation already at target'];
  notes.push(best.successive ? 'progressive/sa' : 'progressive/ss');
  if (apps.iccKept) notes.push(`ICC kept (${apps.iccDesc || 'unidentified'})`);
  return {
    out: best.out,
    note: notes.join(', '),
    stripped: apps.stripped,
    frame,
    tables,
    lossless: !coarsened,
  };
}

// The entropy layer either round-trips exactly or the file is corrupt, so decode what we just
// built and compare every coefficient against what we meant to write. A Huffman or EOB-run
// mistake cannot survive this.
function verifyJpegCoefficients(out, frame, tables) {
  const back = decodeJpeg(out);
  if (back.frame.width !== frame.width || back.frame.height !== frame.height) {
    throw new Error(`JPEG dimensions changed: ${frame.width}x${frame.height} -> ${back.frame.width}x${back.frame.height}`);
  }
  if (back.frame.components.length !== frame.components.length) throw new Error('JPEG component count changed');
  for (let i = 0; i < frame.components.length; i++) {
    const a = frame.components[i];
    const b = back.frame.components[i];
    if (a.h !== b.h || a.v !== b.v) throw new Error('JPEG sampling factors changed');
    const wanted = tables.get(a.tq);
    const got = back.quant[b.tq];
    for (let k = 0; k < 64; k++) if (wanted[k] !== got[k]) throw new Error(`quantisation table ${a.tq} differs at ${k}`);
    if (a.coefs.length !== b.coefs.length) throw new Error('JPEG coefficient array size changed');
    for (let j = 0; j < a.coefs.length; j++) {
      if (a.coefs[j] !== b.coefs[j]) {
        throw new Error(`coefficient ${j} of component ${i} differs: ${a.coefs[j]} -> ${b.coefs[j]}`);
      }
    }
  }
  return { width: back.frame.width, height: back.frame.height, progressive: back.frame.progressive };
}

// ============================================================ optional WebP

// cwebp is used if and only if it is already installed. It is never required, never installed,
// and never looked up by a name that collides with something else — on Windows `convert` is the
// NTFS filesystem tool, so only the exact name cwebp is probed.
function findCwebp() {
  const names = process.platform === 'win32' ? ['cwebp.exe', 'cwebp.cmd'] : ['cwebp'];
  for (const dir of (process.env.PATH || '').split(delimiter)) {
    if (!dir) continue;
    for (const name of names) {
      const full = join(dir, name);
      try {
        if (statSync(full).isFile()) return full;
      } catch { /* not here */ }
    }
  }
  return null;
}

async function emitWebp(cwebp, file, isPng) {
  const { spawnSync } = await import('node:child_process');
  const target = file.replace(/\.(png|jpe?g)$/i, '.webp');
  // Lossless for PNG so a logo keeps its exact pixels; quality-based for photographs.
  const args = isPng ? ['-lossless', '-z', '9', '-quiet', file, '-o', target] : ['-q', '82', '-quiet', file, '-o', target];
  const res = spawnSync(cwebp, args, { shell: false, stdio: 'ignore' });
  if (res.status !== 0) return null;
  return { target, size: statSync(target).size };
}

// ============================================================ driver

function printTable(rows) {
  const w = {
    file: Math.max(4, ...rows.map((r) => r.file.length)),
    before: Math.max(6, ...rows.map((r) => fmtBytes(r.before).length)),
    after: Math.max(5, ...rows.map((r) => fmtBytes(r.after).length)),
  };
  const head = `${'file'.padEnd(w.file)}  ${'before'.padStart(w.before)}  ${'after'.padStart(w.after)}  ${'saved'.padStart(11)}  note`;
  process.stdout.write(`${head}\n${'-'.repeat(head.length)}\n`);
  for (const r of rows) {
    const saved = r.before - r.after;
    const pct = r.before ? ((saved / r.before) * 100).toFixed(1) : '0.0';
    const savedText = saved > 0 ? `${fmtBytes(saved)} ${pct}%`.padStart(11) : '-'.padStart(11);
    process.stdout.write(`${r.file.padEnd(w.file)}  ${fmtBytes(r.before).padStart(w.before)}  ${fmtBytes(r.after).padStart(w.after)}  ${savedText}  ${r.note}\n`);
  }
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) { process.stdout.write(HELP); return 0; }
  requireInsideAssets(opts.dir);

  let manifest;
  try {
    manifest = JSON.parse(readFileSync(MANIFEST, 'utf8'));
    if (manifest.tool !== TOOL_VERSION || typeof manifest.files !== 'object') manifest = null;
  } catch { manifest = null; }
  if (!manifest) manifest = { tool: TOOL_VERSION, files: {} };

  const files = walk(opts.dir).filter((f) => /\.(png|jpe?g)$/i.test(f));
  if (!files.length) {
    process.stdout.write(`no PNG or JPEG files under ${opts.dir}\n`);
    return 0;
  }

  const cwebp = opts.webp ? findCwebp() : null;
  if (opts.webp && !cwebp) {
    process.stdout.write('--webp asked for but no cwebp on PATH; skipping the .webp siblings\n\n');
  }

  const rows = [];
  let totalBefore = 0;
  let totalAfter = 0;
  let failures = 0;

  for (const file of files) {
    const rel = relative(ROOT, file).replace(/\\/g, '/');
    const source = readFileSync(file);
    const digest = sha256(source);
    totalBefore += source.length;

    const record = manifest.files[rel];
    if (!opts.force && record && record.sha256 === digest && record.quality === opts.jpegQuality) {
      totalAfter += source.length;
      rows.push({ file: basename(file), before: source.length, after: source.length, note: 'already optimised' });
      continue;
    }

    const isPng = /\.png$/i.test(file);
    let result;
    try {
      result = isPng ? optimisePng(source) : optimiseJpeg(source, opts.jpegQuality);
    } catch (err) {
      failures++;
      totalAfter += source.length;
      rows.push({ file: basename(file), before: source.length, after: source.length, note: `LEFT ALONE: ${err.message}` });
      continue;
    }

    if (result.skip) {
      totalAfter += source.length;
      rows.push({ file: basename(file), before: source.length, after: source.length, note: result.skip });
      continue;
    }

    let dims;
    if (opts.verify) {
      try {
        dims = isPng
          ? verifyPngLossless(result.out, result.srcHead, result.pixels, result.srcPlte, result.srcTrns)
          : verifyJpegCoefficients(result.out, result.frame, result.tables);
      } catch (err) {
        failures++;
        totalAfter += source.length;
        rows.push({ file: basename(file), before: source.length, after: source.length, note: `LEFT ALONE, verify failed: ${err.message}` });
        continue;
      }
    }

    // Never trade a smaller original for a larger "optimised" file.
    if (result.out.length >= source.length) {
      totalAfter += source.length;
      const why = result.out.length === source.length ? 'already minimal' : `no gain (would grow ${fmtBytes(result.out.length - source.length)})`;
      rows.push({ file: basename(file), before: source.length, after: source.length, note: why });
      if (!opts.dryRun) manifest.files[rel] = { sha256: digest, quality: opts.jpegQuality, bytes: source.length };
      continue;
    }

    const notes = [result.note];
    if (result.stripped && result.stripped.length) notes.push(`stripped ${[...new Set(result.stripped)].join('+')}`);
    if (dims && dims.width) notes.push(`${dims.width}x${dims.height} ok`);

    if (!opts.dryRun) {
      writeFileSync(file, result.out);
      manifest.files[rel] = { sha256: sha256(result.out), quality: opts.jpegQuality, bytes: result.out.length };
      if (cwebp) {
        const webp = await emitWebp(cwebp, file, isPng);
        if (webp) notes.push(`webp ${fmtBytes(webp.size)}`);
      }
    }
    totalAfter += result.out.length;
    rows.push({ file: basename(file), before: source.length, after: result.out.length, note: notes.join(', ') });
  }

  printTable(rows);
  const saved = totalBefore - totalAfter;
  const pct = totalBefore ? ((saved / totalBefore) * 100).toFixed(1) : '0.0';
  process.stdout.write(`\n${files.length} files: ${fmtBytes(totalBefore)} -> ${fmtBytes(totalAfter)}, saved ${fmtBytes(saved)} (${pct}%)\n`);
  if (opts.dryRun) process.stdout.write('dry run: nothing was written\n');
  else writeFileSync(MANIFEST, `${JSON.stringify(manifest, null, 2)}\n`);
  if (failures) process.stdout.write(`${failures} file(s) were left untouched because they could not be handled safely\n`);
  return failures ? 1 : 0;
}

// exitCode rather than exit(): stdout is an async pipe on Linux, and process.exit() there can
// cut the table off mid-write when the output is piped into anything. Nothing holds the event
// loop open, so the process still ends immediately.
main().then(
  (code) => { process.exitCode = code; },
  (err) => {
    process.stderr.write(`optimise-images: ${err.message}\n`);
    process.exitCode = 1;
  },
);
