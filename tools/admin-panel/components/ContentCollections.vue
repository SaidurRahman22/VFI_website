<script setup>
/*
  One group of website-content collections: the tabs, the list and the editor.

  This is the screen that lets admin.html go. That page had a tab per collection
  but was READ-ONLY - its "New event" buttons deep-linked into the Filament
  panel - so this is the first place in the project where a person can actually
  write website content without leaving the console.

  It takes a `group` and shows only that group's collections, because the
  sidebar - not a tab strip - is where the two groups are chosen. Ten tabs in
  one strip overflowed a 1500px screen and hid six of them behind a scroll
  arrow. The two routes that use this are pages/content/public.vue and
  pages/content/partner.vue; everything else about the screen is identical, so
  it lives here once.

  Deliberate choices:

  1. ONE generic form, driven by the schema the API sends with every list. The
     old panel hard-coded its field table in js/admin.js, which is a second copy
     of the database shape sitting in a browser, and the photos form proved the
     cost: it offered a `title` field that had no column, so every caption typed
     into it was silently discarded on save. Here the server is the only place
     the fields are declared.

  2. A VDialog, never a VNavigationDrawer. The drawer on the applications screen
     measured x:1500 in a 1500px viewport and never opened - it positions itself
     through Vuetify's layout system and this template's shell is a custom
     VerticalNavLayout. Same mistake is not repeated.

  3. Delete asks first and says what it does. The API soft-deletes, so this is
     recoverable, and the confirmation says so rather than implying it is final.

  4. The collection is in the URL (?tab=blogs) so a particular one is linkable
     and a refresh does not dump you back on the first tab.

  5. Removing says it can be undone, so undoing it lives on this screen. The
     confirmation has always promised the item is kept and restorable, and for a
     while the only thing that could act on that promise was a psql prompt - the
     panel that held the trashed filter and the restore action is gone.
     "Recently removed" reads the collection's removed rows and puts one back.
     Every action that changes the list is followed by a sentence saying what
     happened, because the list shows only the result and not which of the two
     things produced it.

  6. The filter is client-side, and it changes what reordering can honestly
     offer. index() sends the whole collection unpaginated, so the rows are
     already here and a request per keystroke would buy nothing. One step up or
     down is withdrawn while a filter is on: the row above the one you can see
     is not necessarily the row it would swap with, so the click would either
     move something out of sight or appear to do nothing at all. To-the-top and
     to-the-bottom stay, because where they land does not depend on what is
     filtered out - and they are why reaching the front of a thirty-item gallery
     is no longer twenty-nine clicks and twenty-nine requests.

  7. Several at once, with an honest count afterwards. Clearing eleven photos
     out of a gallery was eleven confirmations and eleven round trips. The bulk
     endpoint answers per id rather than yes-or-no, and that per-id answer is
     the whole point: three of five going through is not a success and is never
     reported as one. The sentence names how many went, how many stayed, and
     what the server said about each that stayed.

  8. Sorting belongs to the SERVER, and reordering goes away while it is on. The
     column allow-list lives in the API, so the header sends `sort` and
     `direction` and nothing here re-sorts the rows; two sort implementations,
     one per side, disagree the first time one of them meets a null. And
     `position` is the order the public site renders: while the list is in title
     order, "move up" would swap a row with one nowhere near it on the website,
     so every reorder control is withdrawn and the screen says why. That is the
     same rule the filter already follows, for the same reason.

  9. Erasing for good is offered only where it can work and only to someone who
     can use it. The endpoint refuses a row that is still live, so the button
     exists in "Recently removed" and nowhere else; it is superadmin-only, so a
     content editor never sees it instead of learning about the limit from a
     403. Its dialog cannot be dismissed by a stray click and makes you
     acknowledge the sentence, because unlike everything else destructive here
     there is nothing underneath it: no trash, no restore, nothing to put back.

 10. Drag REPLACES nothing. The four move buttons stay exactly where they were.
     If dragging were the only way to reorder, the feature would simply be gone
     for anyone working from a keyboard, and HTML5 drag-and-drop does not fire
     for touch either. A drop is spent as one `move` call per place travelled,
     because that endpoint swaps with the immediate neighbour and so leaves
     every other row's number alone - a renumber-the-whole-list save would
     overwrite whoever else is reordering the far end of the same gallery.
*/
const props = defineProps({
  /* The API's group slug: 'public-website' or 'partner-console'. */
  group: { type: String, required: true },

  /* What to call it on screen. Comes from the route, not from the API, because
     the heading has to render before the first request answers. */
  heading: { type: String, required: true },

  /* One line saying where this group's content comes out. */
  blurb: { type: String, default: '' },
})

const api = useVfiApi()
const route = useRoute()
const router = useRouter()
const { can, isSuperAdmin, load: loadUser } = useVfiUser()

const allowed = ref(false)
const booting = ref(true)
const bootError = ref(null)

const tabs = ref([])
const tab = ref(null)

const loading = ref(false)
const listError = ref(null)
const list = ref({ fields: [], data: [], title_key: 'title', meta_keys: [], singular: 'Item' })

/* ---- the editor ---- */
const open = ref(false)
const editing = ref(null) // null = creating
const form = ref({})
const saving = ref(false)
const saveError = ref(null)
const fieldErrors = ref({})
const uploading = ref(null) // the key of the field currently uploading

/* ---- delete confirmation ---- */
const confirming = ref(null)
const deleting = ref(false)

/* ---- choosing several rows at once ---- */
const selected = ref([])
const bulkConfirm = ref(false)
const bulkBusy = ref(false)

/* ---- the order the server is asked for. null = the collection's own. ---- */
const sort = ref(null)
const direction = ref('asc')

/* ---- what has been removed, and putting one back ---- */
const trashOpen = ref(false)
const trashLoading = ref(false)
const trashError = ref(null)
const trashNotice = ref(null)
const trash = ref([])
const restoring = ref(null)

/* ---- erasing one for good, which nothing can undo ---- */
const forcing = ref(null)
const forceUnderstood = ref(false)
const forceBusy = ref(false)

/* The sentence after an action. The list only shows the result, so on its own
   it cannot distinguish "removed" from "put back" from "never saved". */
const notice = ref(null)

/* The client-side filter over the rows already in the browser. `clearable`
   writes null into this, so nothing may assume it is a string. */
const filter = ref('')

const rowBusy = ref(null)

/* ---- a drag in progress ---- */
const dragId = ref(null)
const dragOverId = ref(null)

function labelOf(slug) {
  return tabs.value.find(t => t.slug === slug)?.label || 'Content'
}

/* "3 photos", "1 photo". None of the ten singulars the server declares is
   irregular in the plural, which is the only reason an added 's' is honest
   here; a collection called "Bursary" would need the server to send both. */
function things(n) {
  const one = (list.value.singular || 'item').toLowerCase()

  return `${n} ${n === 1 ? one : `${one}s`}`
}

/*
  Only this group's collections. The endpoint returns all ten with their group,
  so the filter happens here rather than in a second endpoint per group - the
  counts on the other group's tabs are the same request either way.
*/
async function loadTabs() {
  const res = await api.get('/api/admin/content/collections')

  tabs.value = res.data.filter(t => t.group === props.group)
}

