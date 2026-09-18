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

/* ---- the trend chart ---- */

/*
  A separate request from the queue, because it answers a different question and
  has its own control. The window re-queries rather than slicing a cached
  series: the totals printed under the chart have to belong to the window the
  buttons say is selected.
*/
const RANGES = [
  { days: 7, label: '7 days' },
  { days: 30, label: '30 days' },
  { days: 90, label: '90 days' },
]

const range = ref(30)
const trend = ref(null)
const trendLoading = ref(true)
const trendError = ref(null)

async function loadTrend() {
  trendLoading.value = true
  trendError.value = null
  try {
    trend.value = await api.get(`/api/admin/applications/trend?days=${range.value}`)
  }
  catch (e) {
    trendError.value = e.message || 'Could not load the trend.'
  }
  finally {
    trendLoading.value = false
  }
}

watch(range, loadTrend)

/*
  Two series against a real date axis. ApexCharts is already a dependency of
  this template and ships a client-only wrapper, so there is no new library
  here - and it must stay client-only, because the console is prerendered to
  static HTML and a chart cannot be drawn at build time.
*/
const chartSeries = computed(() => [
  { name: 'Arrived', data: (trend.value?.points || []).map(p => [`${p.date}T00:00:00`, p.arrived]) },
  { name: 'Decided', data: (trend.value?.points || []).map(p => [`${p.date}T00:00:00`, p.decided]) },
])

/* Whether there is enough movement to be worth reading as a shape. */
const trendIsFlat = computed(() => {
  const t = trend.value?.totals

  return !t || (t.arrived + t.decided) <= 1
})

const trendIsEmpty = computed(() => {
  const t = trend.value?.totals

  return !!trend.value && (!t || (t.arrived + t.decided) === 0)
})

/*
  How stale the queue is, in days, from the newest thing anywhere - which the
  endpoint reports even when it falls outside the window. Without this an empty
  chart cannot tell "nothing has ever happened" from "nothing happened in the
  last month", and those call for different reactions.
*/
const daysSinceLatest = computed(() => {
  if (!trend.value?.latest)
    return null

  const then = new Date(`${trend.value.latest}T00:00:00`)
  const now = new Date(`${trend.value.to}T00:00:00`)

  return Math.round((now - then) / 86400000)
})

/* The smallest offered window that would actually contain that last activity. */
const windowThatWouldShowIt = computed(() => {
  const gap = daysSinceLatest.value

  return gap === null ? null : (RANGES.find(r => r.days > gap) || null)
})

/* The largest count in either series, for the axis. */
const trendPeak = computed(() => (trend.value?.points || [])
  .reduce((m, p) => Math.max(m, p.arrived, p.decided), 0))

