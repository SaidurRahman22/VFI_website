<script setup>
/*
  The per-slug content: country pages, region pages, the services page.

  These three were the last content in the project with no editor ANYWHERE.
  They save through the same endpoint as the flat singletons and always could -
  update() takes any JSON with optimistic concurrency - but they were left out
  of AdminContentController::SCHEMA because a flat key-and-string form cannot
  express "a set of repeating blocks, once per country". So they sat editable
  by the API and unreachable by any person.

  The shape comes from the server, in `grouped`: `groups` (the slugs, empty for
  a one-of-a-kind page like services), `fields` (plain text for the selected
  slug) and `lists` (the repeating blocks). A key carries either `sections` or
  `grouped`, never both, which is how a page picks its editor.

  Stored shape, so the nesting is not a surprise:
      with slugs   { usa: { heroTitle: '...', universities: [ {...} ] }, uk: {...} }
      without      { blocks: [ {...} ] }

  Deleting a row is deliberately two clicks and never silent: a repeating block
  has no trash to restore from, unlike the content collections, so the only
  protection is not doing it by accident.
*/
const props = defineProps({
  singletonKey: { type: String, required: true },
  heading: { type: String, default: '' },
})

const api = useVfiApi()
const { can, load: loadUser } = useVfiUser()

const booting = ref(true)
const allowed = ref(false)
const loadError = ref(null)

const meta = ref({ label: props.heading, blurb: '', empty_means: null, groups: [], fields: [], lists: [] })
const value = ref({})
const saved = ref('{}')
const version = ref(0)
const slug = ref(null)

const saving = ref(false)
const saveError = ref(null)
const conflict = ref(false)
const note = ref(null)
const confirmDelete = ref(null)   // `${listKey}:${index}` awaiting a second click

const hasSlugs = computed(() => (meta.value.groups || []).length > 0)

/* The object being edited: one slug's bucket, or the whole value when the page
   is one of a kind. Created on demand so an untouched country stays absent from
   the payload rather than being written as an empty object. */
const current = computed(() => {
  if (!hasSlugs.value) return value.value
  if (!slug.value) return {}
  if (!value.value[slug.value] || typeof value.value[slug.value] !== 'object') {
    value.value[slug.value] = {}
  }
  return value.value[slug.value]
})

const dirty = computed(() => JSON.stringify(value.value) !== saved.value)

/* A `lines` list is stored as one newline-separated string, not an array, because
   that is what js/render.js clines() reads. Bound through a getter/setter pair so
   the textarea can write straight into the value object. */
function linesOf(listKey) {
  const v = current.value[listKey]
  if (Array.isArray(v)) return v.join('\n')   // tolerate an array left by an older save
  return typeof v === 'string' ? v : ''
}

function setLines(listKey, text) {
  current.value[listKey] = text
}

/* True when this list's section does not exist in THIS country's markup, so
   anything typed here would save and display nowhere. */
function absentHere(list) {
  return hasSlugs.value && Array.isArray(list.absent_on) && list.absent_on.includes(slug.value)
}

function rows(listKey) {
  const bucket = current.value
  if (!Array.isArray(bucket[listKey])) bucket[listKey] = []
  return bucket[listKey]
}

/* How many rows each list holds for the CURRENT slug - shown on the tab so an
   editor can see at a glance which countries have been filled in. */
function filledCount(groupSlug) {
  const b = value.value[groupSlug]
  if (!b || typeof b !== 'object') return 0
  return Object.values(b).reduce((n, v) => n + (Array.isArray(v) ? v.length : (String(v || '').trim() ? 1 : 0)), 0)
}

function addRow(list) {
  const blank = {}
  for (const f of list.item) blank[f.key] = ''
  rows(list.key).push(blank)
  note.value = null
}

function removeRow(list, i) {
  const token = `${list.key}:${i}`
  if (confirmDelete.value !== token) { confirmDelete.value = token; return }
  rows(list.key).splice(i, 1)
  confirmDelete.value = null
}

function moveRow(list, i, delta) {
  const arr = rows(list.key)
  const j = i + delta
  if (j < 0 || j >= arr.length) return
  const [row] = arr.splice(i, 1)
  arr.splice(j, 0, row)
  confirmDelete.value = null
}