async function loadList(slug) {
  loading.value = true
  listError.value = null

  const query = sort.value
    ? `?sort=${encodeURIComponent(sort.value)}&direction=${direction.value === 'desc' ? 'desc' : 'asc'}`
    : ''

  try {
    list.value = await api.get(`/api/admin/content/${slug}${query}`)

    /* Anything that is no longer in the list cannot be acted on, and a bulk
       request carrying an id somebody else removed a minute ago would come
       back as a failure the person could not explain. */
    selected.value = selected.value.filter(id => list.value.data.some(r => r.id === id))
  }
  catch (e) {
    /*
      A column the server does not allow-list comes back 422. Dropping the sort
      and re-reading is the only way the header and the rows can agree: leaving
      the header lit over a list that failed to load would claim an order that
      nothing on screen is in.
    */
    if (sort.value && (e.status === 422 || e.status === 400)) {
      sort.value = null
      loading.value = false
      await loadList(slug)
      listError.value = `${e.message} Showing the collection’s own order instead.`

      return
    }

    listError.value = e.message || 'Could not load this collection.'
  }
  finally {
    loading.value = false
  }
}

/*
  Re-read the counts after anything that changes one. Asked for again rather
  than adjusted locally: a guessed count that drifts from the list underneath it
  is worse than one extra request.
*/
async function refreshCounts() {
  try {
    await loadTabs()
  }
  catch {
    // The list itself already reloaded; a stale number in the tab is not worth
    // an error banner over working content.
  }
}

watch(tab, async slug => {
  if (!slug)
    return

  /* All of these belong to the collection being left, not to the one arriving:
     a filter typed for blog titles hides most of the photos, a sentence about a
     restored event means nothing over a list of documents, and a sort by a
     column this collection may not even have would be refused on arrival. */
  filter.value = ''
  notice.value = null
  trashOpen.value = false
  selected.value = []
  sort.value = null
  direction.value = 'asc'

  router.replace({ query: { ...route.query, tab: slug } })
  await loadList(slug)
})

/* ---------------------------------------------------------------- the form */

function blankForm() {
  const out = {}

  for (const f of list.value.fields)
    out[f.key] = f.type === 'select' ? (f.options?.[0]?.value ?? null) : ''

  return out
}

function startCreate() {
  editing.value = null
  form.value = blankForm()
  saveError.value = null
  fieldErrors.value = {}
  open.value = true
}

function startEdit(row) {
  editing.value = row
  // Copy only the schema's keys, and turn nulls into '' so the inputs are
  // controlled - a null v-model on a VTextField renders as the string "null".
  const out = {}

  for (const f of list.value.fields)
    out[f.key] = row[f.key] ?? (f.type === 'select' ? null : '')

  form.value = out
  saveError.value = null
  fieldErrors.value = {}
  open.value = true
}

async function save() {
  saving.value = true
  saveError.value = null
  fieldErrors.value = {}

  // Empty string means "no value", which is NULL in the database, not "".
  const payload = {}

  for (const [k, v] of Object.entries(form.value))
    payload[k] = typeof v === 'string' && v.trim() === '' ? null : v

  try {
    if (editing.value)
      await api.put(`/api/admin/content/${tab.value}/${editing.value.id}`, payload)
    else
      await api.post(`/api/admin/content/${tab.value}`, payload)

    open.value = false
    await loadList(tab.value)
    if (!editing.value)
      await refreshCounts()
  }
  catch (e) {
    // Per-field where Laravel gave us per-field, so the person can see WHICH
    // box is wrong instead of one banner over a form of twelve inputs.
    saveError.value = e.message
    fieldErrors.value = e.errors || {}
  }
  finally {
    saving.value = false
  }
}

async function confirmDelete() {
  const row = confirming.value
  const title = titleOf(row)

  deleting.value = true
  try {
    await api.del(`/api/admin/content/${tab.value}/${row.id}`)
    confirming.value = null
    await loadList(tab.value)
    await refreshCounts()

    /* Named, and pointing at the undo. The dialog said it could be put back;
       this is where the person is told where that is. */
    notice.value = `“${title}” is off the website. Recently removed will put it back.`
  }
  catch (e) {
    listError.value = e.message
    confirming.value = null
  }
  finally {
    deleting.value = false
  }
}

/* ------------------------------------------------- choosing several at once */

function isSelected(row) {
  return selected.value.includes(row.id)
}

function toggleRow(row, on) {
  selected.value = on
    ? [...selected.value, row.id]
    : selected.value.filter(id => id !== row.id)
}

/* Select-all means "all of the ones you can see". Extending it to rows the
   filter is hiding would be a tick box that quietly arms a delete against
   content nobody on this screen has looked at. */
const visibleIds = computed(() => filtered.value.map(r => r.id))

const allVisibleSelected = computed(() =>
  visibleIds.value.length > 0 && visibleIds.value.every(id => selected.value.includes(id)))

const someVisibleSelected = computed(() =>
  !allVisibleSelected.value && visibleIds.value.some(id => selected.value.includes(id)))

function toggleAllVisible(on) {
  const ids = visibleIds.value

  selected.value = on
    ? [...new Set([...selected.value, ...ids])]
    : selected.value.filter(id => !ids.includes(id))
}

/* Taken from the whole collection, not from the filtered view: a row can be
   selected and then filtered out, and it is still selected. */
const selectedRows = computed(() => list.value.data.filter(r => selected.value.includes(r.id)))

const selectedHiddenCount = computed(() =>
  selectedRows.value.filter(r => !visibleIds.value.includes(r.id)).length)

/* The words a per-id answer may use to mean it worked. */
const BULK_OK_WORDS = ['ok', 'done', 'deleted', 'removed', 'restored', 'success']

/*
  What the bulk endpoint said about each id.

  It answers per id so that a partial result can be reported as one, and this
  reads that answer defensively: success is only ever something the row states
  positively, never the absence of a failure flag. A row this cannot understand
  is counted as "the server did not say", which is the truth, rather than as a
  success - reporting a deletion that did not happen is the one outcome worth
  going out of the way to make impossible.
*/
function readBulk(res, targets) {
  const answers = Array.isArray(res?.results)
    ? res.results
    : Array.isArray(res?.data)
      ? res.data
      : res?.results && typeof res.results === 'object'
        // An object keyed by id, which is the other shape the contract allows.
        ? Object.entries(res.results).map(([id, v]) => ({
          id: Number(id),
          ...(v && typeof v === 'object' ? v : { ok: v === true }),
        }))
        : null

  if (!answers)
    return { readable: false, done: [], failed: [] }

  const done = []
  const failed = []

  for (const t of targets) {
    const r = answers.find(x => Number(x?.id) === t.id)
    const ok = !!r && (r.ok === true
      || r.success === true
      || BULK_OK_WORDS.includes(String(r.status ?? '').toLowerCase()))

    if (ok)
      done.push(t)
    else
      failed.push({ row: t, why: r?.message || r?.error || (r ? 'It was refused.' : 'The server did not say what happened to it.') })
  }

  return { readable: true, done, failed }
}

