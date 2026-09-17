<script setup>
/*
  Website content: the ten collections that fill the public site.

  This is the screen that lets admin.html go. That page had a tab per
  collection but was READ-ONLY - its "New event" buttons deep-linked into the
  Filament panel - so this is the first place in the project where a person can
  actually write website content without leaving the console.

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

  4. Tabs are in the URL (?tab=blogs) so a particular collection is linkable and
     a refresh does not dump you back on Events.
*/
definePageMeta({ title: 'Website content' })

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

const rowBusy = ref(null)

function labelOf(slug) {
  return tabs.value.find(t => t.slug === slug)?.label || 'Content'
}

async function loadTabs() {
  const res = await api.get('/api/admin/content/collections')

  tabs.value = res.data
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

  deleting.value = true
  try {
    await api.del(`/api/admin/content/${tab.value}/${row.id}`)
    confirming.value = null
    await loadList(tab.value)
    await refreshCounts()
  }
  catch (e) {
    listError.value = e.message
    confirming.value = null
  }
  finally {
    deleting.value = false
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
            Website content
          </h4>
          <p class="text-body-2 mb-0 text-medium-emphasis">
            Everything here appears on the public site. Changes are live as soon as you save.
          </p>
        </div>

        <VBtn
          v-if="tab"
          prepend-icon="ri-add-line"
          :disabled="loading"
          @click="startCreate"
        >
          New {{ list.singular.toLowerCase() }}
        </VBtn>
      </div>

      <VCard>
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

        <VCardText v-if="!loading && !list.data.length">
          <p class="text-body-1 mb-0">
            There is nothing in {{ labelOf(tab).toLowerCase() }} yet. Use
            <strong>New {{ list.singular.toLowerCase() }}</strong> to add the first one.
          </p>
        </VCardText>

        <VList
          v-else
          lines="two"
        >
          <template
            v-for="(row, i) in list.data"
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
                    icon="ri-arrow-up-line"
                    variant="text"
                    size="small"
                    :disabled="i === 0 || rowBusy === row.id"
                    :aria-label="`Move ${titleOf(row)} up`"
                    @click="move(row, 'up')"
                  />
                  <VBtn
                    icon="ri-arrow-down-line"
                    variant="text"
                    size="small"
                    :disabled="i === list.data.length - 1 || rowBusy === row.id"
                    :aria-label="`Move ${titleOf(row)} down`"
                    @click="move(row, 'down')"
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
            <VDivider v-if="i < list.data.length - 1" />
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
          <VCardSubtitle v-if="editing">
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
            It is kept in the database and can be restored, and the change is recorded in the audit log.
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
  </div>
</template>
