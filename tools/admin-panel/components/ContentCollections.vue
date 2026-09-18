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

  Four deliberate choices:

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
const { can, load: loadUser } = useVfiUser()

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

/* ---- what has been removed, and putting one back ---- */
const trashOpen = ref(false)
const trashLoading = ref(false)
const trashError = ref(null)
const trash = ref([])
const restoring = ref(null)

/* The sentence after an action. The list only shows the result, so on its own
   it cannot distinguish "removed" from "put back" from "never saved". */
const notice = ref(null)

/* The client-side filter over the rows already in the browser. `clearable`
   writes null into this, so nothing may assume it is a string. */
const filter = ref('')

const rowBusy = ref(null)

function labelOf(slug) {
  return tabs.value.find(t => t.slug === slug)?.label || 'Content'
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
  try {
    list.value = await api.get(`/api/admin/content/${slug}`)
  }
  catch (e) {
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

  /* All three belong to the collection being left, not to the one arriving: a
     filter typed for blog titles hides most of the photos, and a sentence about
     a restored event means nothing over a list of documents. */
  filter.value = ''
  notice.value = null
  trashOpen.value = false

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
  trashOpen.value = true
  await loadTrash()
}

async function restoreItem(row) {
  restoring.value = row.id
  trashError.value = null
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
    trashError.value = e.message
    await loadTrash()
  }
  finally {
    restoring.value = null
  }
}

async function move(row, direction) {
  rowBusy.value = row.id
  listError.value = null
  try {
    await api.put(`/api/admin/content/${tab.value}/${row.id}/move`, { direction })
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

        <VAlert
          v-if="listError"
          type="warning"
          variant="tonal"
          class="ma-4"
          closable
          @click:close="listError = null"
        >
          {{ listError }}
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
              ? 'While filtered, a row can go to the top or the bottom. One step up or down is hidden: it would swap this row with one you cannot see.'
              : ''"
          />

          <p
            v-if="filtering"
            class="text-body-2 text-medium-emphasis mb-0"
          >
            Showing {{ filtered.length }} of {{ list.data.length }}.
          </p>
        </div>

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

        <VList
          v-else
          lines="two"
        >
          <template
            v-for="(row, i) in filtered"
            :key="row.id"
          >
            <VListItem>
              <template
                v-if="imageKey"
                #prepend
              >
                <VAvatar
                  size="48"
                  rounded
                  :color="row[imageKey] ? undefined : 'secondary'"
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
              <VListItemSubtitle v-if="metaOf(row)">
                {{ metaOf(row) }}
              </VListItemSubtitle>

              <template #append>
                <div class="d-flex align-center gap-1">
                  <VBtn
                    icon="ri-skip-up-line"
                    variant="text"
                    size="small"
                    :disabled="isFirst(row) || rowBusy === row.id"
                    :aria-label="`Move ${titleOf(row)} to the top`"
                    @click="move(row, 'top')"
                  />
                  <VBtn
                    v-if="!filtering"
                    icon="ri-arrow-up-line"
                    variant="text"
                    size="small"
                    :disabled="isFirst(row) || rowBusy === row.id"
                    :aria-label="`Move ${titleOf(row)} up`"
                    @click="move(row, 'up')"
                  />
                  <VBtn
                    v-if="!filtering"
                    icon="ri-arrow-down-line"
                    variant="text"
                    size="small"
                    :disabled="isLast(row) || rowBusy === row.id"
                    :aria-label="`Move ${titleOf(row)} down`"
                    @click="move(row, 'down')"
                  />
                  <VBtn
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
              </template>
            </VListItem>
            <VDivider v-if="i < filtered.length - 1" />
          </template>
        </VList>
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
                    color="secondary"
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
            <strong>{{ titleOf(confirming) }}</strong> will stop appearing on the public site immediately.
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
            {{ trashError }}
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
                <VListItemTitle>{{ titleOf(row) }}</VListItemTitle>
                <VListItemSubtitle>{{ removedLine(row) }}</VListItemSubtitle>

                <template #append>
                  <VBtn
                    variant="tonal"
                    size="small"
                    prepend-icon="ri-arrow-go-back-line"
                    :loading="restoring === row.id"
                    :disabled="!!restoring"
                    @click="restoreItem(row)"
                  >
                    Put it back
                  </VBtn>
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
            :disabled="!!restoring"
            @click="trashOpen = false"
          >
            Close
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