/* The sentence afterwards. A partial result is a warning, never the green
   banner: "removed" over a list that still holds two of them is the screen
   telling the person something they can see is untrue. */
function reportBulk(outcome, asked) {
  if (!outcome.readable) {
    listError.value = `The server answered in a shape this screen could not read, so it cannot say which of the ${asked} went. The list below is what is actually on the website now.`

    return
  }

  if (!outcome.failed.length) {
    const n = outcome.done.length

    notice.value = `${things(n)} ${n === 1 ? 'is' : 'are'} off the website. Recently removed will put ${n === 1 ? 'it' : 'them'} back.`

    return
  }

  const named = outcome.failed.slice(0, 3).map(f => `“${titleOf(f.row)}” — ${f.why}`).join(' ')
  const rest = outcome.failed.length > 3 ? ` And ${outcome.failed.length - 3} more.` : ''

  listError.value = outcome.done.length
    // Neutral about where the failures ended up, because the screen cannot
    // know. A bulk delete fails per id when the row no longer exists OR when it
    // had already been removed - and in that second case saying "still on the
    // website" is simply false. The per-id reason below says which it was.
    ? `Removed ${outcome.done.length} of ${asked}. ${outcome.failed.length} were not: ${named}${rest}`
    : `None of the ${asked} were removed: ${named}${rest}`
}

async function bulkRemove() {
  const targets = selectedRows.value

  if (!targets.length)
    return

  bulkBusy.value = true
  listError.value = null
  notice.value = null

  try {
    const res = await api.post(`/api/admin/content/${tab.value}/bulk`, {
      action: 'delete',
      ids: targets.map(r => r.id),
    })

    const outcome = readBulk(res, targets)

    bulkConfirm.value = false
    selected.value = []
    await loadList(tab.value)
    await refreshCounts()
    reportBulk(outcome, targets.length)
  }
  catch (e) {
    bulkConfirm.value = false

    /* The whole request was refused, so nothing is assumed about what it did
       or did not do before refusing - the list is re-read and that is what the
       person is pointed at. */
    const refused = e.status === 404 || e.status === 405
      ? 'This server does not have the bulk action yet, so nothing was removed. Remove them one at a time until the API is updated.'
      : e.message || 'The removal was refused.'

    await loadList(tab.value)
    await refreshCounts()
    listError.value = refused
  }
  finally {
    bulkBusy.value = false
  }
}

/* ------------------------------------------------------------------ sorting */

/*
  Which orders the header offers.

  Two columns, because a sort header has to name a column the row actually
  shows: the meta line is several fields joined with "·", so "sort by that"
  would name something that is not on screen and cannot be checked by eye. If
  the API starts sending its allow-list as `sortable`, it wins - the server is
  the one that knows which columns it will accept.
*/
const sortColumns = computed(() => {
  const allowed = Array.isArray(list.value.sortable)
    ? list.value.sortable.map(c => (typeof c === 'string' ? c : c?.key))
    : null

  return [
    { key: list.value.title_key, label: 'Title', asc: 'A to Z', desc: 'Z to A', first: 'asc' },
    { key: 'updated_at', label: 'Last updated', asc: 'oldest first', desc: 'newest first', first: 'desc' },
  ].filter(c => c.key && (!allowed || allowed.includes(c.key)))
})

/* The rows carry `updated_at` only once the sorting API is deployed, so its
   presence is what says the header can be trusted. Drawing sort controls
   against a build that ignores the parameters would light a column heading over
   a list in a completely different order. */
const showUpdated = computed(() => list.value.data.some(r => r.updated_at))
const sortSupported = computed(() => showUpdated.value || Array.isArray(list.value.sortable))

/* Resolved once each rather than looked up per binding, so the heading, its
   label and its click cannot end up describing different columns. */
const titleSort = computed(() => sortColumns.value.find(c => c.key === list.value.title_key) || null)
const updatedSort = computed(() => sortColumns.value.find(c => c.key === 'updated_at') || null)

const naturalOrder = computed(() => sort.value === null)

const sortedColumnLabel = computed(() =>
  sortColumns.value.find(c => c.key === sort.value)?.label || null)

async function sortBy(col) {
  if (sort.value === col.key) {
    direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  }
  else {
    sort.value = col.key
    direction.value = col.first || 'asc'
  }

  await loadList(tab.value)
}

async function clearSort() {
  sort.value = null
  direction.value = 'asc'
  await loadList(tab.value)
}

function sortAria(col) {
  if (sort.value !== col.key)
    return `Sort by ${col.label.toLowerCase()}, ${col[col.first || 'asc']}`

  const now = direction.value === 'asc' ? col.asc : col.desc
  const next = direction.value === 'asc' ? col.desc : col.asc

  return `Sorted by ${col.label.toLowerCase()}, ${now}. Sort ${next} instead.`
}

/* ------------------------------------------------- removed, and put back */

async function loadTrash() {
  trashLoading.value = true
  trashError.value = null
  try {
    trash.value = (await api.get(`/api/admin/content/${tab.value}/trashed`)).data
  }
  catch (e) {
    trashError.value = e.message || 'Could not read what has been removed.'
  }
  finally {
    trashLoading.value = false
  }
}

async function openTrash() {
  /* Asked for on open, not held from earlier: someone else may have removed or
     restored something since, and a stale list here offers a button that would
     then be refused. */
  trash.value = []
  trashNotice.value = null
  trashOpen.value = true
  await loadTrash()
}

async function restoreItem(row) {
  restoring.value = row.id
  trashError.value = null
  trashNotice.value = null
  try {
    await api.post(`/api/admin/content/${tab.value}/${row.id}/restore`)

    /* Closed, because what was recovered is in the list behind this dialog and
       that is where it should be looked at. */
    trashOpen.value = false
    await loadList(tab.value)
    await refreshCounts()
    notice.value = `“${titleOf(row)}” is back in ${labelOf(tab.value).toLowerCase()}, in the place it held.`
  }
  catch (e) {
    /* "it is still on the website" arrives as a 422 - someone else put it back
       first. That is information, so it stays here beside the row it is about,
       and the list is re-read so the row it refers to goes away. */
    const refused = e.message

    await loadTrash()
    await loadList(tab.value)
    await refreshCounts()
    trashError.value = refused
  }
  finally {
    restoring.value = null
  }
}

/*
  Erase one for good.

  Superadmin-only on the server, and hidden from everyone else here rather than
  shown and refused: a button whose only answer is 403 teaches nothing except
  that the screen does not know who is using it. The dialog behind it is the
  only `persistent` one in this component, because every other destructive
  thing on this screen leaves something behind and this leaves nothing.
*/
function startForceDelete(row) {
  forcing.value = row
  forceUnderstood.value = false
}

function cancelForceDelete() {
  forcing.value = null
  forceUnderstood.value = false
}

