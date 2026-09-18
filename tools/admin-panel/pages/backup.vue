<script setup>
/*
  Take a copy of everything the site says, and put one back.

  The legacy admin page had a backup button that serialised the editor's own
  localStorage, so it copied one browser's private draft - and restoring it
  somewhere else pushed whatever had been typed in that browser. This talks to
  /api/admin/backup, which reads and writes the database every visitor sees.

  Four things this screen has to get right, because only reading BackupService
  reveals them and each one is expensive to learn the hard way:

    OWNER ONLY. AdminBackupController calls isOwner(), and App\Models\User
    defines isOwner() as exactly isSuperAdmin() - the same signal /api/admin/me
    reports as is_superadmin and the same one pages.vue asks. It is not an
    ability in StaffAbilities, so there is nothing to grant: a content editor
    who may rewrite every word on the site cannot take a copy of it.

    THE FILE IS MADE HERE. The controller sets a Content-Disposition header, but
    useVfiApi returns parsed JSON - fetch already consumed the response and the
    browser was never offered a download. So the JSON is re-serialised into a
    Blob and a synthetic link at an object URL is clicked.

    RESTORE IS A FULL REPLACE, AND ABSENCE COUNTS. BackupService::restore
    empties every collection table and refills it from the file, so a
    collection the file does not mention ends up EMPTY rather than untouched.
    Singletons behave the opposite way: a key that is absent is skipped and
    keeps its current value. Nothing in the file announces that asymmetry, and
    it deletes content silently, so the preview names every collection a chosen
    file is missing before anything is sent.

    THE SNAPSHOT IS NOT SELF-SERVICE. BackupService::snapshot writes the current
    state to Storage::disk('local'), whose root is storage_path('app/private').
    Nothing serves that directory - `php artisan storage:link` only exposes
    app/public - and there is no route that lists or reads a snapshot back. So
    "recoverable" means someone with file access to the VPS fetching that file;
    it is written in the same shape as an export, so once it is off the server
    any owner can put it back through this screen. The screen has to say that,
    because the path alone reads like a download link. No endpoint lists those
    paths afterwards either, so the one the response carries is put on screen
    and left there instead of folded into a passing toast.

    THE GOLD CANNOT CARRY THE WORDS. A tonal alert paints its own colour as the
    text over a 16% tint of itself (.v-alert--variant-tonal sets color: inherit
    and fills __underlay with currentColor at --v-activated-opacity), so a
    warning alert is gold text on gold-tinted white: 1.81:1. The plain
    `text-warning` class is 2.03:1 on a white card. Every sentence on this
    screen that says something is deleted is therefore carried in
    `text-high-emphasis` - the theme's own ink at 90%, 10-13:1 on the light
    surface and 7-10:1 on the dark one - while the alert's `type` keeps the
    icon and the tint that say how bad the news is. `text-error` is not used
    for that job: #e04b3c on the dark theme's navy is 3.86:1.
*/
definePageMeta({ title: 'Backup' })

const api = useVfiApi()
const { load: loadUser, isSuperAdmin } = useVfiUser()

/*
  The keys a backup carries, with names a person recognises.

  BackupService::COLLECTIONS and ::SINGLETONS are the authority; the export
  endpoint does not describe itself the way the content endpoints send their
  schema, so this is the one list the panel has to repeat. It decides only what
  is DRAWN - every count is read out of the actual file. A key added on the
  server that is missing here surfaces as an unrecognised key on screen, which
  is why unknown keys are listed rather than quietly dropped.
*/
const COLLECTIONS = [
  { key: 'events', label: 'Events' },
  { key: 'blogs', label: 'Blog posts' },
  { key: 'news', label: 'News & updates' },
  { key: 'photos', label: 'Photo gallery' },
  { key: 'ppManagers', label: 'Regional managers' },
  { key: 'ppUpdates', label: 'Important updates' },
  { key: 'ppQuicklinks', label: 'Quick links' },
  { key: 'ppDocs', label: 'Learning documents' },
  { key: 'ppEmails', label: 'Email updates' },
  { key: 'ppNotifs', label: 'Notifications' },
]

