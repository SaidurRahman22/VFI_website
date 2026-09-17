<script setup>
/*
  The application queue: the screen VFI staff actually live in.

  Three deliberate choices, all reactions to how the old panel behaved:

  1. "Waiting on us" is the default view, not "everything". The job is the cases
     sitting with VFI; the full list is one click away.

  2. The Move menu is built from next_statuses, which the SERVER derives from
     ApplicationReviewService's transition map. The panel never offers a move
     the state machine would refuse - the old panel listed every status and let
     the user discover the rejection.

  3. A refused move, a missing reason, or a failed request is shown as the
     message the server sent. Silence is what made the old buttons feel dead.

  Detail opens in a drawer against ?open=<id>, so a case is linkable and the
  back button behaves - without needing a dynamic route, which would have
  required an nginx SPA fallback to survive a hard refresh.
*/
definePageMeta({ title: 'Applications' })

const api = useVfiApi()
const route = useRoute()
const router = useRouter()
const { can, load: loadUser } = useVfiUser()

const loading = ref(true)
const error = ref(null)
const rows = ref([])
const meta = ref({ counts: {}, total: 0, waiting: 0 })

const search = ref('')
const statusFilter = ref(null)
const waitingOnly = ref(true)

/* ---- detail drawer ---- */
const open = ref(false)
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref(null)
const actionBusy = ref(false)
const actionError = ref(null)

const moveTo = ref(null)
const moveReason = ref('')
const noteBody = ref('')

const STATUS_COLOR = {
  submitted: 'primary',
  review: 'info',
  offer: 'success',
  conditional: 'warning',
  pending_from_partner: 'warning',
  payment: 'warning',
  visa_received: 'success',
  visa_rejected: 'error',
  deferral: 'warning',
  non_enrolment: 'error',
}

function colorFor(status) {
  return STATUS_COLOR[status] || 'secondary'
}

async function loadList() {
  loading.value = true
  error.value = null
  try {
    const params = new URLSearchParams({ per_page: '50' })

    if (waitingOnly.value)
      params.set('waiting', '1')
    if (statusFilter.value)
      params.set('status', statusFilter.value)
    if (search.value.trim())
      params.set('q', search.value.trim())

    const res = await api.get(`/api/admin/applications?${params}`)

    rows.value = res.data
    meta.value = res.meta
  }
  catch (e) {
    error.value = e.message || 'Could not load the queue.'
  }
  finally {
    loading.value = false
  }
}

async function openCase(id) {
  open.value = true
  detailLoading.value = true
  detailError.value = null
  actionError.value = null
  moveTo.value = null
  moveReason.value = ''
  noteBody.value = ''
  try {
    detail.value = await api.get(`/api/admin/applications/${id}`)
  }
  catch (e) {
    detailError.value = e.message || 'Could not load this application.'
  }
  finally {
    detailLoading.value = false
  }

  // Linkable without a dynamic route.
  router.replace({ query: { ...route.query, open: String(id) } })
}

function closeCase() {
  open.value = false
  detail.value = null

  const q = { ...route.query }

  delete q.open
  router.replace({ query: q })
}

/* A negative outcome needs a reason; the server enforces it, the form says so. */
const NEEDS_REASON = ['visa_rejected', 'non_enrolment', 'deferral', 'pending_from_partner']
const reasonRequired = computed(() => NEEDS_REASON.includes(moveTo.value))

async function submitMove() {
  if (!moveTo.value)
    return

  actionBusy.value = true
  actionError.value = null
  try {
    await api.post(`/api/admin/applications/${detail.value.application.id}/transition`, {
      to: moveTo.value,
      reason: moveReason.value.trim() || null,
    })

    // Reload both: the case's own history and its position in the queue.
    await openCase(detail.value.application.id)
    await loadList()
  }
  catch (e) {
    actionError.value = e.message
  }
  finally {
    actionBusy.value = false
  }
}

async function submitNote() {
  if (noteBody.value.trim().length < 2)
    return

  actionBusy.value = true
  actionError.value = null
  try {
    await api.post(`/api/admin/applications/${detail.value.application.id}/notes`, {
      body: noteBody.value.trim(),
    })
    noteBody.value = ''
    await openCase(detail.value.application.id)
  }
  catch (e) {
    actionError.value = e.message
  }
  finally {
    actionBusy.value = false
  }
}

let searchTimer = null

watch(search, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(loadList, 300)
})
watch([statusFilter, waitingOnly], loadList)

onMounted(async () => {
  /*
    AWAIT the user first - see the note in pages/index.vue. Checking can() before
    /api/admin/me has answered made this screen render "no access" while the API
    was returning all seven applications.
  */
  await loadUser()

  if (!can('applications.process')) {
    loading.value = false

    return
  }
  await loadList()

  // Deep link straight into a case.
  if (route.query.open)
    openCase(Number(route.query.open))
})
</script>

