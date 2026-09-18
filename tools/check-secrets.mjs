// Refuse to ship a credential in this repository.
//
// gitleaks already runs in CI and it is good at KEYED formats - AWS ids, GitHub
// tokens, PEM blocks. It is blind to the thing that actually leaked here: a
// human writing a password into a Markdown file. `VFI@123`, the super-admin
// placeholder, sat in the tracked, PUBLIC Developer_requier.md for weeks with
// gitleaks green over it. This closes that gap.
//
// Run: node tools/check-secrets.mjs      (exit 1 = something looks exposed)
//
// Two checks:
//   1. No credential FILE is tracked - .env, private keys, keystores.
//   2. No credential VALUE appears in a tracked doc, script or config.
//
// Tuned against a full audit of this repo's history, so the noise it learned to
// ignore is real noise seen here: PHP class names (AdminAuthController),
// Heroicon enum members, GitHub action refs (docker/login-action@v3), file
// names (student-verify.html), and Cloudflare's published Turnstile test key.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const git = (...args) =>
  execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });

/* ---------------------------------------------------------------- 1. files */

// Anything matching these must never be tracked. .env.example is the documented
// exception: it holds keys with empty or placeholder values, by design.
const FORBIDDEN_PATH =
  /(^|\/)\.env($|\.(?!example))|\.(pem|ppk|p12|pfx|jks|keystore)$|(^|\/)id_(rsa|dsa|ecdsa|ed25519)($|\.)/i;

const tracked = git('ls-files').split('\n').filter(Boolean);
const badFiles = tracked.filter((f) => FORBIDDEN_PATH.test(f));

/* --------------------------------------------------------------- 2. values */

// Only files a person hand-writes. Built output and dependencies are excluded:
// they are full of camelCase identifiers that look like secrets and are not.
const SCANNABLE = /\.(md|txt|sh|ya?ml|conf|ini|json|env\.example)$/i;
const NOT_OURS =
  /(node_modules|\/vendor\/|backend\/public\/js\/|_nuxt\/|^admin-panel\/|package-lock|composer\.lock|\.playwright-mcp\/)/i;

// A line has to be TALKING about a credential before a token on it is suspicious.
const CUE = /(password|passwd|secret|token|credential|api[_ -]?key|apikey|\bssh\b)/i;

// Two shapes of hand-written secret.
//
// The dot and hyphen are INSIDE the symbolic class deliberately. Without them
// the match stops at the first dot, so `ssh vfi@103.14.23.151` arrives as the
// fragment `vfi@103` and `superadmin@vfi-fc.com` as `superadmin@vfi` - both of
// which then look like a word-plus-symbol secret and neither of which any
// sane allow-rule matches. Capturing the whole address lets the email and
// user@host rules below recognise them for what they are. Fixing the tokeniser
// beats allow-listing its mistakes.
const SYMBOLIC = /\b[A-Za-z][A-Za-z0-9.-]{1,}[!@#$%^&*][A-Za-z0-9!@#$%^&*.-]{2,}\b/g;
const LONG_MIXED =
  /\b(?=[A-Za-z0-9]*[a-z])(?=[A-Za-z0-9]*[A-Z])(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{14,40}\b/g;

// Known-safe, each with the reason it is safe.
const ALLOW = [
  [/^1x0+A+$/i, 'Cloudflare Turnstile published test key'],
  [/^2x0+A+$/i, 'Cloudflare Turnstile published test key'],
  [/^[A-Z][A-Za-z]+(Controller|Service|Resource|Request|Provider|Middleware|Command|Job|Policy|Scope|Test)(@[a-zA-Z]+)?$/,
    'PHP class name, optionally Class@method as route docs write it'],
  [/^(Admin|Student|Partner|Staff|Content|Program|Application|Agency|University)[A-Za-z]*$/,
    'PascalCase class or enum name'],
  [/^[a-z][\w-]*@\d{1,3}(\.\d{1,3}){0,3}$/i, 'user@host from an ssh example'],
  [/^(Outline|Solid|Mini)[A-Za-z0-9]*$/, 'Heroicon enum member'],
  [/^[a-z-]+\/[a-z-]+@v\d+$/i, 'GitHub Action reference'],
  [/^[\w-]+\.(html|php|js|css|md|json|ya?ml|sh|png|jpg|svg)$/i, 'file name'],
  [/^\$argon2/, 'argon2 hash prefix, not a password'],
  [/^(your|example|changeme|placeholder|dummy|sample|test)/i, 'placeholder'],
  [/^[A-Za-z]+@\d+(\.\d+)+$/, 'user@host, not a password'],
  [/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/, 'email address'],
  [/^(authentication|authorization|credentials|passwordless|passwordreset|tokenizer)$/i,
    'ordinary word'],
];

const allowed = (tok) => ALLOW.some(([re]) => re.test(tok));
const mask = (s) => s.slice(0, 2) + '*'.repeat(Math.max(0, s.length - 3)) + s.slice(-1);

const findings = [];
for (const file of tracked) {
  if (!SCANNABLE.test(file) || NOT_OURS.test(file)) continue;
  let text;
  try {
    text = readFileSync(file, 'utf8');
  } catch {
    continue;
  }
  text.split('\n').forEach((line, i) => {
    if (!CUE.test(line)) return;
    const tokens = new Set([...(line.match(SYMBOLIC) || []), ...(line.match(LONG_MIXED) || [])]);
    for (const tok of tokens) {
      if (allowed(tok) || tok.includes('http') || tok.includes('/')) continue;
      findings.push({ file, line: i + 1, tok });
    }
  });
}

/* ---------------------------------------------------------------- report */

if (badFiles.length) {
  console.log('SECRET FILES ARE TRACKED — these must never be committed:');
  for (const f of badFiles) console.log('  ' + f);
  console.log('');
}
if (findings.length) {
  console.log('CREDENTIAL-SHAPED VALUES in tracked files (masked — check each one):');
  for (const f of findings) console.log(`  ${mask(f.tok).padEnd(26)} ${f.file}:${f.line}`);
  console.log('');
  console.log('If one is genuinely safe, add it to ALLOW in tools/check-secrets.mjs');
  console.log('WITH THE REASON. Do not silence this by deleting the check.');
}

const total = badFiles.length + findings.length;
if (total === 0) {
  console.log(`check-secrets: clean — ${tracked.length} tracked files, no credential files, no credential values.`);
  process.exit(0);
}
console.log(`check-secrets: ${total} problem(s) found.`);
process.exit(1);
