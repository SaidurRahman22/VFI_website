#!/usr/bin/env node
/**
 * Build the staff admin panel and place it where nginx serves it.
 *
 * WHY THE OUTPUT IS COMMITTED
 * Deploy is git-sync: a cron on the VPS pulls this repo and runs composer and
 * migrations. There is no Node on the server and no build step, so the built
 * panel has to be in the repository. That is a real cost - every build adds its
 * assets to history - and it is the price of a deploy model with no build stage.
 * Nuxt fingerprints every asset, so old files are replaced rather than
 * accumulating under the same names.
 *
 * WHY THE SOURCE LIVES UNDER tools/
 * The repo root IS the web root. nginx denies /tools/ (alongside /backend/,
 * /deploy/, /docs/ and /test/), so the .vue source, the composables and
 * node_modules are never web-readable. Putting the source anywhere else would
 * have needed another nginx rule.
 *
 *   source  tools/admin-panel/          (denied by nginx, build-time only)
 *   output  admin-panel/                (served, fingerprinted assets)
 *
 * Usage:  node tools/build-admin-panel.mjs [--skip-build]
 *         --skip-build re-syncs the last build without rebuilding.
 */
import { execSync } from 'node:child_process'
import { cpSync, existsSync, mkdirSync, readdirSync, rmSync, statSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '..')
const SRC = join(REPO, 'tools', 'admin-panel')
const BUILT = join(SRC, '.output', 'public')
const DEST = join(REPO, 'admin-panel')

const skipBuild = process.argv.includes('--skip-build')

function bytes(dir) {
  let total = 0
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name)
    total += entry.isDirectory() ? bytes(p) : statSync(p).size
  }

  return total
}

function kb(n) {
  return `${Math.round(n / 1024).toLocaleString()} KB`
}

if (!existsSync(join(SRC, 'node_modules'))) {
  console.error(`Dependencies are not installed.\n  cd tools/admin-panel && npm install`)
  process.exit(1)
}

if (!skipBuild) {
  console.log('building the panel (nuxt generate)…')
  try {
    // stdio inherited: a build failure has to be readable, not swallowed.
    execSync('npx nuxt generate', { cwd: SRC, stdio: 'inherit' })
  }
  catch {
    console.error('\nbuild failed — nothing was copied, the deployed panel is untouched.')
    process.exit(1)
  }
}

if (!existsSync(BUILT)) {
  console.error(`No build output at ${BUILT}. Run without --skip-build.`)
  process.exit(1)
}

// Replaced wholesale rather than merged: a merge leaves last build's orphaned
// pages and chunks behind, and a stale prerendered page is indistinguishable
// from a current one.
if (existsSync(DEST))
  rmSync(DEST, { recursive: true, force: true })

mkdirSync(DEST, { recursive: true })
cpSync(BUILT, DEST, { recursive: true })

const pages = readdirSync(DEST, { recursive: true })
  .filter(f => String(f).endsWith('index.html') || String(f) === '404.html')

console.log(`\ncopied  ${kb(bytes(BUILT))}  ->  admin-panel/`)
console.log(`pages   ${pages.length}`)
for (const p of pages)
  console.log(`   /admin-panel/${String(p).replace(/\\/g, '/').replace(/index\.html$/, '')}`)
console.log('\nCommit admin-panel/ for the change to reach the server.')
