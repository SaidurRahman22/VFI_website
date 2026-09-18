<script setup>
/*
  One editor for any FLAT singleton the server declares a form for.

  AdminContentController::SCHEMA describes three of them - `settings`,
  `partnerPage` and `partnerPortal` - with their sections, labels, field types
  and `empty_means` text. Only `settings` ever had a screen, so the other two
  were fully specified on the server and unreachable for the person meant to
  edit them. This component is that screen for all of them, and the pages over
  it are thin wrappers, the same way content/public.vue and content/partner.vue
  sit over ContentCollections.vue.

  Three things it does that the legacy localStorage admin could not:

  1. It SAVES. PUT /api/admin/content/singleton/<key> writes to site_content and
     audits the change, rather than to the editor's own browser.

  2. It respects the version. That endpoint uses optimistic concurrency: a save
     carries the version it loaded, and a stale one is refused with 409 instead
     of quietly overwriting whoever saved first. The refusal is shown as what it
     is, with the option to reload.

  3. It shows what is stored, filling every field from the server on open.

  The form is built from the schema the API sends, so the fields are declared in
  one place - which is why adding these two screens needed no new field lists.

  NOT for `countries`, `regions` or `servicesPage`. Those are editable through
  the same endpoint but hold repeating blocks per slug
  ({uk: {heroTitle: ..., bands: [...]}}), which a key-and-string form cannot
  express; the controller leaves them out of SCHEMA deliberately and they still
  need a screen of their own.
*/
const props = defineProps({
  /* A key present in AdminContentController::SCHEMA. */
  singletonKey: { type: String, required: true },
  /* Shown until the server's own label arrives, so the heading is never blank. */
  heading: { type: String, default: '' },
})

const api = useVfiApi()
const { can, load: loadUser } = useVfiUser()

const booting = ref(true)
const allowed = ref(false)
const loadError = ref(null)

const meta = ref({ label: props.heading, blurb: '', empty_means: null, sections: [] })
const form = ref({})
const version = ref(0)
const saved = ref({})

const saving = ref(false)
const saveError = ref(null)
const conflict = ref(false)
const fieldErrors = ref({})
const note = ref(null)

/* Every field the schema declares, flattened out of its sections. */
const fields = computed(() => (meta.value.sections || []).flatMap(s => s.fields))

const dirty = computed(() =>
  fields.value.some(f => (form.value[f.key] ?? '') !== (saved.value[f.key] ?? '')))

async function load() {
  loadError.value = null
  try {
    const res = await api.get(`/api/admin/content/singleton/${props.singletonKey}`)

    meta.value = res
    version.value = res.version

    // The stored value, with a controlled '' for anything absent - a null
    // v-model renders the string "null" in a VTextField.
    const value = res.value || {}
    const next = {}

    for (const f of (res.sections || []).flatMap(s => s.fields))
      next[f.key] = value[f.key] ?? ''

    form.value = next
    saved.value = { ...next }
  }
  catch (e) {
    loadError.value = e.message || 'Could not load this page\u2019s text.'
  }
}

async function save() {
  saving.value = true
  saveError.value = null
  conflict.value = false
  fieldErrors.value = {}
  note.value = null

  try {
    /*
      Merge over what was loaded rather than sending only the form's keys: the
      stored object may carry keys this schema does not describe yet, and a save
      that sent just the form would delete them.
    */
    const value = { ...(meta.value.value || {}) }

    for (const f of fields.value)
      value[f.key] = String(form.value[f.key] ?? '').trim()

    const res = await api.put(`/api/admin/content/singleton/${props.singletonKey}`, {
      version: version.value,
      value,
    })

    version.value = res.version
    saved.value = { ...form.value }
    meta.value = { ...meta.value, value: res.value }
    // Stored at once; the public pages hold the content bundle for 60
    // seconds, so "straight away" was not true of what a visitor sees.
    note.value = 'Saved. The public pages pick these up within a minute.'
  }
  catch (e) {
    if (e.status === 409) {
      // Somebody else saved while this form was open. Their work is intact;
      // this one has to be reapplied, and the screen says so instead of
      // pretending the save worked.
      conflict.value = true
      saveError.value = e.message
    }
    else {
      saveError.value = e.message
      fieldErrors.value = e.errors || {}
    }
  }
  finally {
    saving.value = false
  }
}

async function reloadFromServer() {
  conflict.value = false
  saveError.value = null
  await load()
  note.value = 'Reloaded. This is what is stored now.'
}

function revert() {
  form.value = { ...saved.value }
  note.value = null
  saveError.value = null
}

onMounted(async () => {
  // AWAIT the user before deciding what to draw - checking can() first is what
  // made the applications screen claim no access while the API returned rows.
  await loadUser()
  allowed.value = can('content.manage')

  if (allowed.value)
    await load()

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
            {{ dirty ? 'Save changes' : 'Saved' }}
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
        closable
        @click:close="saveError = null"
      >
        {{ saveError }}
      </VAlert>

      <VAlert
        v-if="note"
        type="success"
        variant="tonal"
        class="mb-4"
        closable
        @click:close="note = null"
      >
        {{ note }}
      </VAlert>

      <VCard
        v-for="section in meta.sections"
        :key="section.title"
        class="mb-4"
      >
        <VCardItem>
          <VCardTitle>{{ section.title }}</VCardTitle>
          <VCardSubtitle v-if="section.blurb">
            {{ section.blurb }}
          </VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol
              v-for="f in section.fields"
              :key="f.key"
              cols="12"
              :md="f.half ? 6 : 12"
            >
              <VTextarea
                v-if="f.type === 'textarea'"
                v-model="form[f.key]"
                :label="f.label"
                rows="3"
                auto-grow
                :hint="f.hint"
                persistent-hint
                :error-messages="fieldErrors[`value.${f.key}`]"
              />
              <VTextField
                v-else
                v-model="form[f.key]"
                :label="f.label"
                :type="f.type === 'email' ? 'email' : 'text'"
                :hint="f.hint"
                persistent-hint
                :error-messages="fieldErrors[`value.${f.key}`]"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <p
        v-if="meta.empty_means"
        class="text-body-2 text-medium-emphasis"
      >
        An empty field {{ meta.empty_means }}.
      </p>
    </template>
  </div>
</template>
