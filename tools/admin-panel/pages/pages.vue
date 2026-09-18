<script setup>
/*
  Which pages the site links to.

  The legacy admin page had this screen, and it wrote the answer to the editor's
  own localStorage - so switching a page off hid it in that one browser and
  nowhere else. This talks to /api/admin/pages, which keeps the state in
  site_content and audits every change as `toggle_page`.

  Two things the screen has to be honest about, because the server enforces both
  and a switch that silently refuses is worse than no switch:

    OWNER ONLY. Not `content.manage` - AdminPageController requires
    isSuperAdmin(). A content editor can write every word on the site and still
    not remove a page from it.

    THIS IS THE MENU, NOT A LOCK. A page switched off stops being linked; typing
    its address still opens it. Said on screen, because "off" reads like access
    control and it is not.
*/
definePageMeta({ title: 'Pages' })

const api = useVfiApi()
const { load: loadUser, isSuperAdmin } = useVfiUser()

const booting = ref(true)
const allowed = ref(false)
const error = ref(null)
const pages = ref([])
const busy = ref(null)
const note = ref(null)

const groups = computed(() => {
  const out = []

  for (const p of pages.value) {
    let g = out.find(x => x.name === p.group)

    if (!g) {
      g = { name: p.group, items: [] }
      out.push(g)
    }
    g.items.push(p)
  }

  return out
})

const offCount = computed(() => pages.value.filter(p => !p.enabled).length)

async function load() {
  error.value = null
  try {
    pages.value = (await api.get('/api/admin/pages')).pages
  }
  catch (e) {
    error.value = e.message || 'Could not load the page list.'
  }
}

async function toggle(page, enabled) {
  busy.value = page.file
  error.value = null
  note.value = null
  try {
    await api.put(`/api/admin/pages/${page.file}`, { enabled })
    page.enabled = enabled
    note.value = enabled
      ? `${page.label} is linked from the site again.`
      : `${page.label} is no longer linked from the site.`
  }
  catch (e) {
    /*
      The refusals are deliberate and worth reading: sign-in pages can never be
      switched off, and some pages are locked on. Showing the server's own
      sentence beats a generic failure, and the switch snaps back because
      nothing changed.
    */
    error.value = e.message
    await load()
  }
  finally {
    busy.value = null
  }
}

onMounted(async () => {
  // AWAIT the user first: checking a role before /api/admin/me answers is what
  // made the applications screen claim no access while the API returned rows.
  await loadUser()
  allowed.value = isSuperAdmin.value

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
      Only the account owner can switch pages on and off.
    </VAlert>

    <template v-else>
      <div class="mb-4">
        <h4 class="text-h4 mb-1">
          Pages
        </h4>
        <p class="text-body-2 mb-0 text-medium-emphasis">
          Switching a page off removes it from the site's menus and links.
          It does not block the address — anyone who has the link can still open it.
        </p>
      </div>

      <VAlert
        v-if="error"
        type="error"
        variant="tonal"
        class="mb-4"
        closable
        @click:close="error = null"
      >
        {{ error }}
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

      <VAlert
        v-if="!error && offCount"
        type="warning"
        variant="tonal"
        class="mb-4"
      >
        <span class="text-high-emphasis">
          {{ offCount }} {{ offCount === 1 ? 'page is' : 'pages are' }} switched off and not linked anywhere.
        </span>
      </VAlert>

      <VCard
        v-for="g in groups"
        :key="g.name"
        class="mb-4"
      >
        <VCardItem>
          <VCardTitle>{{ g.name }}</VCardTitle>
        </VCardItem>
        <VDivider />
        <VList>
          <template
            v-for="(p, i) in g.items"
            :key="p.file"
          >
            <VListItem>
              <VListItemTitle>{{ p.label }}</VListItemTitle>
              <VListItemSubtitle>
                <code>{{ p.file }}</code>
                <span
                  v-if="p.locked"
                  class="ms-2"
                >· always on</span>
              </VListItemSubtitle>

              <template #append>
                <VSwitch
                  :model-value="p.enabled"
                  :disabled="p.locked || busy === p.file"
                  :loading="busy === p.file"
                  color="primary"
                  hide-details
                  inset
                  :aria-label="`Show ${p.label} in the site menus`"
                  @update:model-value="v => toggle(p, v)"
                />
              </template>
            </VListItem>
            <VDivider v-if="i < g.items.length - 1" />
          </template>
        </VList>
      </VCard>
    </template>
  </div>
</template>