const SINGLETONS = [
  { key: 'settings', label: 'Site settings' },
  { key: 'countries', label: 'Country pages' },
  { key: 'regions', label: 'Region pages' },
  { key: 'servicesPage', label: 'Services page' },
  { key: 'partnerPage', label: 'Partner page' },
  { key: 'partnerPortal', label: 'Partner console text' },
  { key: 'media', label: 'Home-page images' },
  { key: 'pages', label: 'Pages on and off' },
]

const NAMED = new Set([...COLLECTIONS, ...SINGLETONS].map(x => x.key))

/* AdminBackupController::MAX_IMPORT_BYTES, so the estimate can be compared. */
const MAX_IMPORT_BYTES = 8 * 1024 * 1024

/*
  Past this a file is not even read: pulling it into a string would lock the tab
  for something the server refuses anyway. Well above the 8 MB payload cap
  because a pretty-printed export is several times its own compact size.
*/
const MAX_FILE_BYTES = 32 * 1024 * 1024

/* Typed out in full, so a restore cannot happen by clicking through. */
const CONFIRM_PHRASE = 'REPLACE'

const booting = ref(true)
const allowed = ref(false)

const exporting = ref(false)
const exportError = ref(null)
const exported = ref(null)

const file = ref(null)
const reading = ref(false)
const fileError = ref(null)
const staged = ref(null)

const confirmOpen = ref(false)
const typed = ref('')
const restoring = ref(false)
const restoreError = ref(null)
const restored = ref(null)

const confirmed = computed(() => typed.value.trim().toUpperCase() === CONFIRM_PHRASE)

/* Nothing recognised means the server's validate() would refuse it outright. */
const restorable = computed(() => Boolean(staged.value) && staged.value.summary.recognised > 0)

function isPlainObject(v) {
  return Boolean(v) && typeof v === 'object' && !Array.isArray(v)
}

/*
  The same choice BackupService::validate makes on its first line
  (`$content = $payload['content'] ?? $payload`): a file may be the whole export
  envelope or just the content object, and the preview is worthless unless it
  reads the one the server will read.
*/
function contentOf(payload) {
  if (!isPlainObject(payload) && !Array.isArray(payload))
    return null

  const inner = Object.prototype.hasOwnProperty.call(payload, 'content') ? payload.content : payload

  // A `content` that is not an object at all is the server's "Backup has no
  // content object." An array gets through here and is reported as having
  // nothing recognisable in it, which is what the server concludes too.
  return (isPlainObject(inner) || Array.isArray(inner)) ? inner : null
}

/* What is actually in a content object, key by key. */
function summarise(content) {
  const has = k => Object.prototype.hasOwnProperty.call(content, k)
  const stranger = Object.keys(content).filter(k => !NAMED.has(k))

  const collections = COLLECTIONS.map(c => ({
    ...c,
    present: has(c.key),
    count: Array.isArray(content[c.key]) ? content[c.key].length : null,
  }))

  const singletons = SINGLETONS.map(s => ({
    ...s,
    present: has(s.key),
    count: isPlainObject(content[s.key]) ? Object.keys(content[s.key]).length : null,
  }))

  return {
    collections,
    singletons,
    items: collections.reduce((n, c) => n + (c.count || 0), 0),
    recognised: [...collections, ...singletons].filter(x => x.present).length,

    // Collections the file leaves out are the dangerous case: restore wipes
    // them regardless of whether the file has anything to put back.
    emptied: collections.filter(c => !c.present),

    // Present but the wrong shape. A collection that is not a list is refused
    // by name; a singleton that is not an object is stored as-is, which is
    // almost certainly not what the person means.
    badLists: collections.filter(c => c.present && c.count === null),
    oddSingletons: singletons.filter(s => s.present && s.count === null),

    // Capped for display: a JSON array reaches here with one "key" per element,
    // and naming four thousand of them helps nobody.
    unknown: stranger.slice(0, 8),
    unknownCount: stranger.length,
  }
}

function bytes(n) {
  if (n < 1024)
    return `${n} bytes`
  if (n < 1024 * 1024)
    return `${Math.round(n / 1024)} KB`

  return `${(n / (1024 * 1024)).toFixed(1)} MB`
}