const chartOptions = computed(() => ({
  chart: {
    type: 'area',
    height: 280,
    parentHeightOffset: 0,
    toolbar: { show: false },
    zoom: { enabled: false },
    fontFamily: 'DM Sans, sans-serif',
  },
  // The site's own blue for work coming in, its green for work going out.
  colors: ['#2f62a8', '#12a06a'],
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.02 } },
  legend: { position: 'top', horizontalAlign: 'right', markers: { radius: 4 } },
  grid: { borderColor: 'rgba(20, 28, 38, 0.08)', strokeDashArray: 4 },
  xaxis: {
    type: 'datetime',
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { format: 'd MMM' },
  },
  yaxis: {
    // Counts are whole cases. Without min/max a range of 0-1 draws 0.2, 0.4 …
    // and an all-zero series gives forceNiceScale a zero-height range to
    // divide, which it labels "Infinity".
    min: 0,
    max: Math.max(2, trendPeak.value),
    tickAmount: Math.min(4, Math.max(2, trendPeak.value)),
    labels: { formatter: v => String(Math.round(v)) },
  },
  // One tooltip carrying both numbers, because the comparison IS the point.
  tooltip: { shared: true, intersect: false, x: { format: 'EEEE d MMMM' } },
  noData: { text: 'Nothing in this period.' },
}))

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

  if (can('applications.process')) {
    await load()
    await loadTrend()
  }
  else {
    loading.value = false
    trendLoading.value = false
  }
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
        :loading="loading || trendLoading"
        @click="() => { load(); loadTrend() }"
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

      <!--
        👉 arrivals against decisions

        NOT a chart of the tiles above: those already print the per-status
        counts, and redrawing them would be decoration. This is the question the
        tiles cannot answer - is work coming in faster than it is going out.
      -->
      <VCard class="mb-6">
        <VCardItem>
          <VCardTitle>Arriving and being decided</VCardTitle>
          <VCardSubtitle>
            Applications submitted by partners, against decisions VFI recorded on them.
          </VCardSubtitle>

        </VCardItem>

        <!--
          Three plain buttons rather than a VBtnToggle. In the #append slot the
          toggle was drawn with its three buttons on top of each other
          ("7 Day30 Da90 Days"); moved into its own row it still collapsed the
          group to 122px, giving each button 36px to hold 50px of text. A
          v-btn-group sizes its children itself and wins the argument, so this
          stops having the argument: the selected one is filled, the others are
          tonal, and each is as wide as its own label.
        -->
        <div class="d-flex flex-wrap gap-2 px-4 pb-4">
          <VBtn
            v-for="r in RANGES"
            :key="r.days"
            size="small"
            :variant="range === r.days ? 'flat' : 'tonal'"
            :color="range === r.days ? 'primary' : undefined"
            :aria-pressed="range === r.days"
            @click="range = r.days"
          >
            {{ r.label }}
          </VBtn>
        </div>

        <VDivider />

        <VCardText>
          <VAlert
            v-if="trendError"
            type="warning"
            variant="tonal"
            class="mb-4"
          >
            <span class="text-high-emphasis">{{ trendError }}</span>
          </VAlert>

          <div
            v-else-if="trendLoading"
            class="d-flex align-center justify-center"
            style="min-height: 280px"
          >
            <VProgressCircular
              indeterminate
              size="32"
              width="3"
            />
          </div>

          <template v-else>
            <div class="d-flex flex-wrap gap-6 mb-2">
              <div>
                <div class="text-disabled text-sm">
                  Arrived
                </div>
                <span class="text-h5">{{ trend?.totals?.arrived ?? 0 }}</span>
              </div>
              <div>
                <div class="text-disabled text-sm">
                  Decided
                </div>
                <span class="text-h5">{{ trend?.totals?.decided ?? 0 }}</span>
              </div>
            </div>

            <!--
              Client-only: this console is prerendered to static HTML, so a
              chart cannot be drawn at build time.
            -->
            <VueApexCharts
              type="area"
              height="280"
              :options="chartOptions"
              :series="chartSeries"
            />

            <!--
              Said plainly rather than left for the reader to misread: with a
              handful of cases in one week this line is flat because the
              business is young, not because something is broken.
            -->
            <!--
              An empty window is a fair answer and a useless one on its own, so
              it says how far back the last activity was and offers the window
              that would include it.
            -->
            <VAlert
              v-if="trendIsEmpty && windowThatWouldShowIt"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-0"
            >
              <span class="text-high-emphasis">
                Nothing arrived or was decided in the last {{ range }} days. The most recent was
                {{ daysSinceLatest }} {{ daysSinceLatest === 1 ? 'day' : 'days' }} ago.
              </span>
              <template #append>
                <VBtn
                  size="small"
                  variant="tonal"
                  @click="range = windowThatWouldShowIt.days"
                >
                  Show {{ windowThatWouldShowIt.label }}
                </VBtn>
              </template>
            </VAlert>

            <p
              v-else-if="trendIsEmpty"
              class="text-body-2 text-medium-emphasis mb-0"
            >
              Nothing has arrived or been decided yet. This reads the real queue, so it is
              empty rather than illustrative.
            </p>

            <p
              v-else-if="trendIsFlat"
              class="text-body-2 text-medium-emphasis mb-0"
            >
              There is almost nothing to plot yet — this fills in as applications arrive and
              get decided. It reads the real queue, so it is empty rather than illustrative.
            </p>
          </template>
        </VCardText>
      </VCard>

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