<template>
  <div>
    <VAlert
      v-if="!can('applications.process')"
      type="info"
      variant="tonal"
    >
      Your role does not include processing applications.
    </VAlert>

    <template v-else>
      <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
        <div>
          <h4 class="text-h4 mb-1">
            Applications
          </h4>
          <span class="text-body-1 text-disabled">
            {{ meta.waiting }} waiting on VFI &middot; {{ meta.total }} shown
          </span>
        </div>
      </div>

      <VCard class="mb-4">
        <VCardText class="d-flex flex-wrap align-center gap-4">
          <VTextField
            v-model="search"
            placeholder="Search student name, email or reference"
            prepend-inner-icon="ri-search-line"
            clearable
            hide-details
            density="compact"
            style="max-inline-size: 22rem;"
          />
          <VSelect
            v-model="statusFilter"
            :items="Object.keys(meta.counts || {}).map(k => ({ title: `${k.replace(/_/g, ' ')} (${meta.counts[k]})`, value: k }))"
            placeholder="Any status"
            clearable
            hide-details
            density="compact"
            style="max-inline-size: 16rem;"
          />
          <VSwitch
            v-model="waitingOnly"
            label="Only waiting on us"
            hide-details
            density="compact"
          />
          <VSpacer />
          <VBtn
            variant="tonal"
            prepend-icon="ri-refresh-line"
            :loading="loading"
            @click="loadList"
          >
            Refresh
          </VBtn>
        </VCardText>
      </VCard>

      <VAlert
        v-if="error"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ error }}
      </VAlert>

      <VCard>
        <VProgressLinear
          v-if="loading"
          indeterminate
        />
        <VTable v-if="rows.length">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Student</th>
              <th>Agency</th>
              <th>Status</th>
              <th>Intake</th>
              <th>Submitted</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="cursor-pointer"
              @click="openCase(row.id)"
            >
              <td class="text-no-wrap font-weight-medium">
                {{ row.ref }}
              </td>
              <td>
                <div>{{ row.student.name }}</div>
                <div class="text-disabled text-xs">
                  {{ row.student.email }}
                </div>
              </td>
              <td>{{ row.agency_name }}</td>
              <td>
                <VChip
                  :color="colorFor(row.status)"
                  size="small"
                  variant="tonal"
                >
                  {{ row.status_label }}
                </VChip>
              </td>
              <td class="text-no-wrap">
                {{ row.intake || '—' }}
              </td>
              <td class="text-no-wrap">
                {{ row.submitted_at ? row.submitted_at.slice(0, 10) : '—' }}
              </td>
              <td class="text-end">
                <VBtn
                  size="small"
                  variant="text"
                  @click.stop="openCase(row.id)"
                >
                  Open
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardText
          v-else-if="!loading"
          class="text-disabled"
        >
          <template v-if="waitingOnly">
            Nothing is waiting on VFI right now. Turn off &ldquo;Only waiting on us&rdquo; to see every application.
          </template>
          <template v-else>
            No applications match these filters.
          </template>
        </VCardText>
      </VCard>
    </template>

    <!--
      👉 the case

      A VDialog, not a VNavigationDrawer. The drawer measured x:1500 in a
      1500px viewport with transform translateX(560px) and never received
      v-navigation-drawer--active: it positions itself through Vuetify's layout
      system, and this template's shell is a custom VerticalNavLayout, so the
      open position was never applied. The overlay dimmed the page while the
      panel itself stayed off-screen - a click that looked like it worked and
      showed nothing. VDialog teleports to body and does not need that
      registration.
    -->
    <VDialog
      v-model="open"
      max-width="620"
      scrollable
    >
      <VCard>
        <div
          v-if="detailLoading"
          class="pa-6"
        >
          <VProgressLinear indeterminate />
        </div>

        <VAlert
          v-else-if="detailError"
          type="error"
          variant="tonal"
          class="ma-4"
        >
          {{ detailError }}
        </VAlert>

        <template v-else-if="detail">
        <div class="d-flex align-center justify-space-between pa-4">
          <div>
            <div class="text-h6">
              {{ detail.application.ref }}
            </div>
            <div class="text-disabled text-sm">
              {{ detail.application.student.name }} &middot; {{ detail.application.agency?.name }}
            </div>
          </div>
          <IconBtn @click="closeCase">
            <VIcon icon="ri-close-line" />
          </IconBtn>
        </div>
        <VDivider />

        <div class="pa-4">
          <VChip
            :color="colorFor(detail.application.status)"
            variant="tonal"
            class="mb-4"
          >
            {{ detail.application.status_label }}
          </VChip>

          <!-- programme -->
          <div
            v-if="detail.application.program"
            class="mb-4"
          >
            <div class="text-disabled text-sm">
              Programme
            </div>
            <div class="font-weight-medium">
              {{ detail.application.program.title }}
            </div>
            <div class="text-disabled text-sm">
              {{ detail.application.program.university }} &middot; {{ detail.application.program.country }}
            </div>
          </div>

          <!-- readiness: the reason a case can or cannot be processed -->
          <VAlert
            v-if="detail.readiness"
            :type="detail.readiness.ready ? 'success' : 'warning'"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            <template v-if="detail.readiness.ready">
              All {{ detail.readiness.required.length }} required documents are in.
            </template>
            <template v-else>
              Waiting on {{ detail.readiness.missing.length }} document(s):
              {{ detail.readiness.missing.join(', ') || '—' }}
              <template v-if="detail.readiness.rejected.length">
                <br>Rejected: {{ detail.readiness.rejected.join(', ') }}
              </template>
            </template>
          </VAlert>

          <VAlert
            v-if="actionError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ actionError }}
          </VAlert>

          <!-- move: only where the state machine allows -->
          <div class="mb-6">
            <div class="text-sm font-weight-medium mb-2">
              Move this application
            </div>
            <template v-if="detail.next_statuses.length">
              <VSelect
                v-model="moveTo"
                :items="detail.next_statuses.map(s => ({ title: s.label, value: s.value }))"
                placeholder="Choose the next status"
                density="compact"
                hide-details
                class="mb-2"
              />
              <VTextarea
                v-if="moveTo"
                v-model="moveReason"
                :label="reasonRequired ? 'Reason (required)' : 'Reason (optional)'"
                rows="2"
                density="compact"
                hide-details
                class="mb-2"
              />
              <VBtn
                :disabled="!moveTo || (reasonRequired && !moveReason.trim())"
                :loading="actionBusy"
                size="small"
                @click="submitMove"
              >
                Move
              </VBtn>
            </template>
            <div
              v-else
              class="text-disabled text-sm"
            >
              This application has reached a final status.
            </div>
          </div>

          <!-- notes: staff-internal -->
          <div class="mb-2 d-flex align-center gap-2">
            <span class="text-sm font-weight-medium">Internal notes</span>
            <VChip size="x-small" variant="tonal">
              staff only
            </VChip>
          </div>
          <VTextarea
            v-model="noteBody"
            placeholder="Visible to VFI staff only — never to the agency or student"
            rows="2"
            density="compact"
            hide-details
            class="mb-2"
          />
          <VBtn
            :disabled="noteBody.trim().length < 2"
            :loading="actionBusy"
            size="small"
            variant="tonal"
            class="mb-4"
            @click="submitNote"
          >
            Add note
          </VBtn>

          <VList
            v-if="detail.notes.length"
            density="compact"
            class="mb-4"
          >
            <VListItem
              v-for="n in detail.notes"
              :key="n.id"
            >
              <VListItemTitle class="text-wrap text-sm">
                {{ n.body }}
              </VListItemTitle>
              <VListItemSubtitle class="text-xs">
                {{ n.author || 'Unknown' }} &middot; {{ n.created_at ? n.created_at.slice(0, 10) : '' }}
              </VListItemSubtitle>
            </VListItem>
          </VList>

          <!-- history -->
          <div class="text-sm font-weight-medium mb-2">
            History
          </div>
          <VTimeline
            v-if="detail.events.length"
            density="compact"
            side="end"
            truncate-line="both"
size="x-small"
          >
            <VTimelineItem
              v-for="(e, i) in detail.events"
              :key="i"
              size="x-small"
              :dot-color="colorFor(e.to)"
            >
              <div class="text-sm">
                <span v-if="e.from">{{ e.from.replace(/_/g, ' ') }} &rarr; </span>
                <strong>{{ (e.to || '').replace(/_/g, ' ') }}</strong>
              </div>
              <div class="text-disabled text-xs">
                {{ e.occurred_at ? e.occurred_at.slice(0, 10) : '' }}
                <span v-if="e.actor_type"> &middot; by {{ e.actor_type }}</span>
              </div>
              <div
                v-if="e.note"
                class="text-xs mt-1"
              >
                {{ e.note }}
              </div>
            </VTimelineItem>
          </VTimeline>
          <div
            v-else
            class="text-disabled text-sm"
          >
            No status changes recorded yet.
          </div>
        </div>
        </template>
      </VCard>
    </VDialog>
  </div>
</template>