async function forceDelete() {
  const row = forcing.value

  forceBusy.value = true
  trashError.value = null
  trashNotice.value = null
  try {
    await api.del(`/api/admin/content/${tab.value}/${row.id}/force`)
    cancelForceDelete()

    /* The tab counts live rows, and an erased row was already not among them,
       so there is nothing to re-count - only this dialog's own list changes. */
    await loadTrash()
    trashNotice.value = `“${titleOf(row)}” is gone for good. Nothing here can bring it back.`
  }
  catch (e) {
    /* 422 is "that one is not in the trash", which means somebody put it back
       while this dialog sat open, and 403 is a role that may not erase. Both
       are facts about this row, so they are shown beside it and the list is
       re-read so it stops offering an action that has just become impossible. */
    const refused = e.message

    cancelForceDelete()
    await loadTrash()
    trashError.value = refused
  }
  finally {
    forceBusy.value = false
  }
}

/* ---------------------------------------------------------------- reordering */

async function move(row, dir) {
  rowBusy.value = row.id
  listError.value = null
  try {
    await api.put(`/api/admin/content/${tab.value}/${row.id}/move`, { direction: dir })
    await loadList(tab.value)
  }
  catch (e) {
    // "already first in the list" arrives here as a 422. It is information,
    // not a failure, so it reads as a plain sentence.
    listError.value = e.message
  }
  finally {
    rowBusy.value = null
  }
}

/* Reordering only means anything against the order the website renders, and
   only when every row that could be swapped with is on screen. */
const canReorder = computed(() => naturalOrder.value && !filtering.value)

/*
  Land a row on another row's place.

  The endpoint moves one place at a time by design - it swaps with the
  immediate neighbour, so two people reordering opposite ends of the same
  gallery do not overwrite each other - which makes a drag of four places four
  requests. The two ends are the exception: `top` and `bottom` reach them in
  one. Stopping at the first refusal and re-reading leaves the row somewhere
  real, and the sentence says how far it actually got rather than claiming the
  drop landed where it was aimed.
*/
async function reorderTo(row, toIndex) {
  const rows = list.value.data
  const from = rows.findIndex(r => r.id === row.id)

  if (from < 0 || toIndex < 0 || toIndex === from)
    return

  rowBusy.value = row.id
  listError.value = null

  let travelled = 0

  try {
    if (toIndex === 0) {
      await api.put(`/api/admin/content/${tab.value}/${row.id}/move`, { direction: 'top' })
    }
    else if (toIndex === rows.length - 1) {
      await api.put(`/api/admin/content/${tab.value}/${row.id}/move`, { direction: 'bottom' })
    }
    else {
      const dir = toIndex > from ? 'down' : 'up'

      for (let n = Math.abs(toIndex - from); n > 0; n--) {
        await api.put(`/api/admin/content/${tab.value}/${row.id}/move`, { direction: dir })
        travelled++
      }
    }

    await loadList(tab.value)
  }
  catch (e) {
    await loadList(tab.value)
    listError.value = travelled
      ? `“${titleOf(row)}” moved ${travelled} ${travelled === 1 ? 'place' : 'places'} and then stopped: ${e.message} The list shows where it is now.`
      : e.message
  }
  finally {
    rowBusy.value = null
  }
}

const dragIndex = computed(() =>
  (dragId.value === null ? -1 : list.value.data.findIndex(r => r.id === dragId.value)))

function onDragStart(row, e) {
  /* rowBusy means a reorder is still in flight, and a second drag started on
     top of it would compute its distance from a list that is about to be
     replaced by the answer to the first. */
  if (!canReorder.value || rowBusy.value !== null) {
    e.preventDefault()

    return
  }

  /* A drag that starts on the tick box or a button is a mis-aimed click on
     that control, not a reorder, and letting it through would move the row
     somebody was trying to select. */
  if (e.target?.closest?.('button, input, a, .v-selection-control')) {
    e.preventDefault()

    return
  }

  dragId.value = row.id
  if (e.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move'
    // Firefox starts no drag at all unless the event carries some data.
    e.dataTransfer.setData('text/plain', String(row.id))
  }
}

function onDragOver(row, e) {
  if (dragId.value === null || dragId.value === row.id)
    return

  // preventDefault is what makes a drop legal; the default is to refuse it.
  e.preventDefault()
  if (e.dataTransfer)
    e.dataTransfer.dropEffect = 'move'

  dragOverId.value = row.id
}

function onDragLeave(row) {
  if (dragOverId.value === row.id)
    dragOverId.value = null
}

function onDrop(row, e) {
  e.preventDefault()

  const moving = list.value.data.find(r => r.id === dragId.value)
  const to = list.value.data.findIndex(r => r.id === row.id)

  dragId.value = null
  dragOverId.value = null

  if (moving && to >= 0)
    reorderTo(moving, to)
}

function onDragEnd() {
  dragId.value = null
  dragOverId.value = null
}

/* Which edge of the hovered row the line is drawn on. A row coming from above
   lands below the one it is dropped on; a highlighted row instead of an edge
   would only say which row the pointer is over, which is a different
   question from where the thing will end up. */
function dropEdge(row) {
  if (dragOverId.value !== row.id || dragIndex.value < 0)
    return null

  const over = list.value.data.findIndex(r => r.id === row.id)

  return dragIndex.value < over ? 'after' : 'before'
}

/* ---------------------------------------------------------------- images */

/*
  img_id is a path-style id: '/storage/media/<hash>.jpg' for a managed upload,
  or a bundled 'assets/img/x.jpg'. Both are served from the site root, so the
  only work is making the relative one absolute.
*/
function imageSrc(id) {
  if (!id)
    return null

  return id.startsWith('/') || id.startsWith('http') ? id : `/${id}`
}

async function uploadImage(fieldKey, files) {
  const file = files?.[0]

  if (!file)
    return

  uploading.value = fieldKey
  saveError.value = null
  try {
    const body = new FormData()

    body.append('file', file)

    const res = await api.post('/api/admin/media', body)

    // The server re-encodes, downscales and content-hashes it, then hands back
    // the id. The browser never decides the filename.
    form.value[fieldKey] = res.imgId
  }
  catch (e) {
    saveError.value = e.message || 'That image could not be uploaded.'
  }
  finally {
    uploading.value = null
  }
}

/* ---------------------------------------------------------------- the rows */

function titleOf(row) {
  return row[list.value.title_key] || `(untitled — ${list.value.singular.toLowerCase()})`
}

function metaOf(row) {
  return (list.value.meta_keys || [])
    .map(k => row[k])
    .filter(v => v !== null && v !== '' && v !== undefined)
    .join(' · ')
}

const imageKey = computed(() => list.value.fields.find(f => f.type === 'image')?.key || null)

/* What a removed row says under its title. The server sends ISO-8601 and the
   browser is the only thing that knows the reader's timezone and locale, so it
   does the formatting - and says just "Removed" rather than inventing a date if
   there is none to print. */
function removedLine(row) {
  const when = row.removed_at ? new Date(row.removed_at) : null
  const stamp = when && !Number.isNaN(when.getTime()) ? `Removed ${when.toLocaleString()}` : 'Removed'

  return [stamp, metaOf(row)].filter(Boolean).join(' · ')
}

/* The column is narrow, so it holds a date and the full stamp goes in the
   title attribute - "2 Mar 2026" answers "is this stale?" and the hover
   answers "was that before or after the other edit?". */