/* Byte length of the compact JSON, which is what the server measures. */
function payloadBytes(payload) {
  return new Blob([JSON.stringify(payload)]).size
}

function when(iso) {
  // Guarded rather than trusted: `new Date(null)` is 1 January 1970, not an
  // invalid date, so a backup with no exportedAt would read as 55 years old.
  if (!iso)
    return null

  const d = new Date(iso)

  return Number.isNaN(d.getTime()) ? null : d.toLocaleString()
}

/* A name that says what it is and when it was taken, for a file people keep. */
function fileNameFor(iso) {
  const d = iso ? new Date(iso) : new Date()
  const at = Number.isNaN(d.getTime()) ? new Date() : d
  const p = n => String(n).padStart(2, '0')

  return `vfi-backup-${at.getFullYear()}-${p(at.getMonth() + 1)}-${p(at.getDate())}-${p(at.getHours())}${p(at.getMinutes())}.json`
}

function download(name, text) {
  const url = URL.createObjectURL(new Blob([text], { type: 'application/json' }))
  const a = document.createElement('a')

  a.href = url
  a.download = name

  // In the document because a click on a detached link is ignored in Firefox.
  document.body.appendChild(a)
  a.click()
  a.remove()

  // Not revoked straight away: the download is started asynchronously and
  // Safari cancels it if the object URL has already gone.
  setTimeout(() => URL.revokeObjectURL(url), 30000)
}

async function runExport() {
  exporting.value = true
  exportError.value = null
  exported.value = null

  try {
    const payload = await api.get('/api/admin/backup/export')
    const content = contentOf(payload)

    if (!content) {
      exportError.value = 'The server answered, but not with a backup. Nothing was saved.'

      return
    }

    // Indented on the way out: this is a file someone may open in an editor to
    // check a value, and it costs only bytes on disk.
    const text = JSON.stringify(payload, null, 2)
    const name = fileNameFor(payload.exportedAt)

    download(name, text)

    exported.value = {
      name,
      at: typeof payload.exportedAt === 'string' ? payload.exportedAt : null,
      bytes: new Blob([text]).size,
      summary: summarise(content),
    }
  }
  catch (e) {
    exportError.value = e.message || 'Could not export the site content.'
  }
  finally {
    exporting.value = false
  }
}

async function choose(files) {
  const picked = Array.isArray(files) ? files[0] : files

  staged.value = null
  fileError.value = null
  restoreError.value = null
  restored.value = null
  typed.value = ''

  if (!picked)
    return

  if (picked.size > MAX_FILE_BYTES) {
    fileError.value = `That file is ${bytes(picked.size)}. A backup of this site is a fraction of that, so this is almost certainly not one.`

    return
  }

  reading.value = true

  try {
    const text = await picked.text()
    let payload = null

    try {
      payload = JSON.parse(text)
    }
    catch {
      fileError.value = 'That file is not valid JSON, so there is nothing to read in it. Choose a file this screen produced.'

      return
    }

    const content = contentOf(payload)

    if (!content) {
      fileError.value = 'That file has no content object. A backup is either the whole export or its "content" object — this is neither.'

      return
    }

    staged.value = {
      name: picked.name,
      payload,
      at: typeof payload.exportedAt === 'string' ? payload.exportedAt : null,
      bytes: payloadBytes(payload),
      summary: summarise(content),
    }
  }
  catch {
    fileError.value = 'That file could not be read.'
  }
  finally {
    reading.value = false
  }
}

function openConfirm() {
  typed.value = ''
  restoreError.value = null
  confirmOpen.value = true
}