async function load() {
  loadError.value = null
  try {
    const res = await api.get(`/api/admin/content/singleton/${props.singletonKey}`)
    const g = res.grouped || { groups: [], fields: [], lists: [] }
    meta.value = {
      label: res.label || props.heading,
      blurb: res.blurb || '',
      empty_means: res.empty_means || null,
      groups: g.groups || [],
      fields: g.fields || [],
      lists: g.lists || [],
    }
    // `value` may come back as {} from PHP's stdClass, or as [] if it was ever
    // stored empty - normalise so property writes below always work.
    const v = res.value && typeof res.value === 'object' && !Array.isArray(res.value) ? res.value : {}
    value.value = v
    saved.value = JSON.stringify(v)
    version.value = res.version ?? 0
    if (meta.value.groups.length && !slug.value) slug.value = meta.value.groups[0].slug
  } catch (e) {
    loadError.value = e.message || 'Could not load this content.'
  }
}

async function reloadFromServer() {
  conflict.value = false
  saveError.value = null
  await load()
  note.value = 'Reloaded what is stored on the server.'
}

function revert() {
  value.value = JSON.parse(saved.value)
  confirmDelete.value = null
  note.value = null
}

async function save() {
  saving.value = true
  saveError.value = null
  conflict.value = false
  note.value = null
  try {
    const res = await api.put(`/api/admin/content/singleton/${props.singletonKey}`, {
      value: value.value,
      version: version.value,
    })
    version.value = res.version ?? version.value + 1
    saved.value = JSON.stringify(value.value)
    note.value = 'Saved.'
  } catch (e) {
    saveError.value = e.message || 'Could not save.'
    // The endpoint answers 409 when someone else saved first. Say so plainly
    // rather than as a generic failure: nothing of theirs was overwritten.
    conflict.value = /changed by someone else|409/i.test(saveError.value)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadUser()
  allowed.value = can('content.manage')
  if (allowed.value) await load()
  booting.value = false
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
      v-else-if="loadError"
      type="error"
      variant="tonal"
    >
      {{ loadError }}
    </VAlert>

    <template v-else>
      <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
        <div>
          <h4 class="text-h4 mb-1">
            {{ meta.label }}
          </h4>
          <p class="text-body-2 mb-0 text-medium-emphasis">
            {{ meta.blurb }}
          </p>
        </div>

        <div class="d-flex align-center gap-2">
          <VBtn
            v-if="dirty"
            variant="text"
            :disabled="saving"
            @click="revert"
          >
            Undo changes
          </VBtn>
          <VBtn
            :loading="saving"
            :disabled="!dirty"
            @click="save"
          >
            {{ dirty ? 'Save changes' : 'No changes' }}
          </VBtn>
        </div>
      </div>

      <VAlert
        v-if="conflict"
        type="warning"
        variant="tonal"
        class="mb-4"
      >
        <p class="mb-2 text-high-emphasis">
          {{ saveError }}
        </p>
        <p class="text-body-2 mb-3">
          Nothing was overwritten — their save stands and yours was not applied.
        </p>
        <VBtn
          size="small"
          variant="tonal"
          @click="reloadFromServer"
        >
          Reload what is stored
        </VBtn>
      </VAlert>

      <VAlert
        v-else-if="saveError"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ saveError }}
      </VAlert>

      <VAlert
        v-if="meta.empty_means"
        type="info"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        Leave a field empty and the page {{ meta.empty_means }} — an empty box is
        not a blank page.
      </VAlert>

      <!-- Which country / region. Absent for a one-of-a-kind page. -->
      <VTabs
        v-if="hasSlugs"
        v-model="slug"
        class="mb-4"
        show-arrows
      >
        <VTab
          v-for="g in meta.groups"
          :key="g.slug"
          :value="g.slug"
        >
          {{ g.label }}
          <VChip
            v-if="filledCount(g.slug)"
            size="x-small"
            class="ms-2"
            label
          >
            {{ filledCount(g.slug) }}
          </VChip>
        </VTab>
      </VTabs>

      <!-- Plain text for the selected slug -->
      <VCard
        v-if="meta.fields.length"
        class="mb-6"
      >
        <VCardText>
          <VRow>
            <VCol
              v-for="f in meta.fields"
              :key="f.key"
              cols="12"
              :md="f.half ? 6 : 12"
            >
              <VTextarea
                v-if="f.type === 'textarea'"
                v-model="current[f.key]"
                :label="f.label"
                :hint="f.hint"
                persistent-hint
                rows="2"
                auto-grow
              />
              <VTextField
                v-else
                v-model="current[f.key]"
                :label="f.label"
                :hint="f.hint"
                persistent-hint
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- The repeating blocks -->
      <VCard
        v-for="list in meta.lists"
        :key="list.key"
        class="mb-6"
      >
        <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
          <span>{{ list.label }}</span>
          <VBtn
            v-if="!list.lines && !absentHere(list)"
            size="small"
            variant="tonal"
            prepend-icon="ri-add-line"
            @click="addRow(list)"
          >
            Add {{ list.singular }}
          </VBtn>
        </VCardTitle>

        <VCardText>
          <!--
            Said out loud rather than left inert. This section does not exist in
            this country's page, so anything typed would save and show nowhere -
            which is exactly the trap these fields were withheld to avoid.
          -->
          <VAlert
            v-if="absentHere(list)"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-0"
          >
            This page has no {{ list.label.toLowerCase() }} section, so there is nothing here to
            change for {{ (meta.groups.find(g => g.slug === slug) || {}).label }}. The other
            countries do have one.
          </VAlert>

          <!-- One line each: a textarea, not a repeater of one-field rows. -->
          <VTextarea
            v-else-if="list.lines"
            :model-value="linesOf(list.key)"
            :label="list.label"
            hint="One per line. Blank lines are ignored, and the order here is the order on the page."
            persistent-hint
            rows="4"
            auto-grow
            @update:model-value="setLines(list.key, $event)"
          />

          <template v-else>
          <p
            v-if="!rows(list.key).length"
            class="text-body-2 text-medium-emphasis mb-0"
          >
            Nothing here yet — the page shows whatever is built into it.
          </p>

          <div
            v-for="(row, i) in rows(list.key)"
            :key="i"
            class="gce__row"
          >
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis">
                {{ list.singular }} {{ i + 1 }} of {{ rows(list.key).length }}
              </span>
              <div class="d-flex align-center gap-1">
                <VBtn
                  icon="ri-arrow-up-line"
                  size="x-small"
                  variant="text"
                  :disabled="i === 0"
                  @click="moveRow(list, i, -1)"
                />
                <VBtn
                  icon="ri-arrow-down-line"
                  size="x-small"
                  variant="text"
                  :disabled="i === rows(list.key).length - 1"
                  @click="moveRow(list, i, 1)"
                />
                <VBtn
                  size="x-small"
                  :variant="confirmDelete === `${list.key}:${i}` ? 'flat' : 'text'"
                  :color="confirmDelete === `${list.key}:${i}` ? 'error' : undefined"
                  @click="removeRow(list, i)"
                >
                  {{ confirmDelete === `${list.key}:${i}` ? 'Tap again to remove' : 'Remove' }}
                </VBtn>
              </div>
            </div>

            <VRow>
              <VCol
                v-for="f in list.item"
                :key="f.key"
                cols="12"
                :md="f.half ? 6 : 12"
              >
                <VTextarea
                  v-if="f.type === 'textarea' || f.type === 'lines'"
                  v-model="row[f.key]"
                  :label="f.label"
                  :hint="f.type === 'lines' ? (f.hint || 'One per line.') : f.hint"
                  persistent-hint
                  rows="2"
                  auto-grow
                />
                <VTextField
                  v-else
                  v-model="row[f.key]"
                  :label="f.label"
                  :hint="f.hint"
                  persistent-hint
                />
              </VCol>
            </VRow>
            </div>
          </template>
        </VCardText>
      </VCard>

      <VSnackbar
        :model-value="!!note"
        timeout="2500"
        @update:model-value="note = null"
      >
        {{ note }}
      </VSnackbar>
    </template>
  </div>
</template>

<style scoped>
/* A visible boundary per row: without it a long list of text fields reads as
   one undifferentiated column and it stops being obvious which inputs belong
   to which university. */
.gce__row + .gce__row {
  margin-block-start: 1.25rem;
  padding-block-start: 1.25rem;
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>