function updatedShort(row) {
  const when = row.updated_at ? new Date(row.updated_at) : null

  if (!when || Number.isNaN(when.getTime()))
    return '—'

  return when.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

function updatedFull(row) {
  const when = row.updated_at ? new Date(row.updated_at) : null

  return when && !Number.isNaN(when.getTime()) ? `Last saved ${when.toLocaleString()}` : ''
}

/* What was typed, which is also what gets quoted back if nothing matches -
   echoing a lower-cased copy of someone's own words reads as a typo. */
const filterText = computed(() => (filter.value || '').trim())
const filtering = computed(() => filterText.value !== '')

/* Matched against what the row actually shows, plus the public id: a row that
   turned up because of text nobody can see reads as a bug in the filter. */
const filtered = computed(() => {
  if (!filtering.value)
    return list.value.data

  const q = filterText.value.toLowerCase()

  return list.value.data.filter(row =>
    [titleOf(row), metaOf(row), row.legacy_id].join(' ').toLowerCase().includes(q))
})

/* Against the whole collection, never the filtered view. An end derived from a
   filtered index would grey out "to the top" on a row with twenty rows above
   it, which is the disabled button telling a lie instead of the enabled one. */
function isFirst(row) {
  return list.value.data[0]?.id === row.id
}

function isLast(row) {
  return list.value.data[list.value.data.length - 1]?.id === row.id
}

/*
  The column widths, shared by the header and every row.

  Both are their own grid, so they only line up if every track but one is a
  fixed size - an `auto` track for the buttons would settle at a different
  width in a header that has none, and the "Last updated" heading would sit
  over the wrong column.
*/
const gridTemplate = computed(() => [
  '4.5rem', // the drag handle and the tick box
  imageKey.value ? '3.5rem' : null,
  'minmax(0, 1fr)', // the title and the line under it
  showUpdated.value ? '8.5rem' : null,
  '17.5rem', // the row's buttons, right-aligned inside
].filter(Boolean).join(' '))

/* Narrow screens drop the handle - HTML5 drag-and-drop does not fire for touch
   at all - and let the stamp and the buttons wrap onto their own lines. */
const gridTemplateNarrow = computed(() => [
  '2.75rem',
  imageKey.value ? '3rem' : null,
  'minmax(0, 1fr)',
].filter(Boolean).join(' '))

onMounted(async () => {
  /*
    AWAIT the user before deciding what to draw. Calling can() before
    /api/admin/me has answered is what made the applications screen say "no
    access" while the API was returning every row.
  */
  await loadUser()
  allowed.value = can('content.manage')

  if (!allowed.value) {
    booting.value = false

    return
  }

  try {
    await loadTabs()
    tab.value = tabs.value.some(t => t.slug === route.query.tab)
      ? String(route.query.tab)
      : tabs.value[0]?.slug
  }
  catch (e) {
    bootError.value = e.message || 'Could not load the content collections.'
  }
  finally {
    booting.value = false
  }
})
</script>

<template>
  <div>
    <VAlert
      v-if="!booting && !allowed"
      type="info"
      variant="tonal"
    >
      Your role does not include editing website content.
    </VAlert>

    <VAlert
      v-else-if="bootError"
      type="error"
      variant="tonal"
    >
      {{ bootError }}
    </VAlert>

    <template v-else>
      <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
        <div>
          <h4 class="text-h4 mb-1">
            {{ heading }}
          </h4>
          <p
            v-if="blurb"
            class="text-body-2 mb-0 text-medium-emphasis"
          >
            {{ blurb }}
          </p>
        </div>

        <div
          v-if="tab"
          class="d-flex flex-wrap align-center gap-2"
        >
          <VBtn
            variant="text"
            prepend-icon="ri-history-line"
            :disabled="loading"
            @click="openTrash"
          >
            Recently removed
          </VBtn>

          <VBtn
            prepend-icon="ri-add-line"
            :disabled="loading"
            @click="startCreate"
          >
            New {{ list.singular.toLowerCase() }}
          </VBtn>
        </div>
      </div>

      <VCard>
        <!--
          One strip, of at most six. The other group is a separate sidebar entry
          and a separate route, so nothing here scrolls out of reach.
        -->
        <VTabs
          v-model="tab"
          show-arrows
        >
          <VTab
            v-for="t in tabs"
            :key="t.slug"
            :value="t.slug"
          >
            {{ t.label }}
            <VChip
              size="x-small"
              class="ms-2"
            >
              {{ t.count }}
            </VChip>
          </VTab>
        </VTabs>

        <VDivider />

        <VProgressLinear
          v-if="loading"
          indeterminate
        />

          <!--
            The sentence goes in text-high-emphasis, not in the alert's own
            colour. Vuetify's tonal variant paints the type colour as TEXT over
            a 16% tint of itself, so a warning sentence measures 1.81:1 against
            its own background. The gold stays on the icon and the border.
          -->
        <VAlert
          v-if="listError"
          type="warning"
          variant="tonal"
          class="ma-4"
          closable
          @click:close="listError = null"
        >
          <span class="text-high-emphasis">{{ listError }}</span>
        </VAlert>

        <VAlert
          v-if="notice"
          type="success"
          variant="tonal"
          class="ma-4"
          closable
          @click:close="notice = null"
        >
          {{ notice }}
        </VAlert>

        <!--
          The filter, over rows that are already here. It stands in for the
          per-column search the generated panel had; its hint explains why two
          of the reorder buttons leave while it is on.
        -->
        <div
          v-if="!loading && list.data.length"
          class="d-flex flex-wrap align-center justify-space-between gap-3 px-4 pt-4"
        >
          <VTextField
            v-model="filter"
            label="Filter by title, the line under it, or the public id"
            prepend-inner-icon="ri-search-line"
            density="compact"
            clearable
            persistent-hint
            hide-details="auto"
            style="max-inline-size: 30rem;"
            :hint="filtering
              ? 'While filtered, a row can go to the top or the bottom. One step up or down is hidden, and so is dragging: they would swap this row with one you cannot see.'
              : ''"
          />

          <p
            v-if="filtering"
            class="text-body-2 text-medium-emphasis mb-0"
          >
            Showing {{ filtered.length }} of {{ list.data.length }}.
          </p>
        </div>

        <!--
          Sorted by something other than the collection's own order, which is
          the order the public site renders. Said out loud, because the reorder
          controls have just gone and their absence on its own looks like a bug.
        -->
        <VAlert
          v-if="!naturalOrder"
          type="info"
          variant="tonal"
          density="compact"
          class="mx-4 mt-4"
        >
          <span class="text-high-emphasis">
            Sorted by {{ sortedColumnLabel ? sortedColumnLabel.toLowerCase() : 'a column' }}, which is not the order
            the website shows these in — so reordering is off.
          </span>

          <template #append>
            <VBtn
              variant="tonal"
              size="small"
              :disabled="loading"
              @click="clearSort"
            >
              Website order
            </VBtn>
          </template>
        </VAlert>

        <VCardText v-if="!loading && !list.data.length">
          <p class="text-body-1 mb-0">
            There is nothing in {{ labelOf(tab).toLowerCase() }} yet. Use
            <strong>New {{ list.singular.toLowerCase() }}</strong> to add the first one.
          </p>
        </VCardText>

        <!-- Not the empty state above: these rows exist, the filter is hiding them. -->
        <VCardText v-else-if="!loading && !filtered.length">
          <p class="text-body-1 mb-0">
            Nothing in {{ labelOf(tab).toLowerCase() }} matches
            <strong>{{ filterText }}</strong>. Clear the filter to see all {{ list.data.length }}.
          </p>
        </VCardText>

        <!--
          Deliberately NOT hidden while `loading`. Every sort click reloads,
          and taking the rows away for the length of the round trip makes the
          card collapse and snap back on each one; the progress bar above
          already says a request is out.
        -->
        <div
          v-else
          class="cc mt-4"
          :style="{ '--cc-cols': gridTemplate, '--cc-cols-sm': gridTemplateNarrow }"
        >
          <!--
            What is selected and what can be done with it. Only here when
            something is: a permanently visible bar of disabled buttons is a
            row of controls that spends most of its life meaning nothing.
          -->
          <div
            v-if="selected.length"
            class="cc__bulk"
          >
            <span class="text-body-2 text-high-emphasis">
              {{ selected.length }} selected<template v-if="selectedHiddenCount">
                — {{ selectedHiddenCount }} of them {{ selectedHiddenCount === 1 ? 'is' : 'are' }} hidden by the filter
              </template>
            </span>
            <VSpacer />
            <VBtn
              variant="text"
              size="small"
              :disabled="bulkBusy"
              @click="selected = []"
            >
              Clear selection
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              size="small"
              prepend-icon="ri-delete-bin-line"
              :loading="bulkBusy"
              @click="bulkConfirm = true"
            >
              Remove {{ selected.length }} from the website
            </VBtn>
          </div>

          <VDivider />

          <!-- The column headings, which are also how the order is chosen. -->
          <div
            v-if="filtered.length"
            class="cc__head text-caption text-medium-emphasis"
          >
            <div class="cc__cell cc__cell--lead">
              <VCheckboxBtn
                :model-value="allVisibleSelected"
                :indeterminate="someVisibleSelected"
                density="compact"
                hide-details
                :aria-label="`Select the ${filtered.length} shown`"
                @update:model-value="toggleAllVisible"
              />
            </div>

            <div
              v-if="imageKey"
              class="cc__cell"
            />

            <div class="cc__cell">
              <button
                v-if="sortSupported && titleSort"
                type="button"
                class="cc__sort"
                :class="{ 'cc__sort--on': sort === titleSort.key }"
                :disabled="loading"
                :aria-label="sortAria(titleSort)"
                @click="sortBy(titleSort)"
              >
                Title
                <VIcon
                  v-if="sort === titleSort.key"
                  size="16"
                  :icon="direction === 'asc' ? 'ri-arrow-up-s-fill' : 'ri-arrow-down-s-fill'"
                />
              </button>
              <span v-else>Title</span>
            </div>

            <div
              v-if="showUpdated"
              class="cc__cell cc__cell--updated"
            >
              <button
                v-if="sortSupported && updatedSort"
                type="button"
                class="cc__sort"
                :class="{ 'cc__sort--on': sort === 'updated_at' }"
                :disabled="loading"
                :aria-label="sortAria(updatedSort)"
                @click="sortBy(updatedSort)"
              >
                Last updated
                <VIcon
                  v-if="sort === 'updated_at'"
                  size="16"
                  :icon="direction === 'asc' ? 'ri-arrow-up-s-fill' : 'ri-arrow-down-s-fill'"
                />
              </button>
              <span v-else>Last updated</span>
            </div>

            <div class="cc__cell cc__cell--actions" />
          </div>

          <VDivider v-if="filtered.length" />

          <template
            v-for="(row, i) in filtered"
            :key="row.id"
          >
            <div
              class="cc__row"
              :class="{
                'cc__row--dragging': dragId === row.id,
                'cc__row--before': dropEdge(row) === 'before',
                'cc__row--after': dropEdge(row) === 'after',
              }"
              :draggable="canReorder ? 'true' : 'false'"
              @dragstart="e => onDragStart(row, e)"
              @dragover="e => onDragOver(row, e)"
              @dragleave="onDragLeave(row)"
              @drop="e => onDrop(row, e)"
              @dragend="onDragEnd"
            >
              <div class="cc__cell cc__cell--lead">
                <!--
                  Decoration, deliberately: it says the row can be dragged, and
                  the arrow buttons at the other end are what actually carries
                  the feature for a keyboard or a phone. Giving this a second,
                  focusable copy of the same action would put two stops in the
                  tab order for one thing.
                -->
                <VIcon
                  v-if="canReorder"
                  icon="ri-draggable"
                  size="18"
                  class="cc__handle"
                  aria-hidden="true"
                  title="Drag to reorder"
                />
                <span
                  v-else
                  class="cc__handle-gap"
                />
                <VCheckboxBtn
                  :model-value="isSelected(row)"
                  density="compact"
                  hide-details
                  :aria-label="`Select ${titleOf(row)}`"
                  @update:model-value="on => toggleRow(row, on)"
                />
              </div>

              <div
                v-if="imageKey"
                class="cc__cell"
              >
                <VAvatar
                  size="40"
                  rounded
                  variant="tonal"
                >
                  <VImg
                    v-if="row[imageKey]"
                    :src="imageSrc(row[imageKey])"
                    cover
                  />
                  <VIcon
                    v-else
                    icon="ri-image-line"
                  />
                </VAvatar>
              </div>

              <div class="cc__cell">
                <div class="text-body-1 cc__clip">
                  {{ titleOf(row) }}
                </div>
                <div
                  v-if="metaOf(row)"
                  class="text-body-2 text-medium-emphasis cc__clip"
                >
                  {{ metaOf(row) }}
                </div>
              </div>

              <div
                v-if="showUpdated"
                class="cc__cell cc__cell--updated text-body-2 text-medium-emphasis"
                :title="updatedFull(row)"
              >
                {{ updatedShort(row) }}
              </div>

              <div class="cc__cell cc__cell--actions">
                <VBtn
                  v-if="naturalOrder"
                  icon="ri-skip-up-line"
                  variant="text"
                  size="small"
                  :disabled="isFirst(row) || rowBusy === row.id"
                  :aria-label="`Move ${titleOf(row)} to the top`"
                  @click="move(row, 'top')"
                />
                <VBtn
                  v-if="canReorder"
                  icon="ri-arrow-up-line"
                  variant="text"
                  size="small"
                  :disabled="isFirst(row) || rowBusy === row.id"
                  :aria-label="`Move ${titleOf(row)} up`"
                  @click="move(row, 'up')"
                />
                <VBtn
                  v-if="canReorder"
                  icon="ri-arrow-down-line"
                  variant="text"
                  size="small"
                  :disabled="isLast(row) || rowBusy === row.id"
                  :aria-label="`Move ${titleOf(row)} down`"
                  @click="move(row, 'down')"
                />
                <VBtn
                  v-if="naturalOrder"
                  icon="ri-skip-down-line"
                  variant="text"
                  size="small"
                  :disabled="isLast(row) || rowBusy === row.id"
                  :aria-label="`Move ${titleOf(row)} to the bottom`"
                  @click="move(row, 'bottom')"
                />
                <VBtn
                  variant="tonal"
                  size="small"
                  @click="startEdit(row)"
                >
                  Edit
                </VBtn>
                <VBtn
                  icon="ri-delete-bin-line"
                  variant="text"
                  size="small"
                  color="error"
                  :aria-label="`Delete ${titleOf(row)}`"
                  @click="confirming = row"
                />
              </div>
            </div>
            <VDivider v-if="i < filtered.length - 1" />
          </template>
        </div>
      </VCard>
    </template>

    <!-- 👉 the editor. A dialog, for the reason in the header comment. -->
    <VDialog
      v-model="open"
      max-width="760"
      scrollable
    >
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editing ? `Edit ${list.singular.toLowerCase()}` : `New ${list.singular.toLowerCase()}` }}
          </VCardTitle>
          <!-- text-wrap, or Vuetify clips a subtitle to one ellipsised line. -->
          <VCardSubtitle
            v-if="editing"
            class="text-wrap"
          >
            Saved as <code>{{ editing.legacy_id }}</code> — the id the public site uses. It never changes.
          </VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VAlert
            v-if="saveError"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ saveError }}
          </VAlert>

          <VRow>
            <VCol
              v-for="f in list.fields"
              :key="f.key"
              cols="12"
              :md="f.half ? 6 : 12"
            >
              <!-- image: upload, preview, and the stored id in plain sight -->
              <template v-if="f.type === 'image'">
                <label class="text-body-2 font-weight-medium d-block mb-2">{{ f.label }}</label>
                <div class="d-flex align-center gap-4 mb-2">
                  <VAvatar
                    size="72"
                    rounded
                    variant="tonal"
                                      >
                    <VImg
                      v-if="form[f.key]"
                      :src="imageSrc(form[f.key])"
                      cover
                    />
                    <VIcon
                      v-else
                      icon="ri-image-line"
                    />
                  </VAvatar>
                  <div class="flex-grow-1">
                    <VFileInput
                      :label="form[f.key] ? 'Replace image' : 'Choose an image'"
                      accept="image/jpeg,image/png,image/webp,image/gif"
                      density="compact"
                      prepend-icon=""
                      prepend-inner-icon="ri-upload-2-line"
                      :loading="uploading === f.key"
                      :hint="f.hint"
                      persistent-hint
                      hide-details="auto"
                      @update:model-value="files => uploadImage(f.key, Array.isArray(files) ? files : [files])"
                    />
                  </div>
                  <VBtn
                    v-if="form[f.key]"
                    variant="text"
                    size="small"
                    color="error"
                    @click="form[f.key] = ''"
                  >
                    Remove
                  </VBtn>
                </div>
                <!--
                  The stored id, shown rather than hidden: it is what the public
                  page loads, so when a picture looks wrong this is the line
                  that says why.
                -->
                <VTextField
                  v-model="form[f.key]"
                  density="compact"
                  label="Stored image id"
                  :error-messages="fieldErrors[f.key]"
                  hide-details="auto"
                />
              </template>

              <VSelect
                v-else-if="f.type === 'select'"
                v-model="form[f.key]"
                :label="f.label"
                :items="f.options"
                item-title="label"
                item-value="value"
                :hint="f.hint"
                persistent-hint
                :error-messages="fieldErrors[f.key]"
              />

              <VTextarea
                v-else-if="f.type === 'textarea'"
                v-model="form[f.key]"
                :label="f.label"
                :rows="f.rows || 3"
                auto-grow
                :placeholder="f.placeholder"
                :hint="f.hint"
                persistent-hint
                :error-messages="fieldErrors[f.key]"
              />

              <VTextField
                v-else
                v-model="form[f.key]"
                :label="f.label + (f.required ? ' *' : '')"
                :type="f.type === 'date' ? 'date' : f.type === 'email' ? 'email' : 'text'"
                :placeholder="f.placeholder"
                :hint="f.hint"
                persistent-hint
                :error-messages="fieldErrors[f.key]"
              />
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="saving"
            @click="open = false"
          >
            Cancel
          </VBtn>
          <VBtn
            :loading="saving"
            :disabled="!!uploading"
            @click="save"
          >
            {{ editing ? 'Save changes' : `Add ${list.singular.toLowerCase()}` }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- 👉 delete confirmation -->
    <VDialog
      :model-value="!!confirming"
      max-width="480"
      @update:model-value="v => { if (!v) confirming = null }"
    >
      <VCard v-if="confirming">
        <VCardItem>
          <VCardTitle>Remove this from the website?</VCardTitle>
        </VCardItem>
        <VCardText>
          <p class="mb-2">
            <strong>{{ titleOf(confirming) }}</strong> stops appearing on the public site within a minute.
          </p>
          <p class="text-body-2 text-medium-emphasis mb-0">
            It is kept, and <strong>Recently removed</strong> at the top of this page puts it back
            where it was. The change is recorded in the audit log.
          </p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="deleting"
            @click="confirming = null"
          >
            Keep it
          </VBtn>
          <VBtn
            color="error"
            :loading="deleting"
            @click="confirmDelete"
          >
            Remove it
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      👉 the same confirmation for several at once. Named one by one rather
      than counted: "remove 7 items" is not something anybody can check, and
      the whole reason for a confirmation is that it can be checked.
    -->
    <VDialog
      v-model="bulkConfirm"
      max-width="560"
      scrollable
    >
      <VCard>
        <VCardItem>
          <VCardTitle>Remove {{ things(selectedRows.length) }} from the website?</VCardTitle>
        </VCardItem>
        <VCardText>
          <ul class="mb-3 ps-5">
            <li
              v-for="row in selectedRows.slice(0, 8)"
              :key="row.id"
              class="text-body-2"
            >
              {{ titleOf(row) }}
            </li>
          </ul>
          <p
            v-if="selectedRows.length > 8"
            class="text-body-2 mb-3"
          >
            And {{ selectedRows.length - 8 }} more.
          </p>
          <p class="text-body-2 text-medium-emphasis mb-0">
            They are kept, and <strong>Recently removed</strong> puts them back one at a time.
            Each is recorded in the audit log.
          </p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="bulkBusy"
            @click="bulkConfirm = false"
          >
            Keep them
          </VBtn>
          <VBtn
            color="error"
            :loading="bulkBusy"
            @click="bulkRemove"
          >
            Remove {{ selectedRows.length }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- 👉 what has been removed, and putting one back -->
    <VDialog
      v-model="trashOpen"
      max-width="620"
      scrollable
    >
      <VCard>
        <VCardItem>
          <VCardTitle>Removed from {{ labelOf(tab) }}</VCardTitle>
          <VCardSubtitle class="text-wrap">
            Nothing here appears on the website. Putting one back returns it to the list in the
            place it held, under the same id the public site used for it.
          </VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VProgressLinear
          v-if="trashLoading"
          indeterminate
        />

        <VCardText>
          <VAlert
            v-if="trashError"
            type="warning"
            variant="tonal"
            class="mb-4"
          >
            <span class="text-high-emphasis">{{ trashError }}</span>
          </VAlert>

          <VAlert
            v-if="trashNotice"
            type="success"
            variant="tonal"
            class="mb-4"
          >
            {{ trashNotice }}
          </VAlert>

          <!--
            Only once the answer is in: an empty list and an unanswered request
            look identical on screen, and only one of them is nothing.
          -->
          <p
            v-if="!trashLoading && !trash.length"
            class="text-body-1 mb-0"
          >
            Nothing has been removed from {{ labelOf(tab).toLowerCase() }}.
          </p>

          <VList
            v-else
            lines="two"
          >
            <template
              v-for="(row, i) in trash"
              :key="row.id"
            >
              <VListItem>
                <!--
                  The thumbnail matters most in the collection where the title
                  is weakest: a photo's title_key is its caption, and a caption
                  is optional, so three removed photos with none read as three
                  identical rows of "(untitled — photo)". The endpoint already
                  returns every schema field, so the picture is right there.
                -->
                <template
                  v-if="imageKey"
                  #prepend
                >
                  <VAvatar
                    size="48"
                    rounded
                    variant="tonal"
                  >
                    <VImg
                      v-if="row[imageKey]"
                      :src="imageSrc(row[imageKey])"
                      cover
                    />
                    <VIcon
                      v-else
                      icon="ri-image-line"
                    />
                  </VAvatar>
                </template>

                <VListItemTitle>{{ titleOf(row) }}</VListItemTitle>
                <VListItemSubtitle>{{ removedLine(row) }}</VListItemSubtitle>

                <template #append>
                  <div class="d-flex align-center gap-1">
                    <VBtn
                      variant="tonal"
                      size="small"
                      prepend-icon="ri-arrow-go-back-line"
                      :loading="restoring === row.id"
                      :disabled="!!restoring || forceBusy"
                      @click="restoreItem(row)"
                    >
                      Put it back
                    </VBtn>
                    <!--
                      Superadmin only, and absent rather than disabled for
                      anyone else: a greyed-out "Erase" advertises a power the
                      person does not have and cannot be given from here.
                    -->
                    <VBtn
                      v-if="isSuperAdmin"
                      icon="ri-delete-bin-7-line"
                      variant="text"
                      size="small"
                      color="error"
                      :disabled="!!restoring || forceBusy"
                      :aria-label="`Erase ${titleOf(row)} for good`"
                      @click="startForceDelete(row)"
                    />
                  </div>
                </template>
              </VListItem>
              <VDivider v-if="i < trash.length - 1" />
            </template>
          </VList>
        </VCardText>

        <VDivider />

        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="!!restoring || forceBusy"
            @click="trashOpen = false"
          >
            Close
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      👉 erasing for good.

      `persistent`, alone among the dialogs here: a click landing outside this
      one must not dismiss it, because the person may be about to press a
      button whose effect nothing in this project can reverse. The tick box is
      the same idea - the destroying button stays inert until the sentence
      above it has been read and answered.
    -->
    <VDialog
      :model-value="!!forcing"
      max-width="520"
      persistent
      @update:model-value="v => { if (!v) cancelForceDelete() }"
    >
      <VCard v-if="forcing">
        <VCardItem>
          <VCardTitle class="text-error">
            Erase “{{ titleOf(forcing) }}” for good?
          </VCardTitle>
        </VCardItem>
        <VCardText>
          <p class="mb-3">
            This deletes the row from the database. It is not the same as removing it from the
            website — that already happened, and until now it could be put back.
          </p>
          <p class="text-body-2 text-medium-emphasis mb-3">
            Saved as <code>{{ forcing.legacy_id }}</code>. Afterwards there is nothing on this
            screen, and nothing in the console, that can bring it back. The audit log does keep
            a copy of what this held, for accountability — so this removes it from the website
            and from your reach here, not from the record.
          </p>
          <VCheckbox
            v-model="forceUnderstood"
            color="error"
            density="compact"
            hide-details
            label="I understand this cannot be undone."
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="forceBusy"
            @click="cancelForceDelete"
          >
            Keep it
          </VBtn>
          <VBtn
            color="error"
            variant="flat"
            :disabled="!forceUnderstood"
            :loading="forceBusy"
            @click="forceDelete"
          >
            Erase it permanently
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/*
  The header and the rows are separate grids sharing one track list, which is
  why every track but the title is a fixed size: an `auto` column would settle
  at a different width in each of them and the headings would drift off the
  columns they name.
*/
.cc__head,
.cc__row {
  display: grid;
  grid-template-columns: var(--cc-cols);
  align-items: center;
  column-gap: 0.75rem;
  padding-inline: 1rem;
}

.cc__head {
  padding-block: 0.5rem;
}

.cc__row {
  padding-block: 0.625rem;
}

.cc__row:hover {
  background: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));
}