async function restore() {
  if (!staged.value || !confirmed.value)
    return

  restoring.value = true
  restoreError.value = null

  try {
    // Sent under `payload` rather than spread at the top level: the controller
    // falls back to the whole request body, and a backup with a key called
    // "payload" in it would then be read as the payload itself.
    const res = await api.post('/api/admin/backup/import', { payload: staged.value.payload })

    restored.value = {
      // Read off the response rather than assumed: if a future change stops
      // returning it, the screen has to say the path is unknown rather than
      // print an empty box where the one way back used to be.
      snapshot: typeof res?.snapshot === 'string' ? res.snapshot : null,
      from: staged.value.name,
      items: staged.value.summary.items,
      at: new Date().toLocaleString(),
    }

    confirmOpen.value = false
    staged.value = null
    file.value = null
    typed.value = ''
  }
  catch (e) {
    /*
      Verbatim, and left in the dialog rather than replacing it. The 422s name
      what is wrong - which collection is not a list, that it does not look
      like VFI content, that it is too large - and a generic "restore failed"
      would hide which of those happened. Nothing was changed: the controller
      validates before it snapshots, so a refusal costs the site nothing.
    */
    restoreError.value = e.message
  }
  finally {
    restoring.value = false
  }
}

onMounted(async () => {
  // AWAIT the user first: reading the role before /api/admin/me answers is
  // what once made a screen claim no access while the API returned rows.
  await loadUser()
  allowed.value = isSuperAdmin.value
  booting.value = false
})
</script>

