<script setup>
/*
  The dashboard answers one question: what is waiting on us right now.

  Every number is read from /api/admin/applications, which returns the real
  per-status counts for the whole queue. Nothing here is computed in the browser
  from a partial page of rows, and nothing is a placeholder - if a figure cannot
  be loaded, this says so instead of showing a confident zero.
*/
definePageMeta({ title: 'Dashboard' })

const api = useVfiApi()
const { user, can, load: loadUser } = useVfiUser()

const loading = ref(true)
const error = ref(null)
const counts = ref({})
const waiting = ref(0)
const total = ref(0)
const recent = ref([])

/* The statuses worth a tile, in the order a case actually travels. */
const TILES = [
  { key: 'submitted', label: 'Submitted', icon: 'ri-inbox-line', color: 'primary' },
  { key: 'review', label: 'Under review', icon: 'ri-search-eye-line', color: 'info' },
  { key: 'offer', label: 'Offer', icon: 'ri-mail-check-line', color: 'success' },
  { key: 'pending_from_partner', label: 'Waiting on agency', icon: 'ri-time-line', color: 'warning' },
  { key: 'visa_received', label: 'Visa received', icon: 'ri-passport-line', color: 'success' },
  { key: 'visa_rejected', label: 'Visa rejected', icon: 'ri-close-circle-line', color: 'error' },
]

async function load() {
  loading.value = true
  error.value = null
  try {
    // per_page kept small: this page needs the COUNTS, which come from the
    // whole queue regardless, plus a short "latest" list.
    const res = await api.get('/api/admin/applications?per_page=5')

    counts.value = res.meta.counts || {}
    waiting.value = res.meta.waiting || 0
    total.value = res.meta.total || 0
    recent.value = res.data || []
  }
  catch (e) {
    error.value = e.message || 'Could not load the queue.'
  }
  finally {
    loading.value = false
  }
}

onMounted(async () => {
  /*
    AWAIT the user before asking what they may do. The layout loads it too, and
    useVfiUser de-duplicates, so this costs nothing - but checking can() without
    waiting is a race the page loses every time: abilities are still null, the
    deny-by-default answer comes back false, and the screen renders "no access"
    while the API would have returned everything.
  */
  await loadUser()

  if (can('applications.process'))
    await load()
  else
    loading.value = false
})
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between mb-6 gap-3">
      <div>
        <h4 class="text-h4 mb-1">
          {{ user?.name ? `Welcome back, ${user.name.split(' ')[0]}` : 'Welcome back' }}
        </h4>
        <span class="text-body-1 text-disabled">
          Applications waiting on VFI right now
        </span>
      </div>
      <VBtn
        v-if="can('applications.process')"
        variant="tonal"
        prepend-icon="ri-refresh-line"
        :loading="loading"
        @click="load"
      >
        Refresh
      </VBtn>
    </div>

    <!-- A role with no queue access is told so, not shown empty tiles. -->
    <VAlert
      v-if="!can('applications.process')"
      type="info"
      variant="tonal"
      class="mb-6"
    >
      Your role does not include processing applications, so the queue is not shown here.
      Use the sidebar for the areas you do have access to.
    </VAlert>

    <VAlert
      v-else-if="error"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ error }}
    </VAlert>

    <template v-else>
      <!-- 👉 the headline: the work queue -->
      <VCard class="mb-6">
        <VCardText class="d-flex flex-wrap align-center gap-6">
          <div>
            <div class="text-disabled text-sm mb-1">
              Waiting on us
            </div>
            <div class="d-flex align-center gap-2">
              <VProgressCircular
                v-if="loading"
                indeterminate
                size="28"
                width="3"
              />
              <span
                v-else
                class="text-h3"
              >{{ waiting }}</span>
              <span class="text-disabled text-sm">
                submitted + under review
              </span>
            </div>
          </div>

          <VDivider vertical class="d-none d-md-block" />

          <div>
            <div class="text-disabled text-sm mb-1">
              All applications
            </div>
            <span class="text-h5">{{ loading ? '—' : total }}</span>
          </div>

          <VSpacer />

          <VBtn
            to="/applications"
            append-icon="ri-arrow-right-line"
          >
            Open the queue
          </VBtn>
        </VCardText>
      </VCard>

      <!-- 👉 per-status tiles -->
      <VRow class="mb-2">
        <VCol
          v-for="tile in TILES"
          :key="tile.key"
          cols="12"
          sm="6"
          md="4"
        >
          <VCard>
            <VCardText class="d-flex align-center gap-4">
              <VAvatar
                :color="tile.color"
                variant="tonal"
                rounded
                size="42"
              >
                <VIcon :icon="tile.icon" size="24" />
              </VAvatar>
              <div>
                <div class="text-h5">
                  {{ loading ? '—' : (counts[tile.key] ?? 0) }}
                </div>
                <div class="text-disabled text-sm">
                  {{ tile.label }}
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- 👉 latest cases -->
      <VCard title="Latest applications">
        <VDivider />
        <VTable v-if="recent.length">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Student</th>
              <th>Agency</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in recent"
              :key="row.id"
            >
              <td class="text-no-wrap">
                {{ row.ref }}
              </td>
              <td>{{ row.student.name }}</td>
              <td>{{ row.agency_name }}</td>
              <td>
                <VChip size="small" variant="tonal">
                  {{ row.status_label }}
                </VChip>
              </td>
              <td class="text-end">
                <VBtn
                  size="small"
                  variant="text"
                  :to="`/applications?open=${row.id}`"
                >
                  Open
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-else-if="!loading" class="text-disabled">
          No applications have been filed yet.
        </VCardText>
        <VCardText v-else>
          <VProgressLinear indeterminate />
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