.cc__cell {
  min-inline-size: 0;
}

.cc__cell--lead {
  display: flex;
  align-items: center;
  gap: 0.125rem;
}

.cc__cell--actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.25rem;
}

/* A long blog title must shorten rather than push the buttons off the card. */
.cc__clip {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cc__bulk {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: rgba(var(--v-theme-primary), 0.06);
}

.cc__handle {
  cursor: grab;
  opacity: 0.55;
}

.cc__row:hover .cc__handle {
  opacity: 1;
}

/* Holds the handle's width when there is no handle, so switching to a sorted
   list does not shift every tick box two millimetres to the left. */
.cc__handle-gap {
  inline-size: 18px;
}

.cc__sort {
  display: inline-flex;
  align-items: center;
  gap: 0.125rem;
  padding: 0.125rem 0.25rem;
  border: 0;
  border-radius: 0.25rem;
  background: none;
  color: inherit;
  cursor: pointer;
  font: inherit;
  margin-inline-start: -0.25rem;
}

.cc__sort:hover {
  background: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));
  color: rgb(var(--v-theme-on-surface));
}

.cc__sort:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}

.cc__sort--on {
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

/* Only while the reload it started is still out, so a second click cannot
   queue a sort the first answer will overwrite. */
.cc__sort:disabled {
  cursor: default;
  opacity: 0.6;
}

.cc__row--dragging {
  opacity: 0.4;
}

/*
  A line on the edge the row will land against, not a highlight on the row
  under the pointer: the highlight answers "which row am I over", and the only
  question being asked during a drag is "where will this end up".
*/
.cc__row--before {
  box-shadow: inset 0 2px 0 0 rgb(var(--v-theme-primary));
}

.cc__row--after {
  box-shadow: inset 0 -2px 0 0 rgb(var(--v-theme-primary));
}

/*
  Below this width the buttons alone are wider than the card, so the stamp and
  the buttons take lines of their own and the headings stop pretending to be
  columns. The handle goes with them: HTML5 drag-and-drop fires no events at
  all for touch, so on the screens that reach this breakpoint first it would be
  an affordance for something that cannot happen.
*/
@media (max-width: 62rem) {
  .cc__head,
  .cc__row {
    grid-template-columns: var(--cc-cols-sm);
    row-gap: 0.5rem;
  }

  .cc__head {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  .cc__cell--updated,
  .cc__cell--actions {
    grid-column: 1 / -1;
    justify-content: flex-start;
  }

  .cc__handle,
  .cc__handle-gap {
    display: none;
  }
}
</style>