<template>
  <div>
    <!--
      Nothing is drawn until /api/admin/me has answered. Every other screen can
      afford to render and fill itself in; here the first button would already
      be one click away from downloading the whole site.
    -->
    <div
      v-if="booting"
      class="d-flex align-center gap-2 py-4"
    >
      <VProgressCircular
        indeterminate
        size="20"
      />
      <span class="text-body-2">Checking your access…</span>
    </div>

    <VAlert
      v-else-if="!allowed"
      type="info"
      variant="tonal"
    >
      Only the account owner can export or restore the site's content.
    </VAlert>

    <template v-else>
      <div class="mb-6">
        <h4 class="text-h4 mb-1">
          Backup
        </h4>
        <p class="text-body-2 mb-0 text-medium-emphasis">
          Download a copy of everything the site says, or put a copy back.
        </p>
      </div>

      <!-- 👉 export -->
      <VCard class="mb-6">
        <VCardItem>
          <VCardTitle>Download a backup</VCardTitle>
          <VCardSubtitle>One JSON file, taken from the database the site actually serves.</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 mb-4">
            The file holds the ten content collections and the eight stored records behind the
            page text, the home-page image slots and the pages on/off list. It does not hold the
            uploaded pictures themselves — those stay as files on the server and the backup only
            names them, so a restore re-points the same images rather than re-creating them.
          </p>

          <VBtn
            :loading="exporting"
            prepend-icon="ri-download-2-line"
            @click="runExport"
          >
            Download backup
          </VBtn>

          <VAlert
            v-if="exportError"
            type="error"
            variant="tonal"
            class="mt-4"
            closable
            @click:close="exportError = null"
          >
            {{ exportError }}
          </VAlert>

          <div
            v-if="exported"
            class="mt-4"
          >
            <VAlert
              type="success"
              variant="tonal"
              class="mb-4"
            >
              <p class="mb-1">
                Saved to your downloads as <code>{{ exported.name }}</code>.
              </p>
              <p class="text-body-2 mb-0">
                {{ exported.summary.items }}
                {{ exported.summary.items === 1 ? 'item' : 'items' }} across the collections,
                {{ bytes(exported.bytes) }}<span v-if="when(exported.at)">, taken {{ when(exported.at) }}</span>.
              </p>
            </VAlert>

            <p class="text-body-2 text-medium-emphasis mb-2">
              What is in the file:
            </p>
            <!--
              An empty collection takes NO colour, not `secondary`: that slot is
              the logo crimson in this theme, so "Events: 0" arrived as a red
              chip inside the green "saved" panel and read as a failure on the
              one screen whose job is to say the file is complete. A tonal chip
              with no colour inherits the card's ink and tints itself with it -
              the panel's own neutral, the same as the queue chips on the
              dashboard - and nothing here is wrong: a site with no events
              really does back up as zero events.
            -->
            <div class="d-flex flex-wrap gap-2 mb-4">
              <VChip
                v-for="c in exported.summary.collections"
                :key="c.key"
                size="small"
                variant="tonal"
                :color="c.count ? 'primary' : null"
              >
                {{ c.label }}: {{ c.count === null ? '—' : c.count }}
              </VChip>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <VChip
                v-for="s in exported.summary.singletons"
                :key="s.key"
                size="small"
                variant="tonal"
                :color="s.count ? 'primary' : null"
              >
                {{ s.label }}: {{ s.count === null ? '—' : s.count }}
                {{ s.count === 1 ? 'field' : 'fields' }}
              </VChip>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- 👉 restore -->
      <VCard>
        <VCardItem>
          <VCardTitle>Restore from a backup</VCardTitle>
          <VCardSubtitle>Replaces all site content with the contents of a file.</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <!--
            type="error", not "warning": this is the sentence that says what gets
            deleted, and the gold could not be read on top of its own tint. The
            words are in the theme's ink either way — see the note in the header.
          -->
          <VAlert
            type="error"
            variant="tonal"
            class="mb-4"
          >
            <p class="mb-2 text-high-emphasis">
              <strong>Everything currently on the site is replaced.</strong>
              Every event, post, news item, photo and every word of partner-console content is
              deleted and rebuilt from the file. Anything that exists now and is not in the file
              stops appearing on the public website the moment this finishes.
            </p>
            <p class="text-body-2 mb-0 text-high-emphasis">
              Before it changes anything the server saves the site's current content to a file in
              its own private storage and hands back the path. That file is not on the website and
              nothing in this panel can open it: undoing a mistake means asking whoever
              administers the server to fetch it for you. It is written in the same format as a
              backup, so once you have it you can restore it here. If you would rather hold a copy
              yourself, download a backup above first.
            </p>
          </VAlert>

          <VFileInput
            v-model="file"
            label="Choose a backup file"
            accept="application/json,.json"
            prepend-icon=""
            prepend-inner-icon="ri-upload-2-line"
            :loading="reading"
            hint="Nothing is sent until you confirm below."
            persistent-hint
            hide-details="auto"
            @update:model-value="choose"
          />

          <VAlert
            v-if="fileError"
            type="error"
            variant="tonal"
            class="mt-4"
            closable
            @click:close="fileError = null"
          >
            {{ fileError }}
          </VAlert>

          <VAlert
            v-if="restoreError"
            type="error"
            variant="tonal"
            class="mt-4"
            closable
            @click:close="restoreError = null"
          >
            <p class="mb-1">
              The server refused this backup and nothing was changed:
            </p>
            <p class="mb-0">
              <strong>{{ restoreError }}</strong>
            </p>
          </VAlert>

          <VAlert
            v-if="restored"
            type="success"
            variant="tonal"
            class="mt-4"
          >
            <p class="mb-2">
              Restored from <strong>{{ restored.from }}</strong> at {{ restored.at }}.
              That file carried {{ restored.items }}
              {{ restored.items === 1 ? 'item' : 'items' }}, and the site is serving it now.
            </p>
            <template v-if="restored.snapshot">
              <p class="mb-2">
                The content the site held before this is saved on the server as:<br>
                <code>{{ restored.snapshot }}</code>
              </p>
              <p class="text-body-2 mb-0">
                That is a path inside the server's private storage
                (<code>storage/app/private</code>), not an address on the website — opening it in
                a browser will not work, and nothing in this panel can fetch it. If this restore
                was a mistake, ask whoever administers the server to send you that file: it is
                written in the same format as a backup, so you can then put it back through the
                box above. Nothing lists the path again, so write it down now.
              </p>
            </template>
            <p
              v-else
              class="text-body-2 mb-0"
            >
              The server did not say where it saved the previous content. It always writes that
              snapshot before restoring, so the file exists — but finding it means asking whoever
              administers the server to look in the <code>backups/</code> folder of
              <code>storage/app/private</code> for the newest file.
            </p>
          </VAlert>

          <!-- 👉 what is in the chosen file, read here, before anything is sent -->
          <template v-if="staged">
            <VDivider class="my-6" />

            <div class="d-flex flex-wrap align-center justify-space-between gap-2 mb-1">
              <h6 class="text-h6 mb-0">
                What is in {{ staged.name }}
              </h6>
              <span class="text-body-2 text-medium-emphasis">
                {{ bytes(staged.bytes) }}<span v-if="when(staged.at)"> · taken {{ when(staged.at) }}</span>
              </span>
            </div>
            <p class="text-body-2 text-medium-emphasis mb-4">
              Read from the file in this browser. Nothing has been sent to the server yet.
            </p>

            <VAlert
              v-if="!restorable"
              type="error"
              variant="tonal"
              class="mb-4"
            >
              This file contains none of the keys a VFI backup has, so there is nothing in it to
              restore and the server would refuse it. Choose a file this screen produced.
            </VAlert>

            <VAlert
              v-if="staged.summary.badLists.length"
              type="error"
              variant="tonal"
              class="mb-4"
            >
              {{ staged.summary.badLists.map(c => c.label).join(', ') }}
              {{ staged.summary.badLists.length === 1 ? 'is' : 'are' }} in this file but not as a
              list of items. The server refuses a backup shaped like that, and will say which
              collection is wrong.
            </VAlert>

            <!-- Data loss, named. Red for the same reason as the panel above. -->
            <VAlert
              v-if="staged.summary.emptied.length"
              type="error"
              variant="tonal"
              class="mb-4"
            >
              <p class="mb-1 text-high-emphasis">
                <strong>{{ staged.summary.emptied.length }} of the 10 collections
                  {{ staged.summary.emptied.length === 1 ? 'is' : 'are' }} missing from this
                  file, and restoring will empty {{ staged.summary.emptied.length === 1 ? 'it' : 'them' }}:</strong>
              </p>
              <p class="mb-0 text-high-emphasis">
                {{ staged.summary.emptied.map(c => c.label).join(', ') }}
              </p>
            </VAlert>

            <!-- A genuine caution rather than deletion, so the gold icon stays. -->
            <VAlert
              v-if="staged.summary.oddSingletons.length"
              type="warning"
              variant="tonal"
              class="mb-4"
            >
              <p class="mb-0 text-high-emphasis">
                {{ staged.summary.oddSingletons.map(s => s.label).join(', ') }}
                {{ staged.summary.oddSingletons.length === 1 ? 'is' : 'are' }} in this file but
                not stored as a set of fields. Whatever is there will be saved as-is.
              </p>
            </VAlert>

            <VAlert
              v-if="staged.summary.unknownCount"
              type="info"
              variant="tonal"
              class="mb-4"
            >
              This file also carries {{ staged.summary.unknownCount }}
              {{ staged.summary.unknownCount === 1 ? 'key' : 'keys' }} the site does not
              recognise and will ignore: {{ staged.summary.unknown.join(', ')
              }}<span v-if="staged.summary.unknownCount > staged.summary.unknown.length">, and
                {{ staged.summary.unknownCount - staged.summary.unknown.length }} more</span>.
            </VAlert>

            <VAlert
              v-if="staged.bytes > MAX_IMPORT_BYTES"
              type="warning"
              variant="tonal"
              class="mb-4"
            >
              <p class="mb-0 text-high-emphasis">
                This backup is about {{ bytes(staged.bytes) }} and the server refuses anything
                over 8 MB, so it will most likely be turned away. The server has the final word
                on the exact size and its answer is shown here.
              </p>
            </VAlert>

            <VTable class="mb-4">
              <thead>
                <tr>
                  <th>Collection</th>
                  <th class="text-end">
                    Items in the file
                  </th>
                  <th>What restoring does</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="c in staged.summary.collections"
                  :key="c.key"
                >
                  <td>{{ c.label }}</td>
                  <td class="text-end">
                    <span v-if="c.count !== null">{{ c.count }}</span>
                    <span
                      v-else-if="c.present"
                      class="text-error"
                    >wrong shape</span>
                    <span
                      v-else
                      class="text-medium-emphasis"
                    >not in the file</span>
                  </td>
                  <td class="text-body-2">
                    <span v-if="c.count">Replaces what is on the site now</span>
                    <span
                      v-else-if="c.present && c.count === null"
                      class="text-error"
                    >Nothing — the server refuses the whole restore</span>
                    <!--
                      Bold ink, not the gold, and bolder than the refusal above
                      it: this is the only row that loses data, and gold on the
                      white card measured 2.03:1 — unreadable, on the one line
                      that says something is deleted. No amber in this palette is
                      dark enough for 4.5:1 and still amber, so weight carries
                      the emphasis instead of hue.
                    -->
                    <span
                      v-else
                      class="text-high-emphasis font-weight-bold"
                    >Empties this collection</span>
                  </td>
                </tr>
              </tbody>
            </VTable>

            <VTable class="mb-6">
              <thead>
                <tr>
                  <th>Stored record</th>
                  <th class="text-end">
                    Fields in the file
                  </th>
                  <th>What restoring does</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="s in staged.summary.singletons"
                  :key="s.key"
                >
                  <td>{{ s.label }}</td>
                  <td class="text-end">
                    <span v-if="s.count !== null">{{ s.count }}</span>
                    <!-- Odd, not fatal — and the gold was 2.03:1 here too. -->
                    <span
                      v-else-if="s.present"
                      class="text-high-emphasis font-weight-medium"
                    >wrong shape</span>
                    <span
                      v-else
                      class="text-medium-emphasis"
                    >not in the file</span>
                  </td>
                  <td class="text-body-2">
                    <span v-if="s.present">Overwrites what is stored now</span>
                    <span
                      v-else
                      class="text-medium-emphasis"
                    >Left exactly as it is</span>
                  </td>
                </tr>
              </tbody>
            </VTable>

            <VBtn
              color="error"
              :disabled="!restorable"
              prepend-icon="ri-upload-2-line"
              @click="openConfirm"
            >
              Replace all site content
            </VBtn>
          </template>
        </VCardText>
      </VCard>

      <!--
        👉 the confirmation. A VDialog, never a VNavigationDrawer: this
        template's shell sits outside Vuetify's layout system, so a drawer is
        never given an open position and simply never appears.
      -->
      <VDialog
        :model-value="confirmOpen"
        max-width="600"
        scrollable
        @update:model-value="v => { if (!v && !restoring) confirmOpen = false }"
      >
        <VCard v-if="staged">
          <VCardItem>
            <VCardTitle>Replace everything on the site?</VCardTitle>
            <VCardSubtitle>{{ staged.name }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText>
            <p class="mb-3">
              Every collection is emptied and rebuilt from this file, and the page text it
              contains overwrites what is stored. <strong>{{ staged.summary.items }}
                {{ staged.summary.items === 1 ? 'item' : 'items' }}</strong> come in; anything on
              the site now that is not in this file is gone from the public website as soon as
              this finishes.
            </p>

            <p
              v-if="staged.summary.emptied.length"
              class="mb-3 text-high-emphasis font-weight-medium"
            >
              {{ staged.summary.emptied.map(c => c.label).join(', ') }}
              {{ staged.summary.emptied.length === 1 ? 'is' : 'are' }} not in this file, so
              {{ staged.summary.emptied.length === 1 ? 'it' : 'they' }} will be left empty.
            </p>

            <p class="text-body-2 mb-4">
              The server saves the site's current content to a file in its own private storage
              first, and returns the path. That path appears on this screen when the restore
              finishes — but the file is not on the website and nothing here can open it, so
              undoing this needs whoever administers the server to fetch it for you.
            </p>

            <VAlert
              v-if="restoreError"
              type="error"
              variant="tonal"
              class="mb-4"
            >
              <p class="mb-1">
                The server refused this backup and nothing was changed:
              </p>
              <p class="mb-0">
                <strong>{{ restoreError }}</strong>
              </p>
            </VAlert>

            <VTextField
              v-model="typed"
              :label="`Type ${CONFIRM_PHRASE} to confirm`"
              :disabled="restoring"
              autocomplete="off"
              spellcheck="false"
              hint="Typed out on purpose. Nothing in this panel can undo a restore — putting the old content back means asking whoever administers the server for the snapshot file."
              persistent-hint
            />
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              :disabled="restoring"
              @click="confirmOpen = false"
            >
              Leave the site as it is
            </VBtn>
            <VBtn
              color="error"
              :disabled="!confirmed"
              :loading="restoring"
              @click="restore"
            >
              Replace all content
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>
