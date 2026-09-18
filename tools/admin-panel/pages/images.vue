<script setup>
/*
  The pictures that are built into a page's layout rather than kept in a list.

  This is the screen that finally makes them real. The legacy editor for them
  (js/admin.js, SLOTS and P_SLOTS) wrote the chosen image id into the EDITOR'S
  OWN localStorage through js/store.js, so a photo set there appeared in that
  one browser and nowhere else - no image anyone has ever set through it has
  reached a visitor. This talks to /api/admin/media/slots and
  /api/admin/media/slot/<key>, which keep the ids in site_content and audit
  every change.

  Six deliberate choices:

  1. THE SLOT LIST COMES FROM THE SERVER. AdminMediaController::SLOTS declares
     the key, the label, the sentence saying where the picture comes out and the
     recommended size, the same way the content and singleton schemas are
     declared. The page this replaces held three copies of that list, one per
     tab, and they had already drifted - it calls a Multi Country card
     "portrait 7:6" for a box that is wider than it is tall. The one thing this
     file needs to know about a slot by name is nothing at all, because
     that is a gap in the server's schema rather than a choice.

  2. EMPTY AND BROKEN ARE DRAWN DIFFERENTLY, because they are different problems
     with different fixes. An empty slot is normal: the page falls back to
     whatever its own markup carries. A slot holding an id the browser cannot
     load is a fault a visitor sees too - uploads are served from /storage/media/
     through a symlink that was missing until recently, so every upload reported
     success and then 404'd. A blank square is the only symptom that has, and it
     must not be mistaken for "not set yet".

  3. THE STORED ID IS ON SCREEN beside each picture, not hidden behind an edit
     form. It is what the public page loads, so when a picture looks wrong it is
     the line that explains why. It is shown rather than typed: the endpoint
     accepts any string up to 255 characters, and the only ids worth having here
     are the ones the upload pipeline hands back.

  4. CLEARING ASKS FIRST, and says what it really does. ImageService::setMedia
     reference-counts the id it drops and deletes the file when nothing else
     points at it, so clearing a slot can remove the picture from the server for
     good. That is not something to find out afterwards.

  5. "LIVE STRAIGHT AWAY" WOULD BE UNTRUE, TWICE OVER, so this screen does not
     say it. The write lands in site_content at once, but the public pages read
     the media map out of /api/content/bootstrap.js, which
     ContentBundleController serves with `Cache-Control: public, max-age=60`.
     And js/store.js resolves content as `read() || bootstrapData() || SEED` -
     the editor's OWN localStorage wins over the server's bundle - so a browser
     that was ever used with the legacy /admin.html editors holds that key and
     goes on painting the old picture for as long as it is there. The minute is
     named and a private window is suggested, because that is the one check that
     separates "not saved" from "this browser is holding its own stale copy".

  6. TWO OF THE TEN SLOTS HAVE NO PHOTOGRAPH BEHIND THEM, and saying they do was
     the same false reassurance. The server says which per slot.

  Not titled "Home page images", which is what the legacy tab was called: two of
  the slots the server sends are on the public partner page, not the home page.
  Each card says which page its picture appears on.
*/
definePageMeta({ title: 'Page images' })

const api = useVfiApi()
const { can, load: loadUser } = useVfiUser()

/*
  Every slot's wording - its label, where it comes out, the size it wants, and
  what a visitor sees when it is empty - arrives from the server in
  AdminMediaController::SLOTS. This file knows no slot by name, which is the
  point: the panel this replaces kept its own field table in js/admin.js, and
  that second copy is how its photos form came to offer a field with no column
  behind it.

  `fallback` is the one that needs explaining. null means the page has a
  photograph of its own built in, so an empty slot still shows a photograph. A
  sentence means the space shows a DRAWING instead - the two partner-page slots
  are declared with <img ... hidden> and js/render.js never unhides it, so what
  a visitor gets there is the CSS mock-up beside it - and a picture set here
  takes the drawing's place.
*/
function fallbackOf(slot) {
  return slot && slot.fallback ? slot.fallback : null
}

const booting = ref(true)
const allowed = ref(false)
const loadError = ref(null)
const note = ref(null)

const slots = ref([])

/*
  The version of the whole media row, not of one slot: all ten live in one
  site_content row, so a save carries the version it read and the server refuses
  a stale one with a 409 rather than quietly overwriting whoever saved first.
  Kept in step on every read and after every successful write.
*/
const version = ref(0)

/* The one slot being uploaded to or saved. One at a time - see the file input. */
const busy = ref(null)

/* slot key -> { ok, text }: what happened last, in words, beside the thing it
   happened to. The old buttons said nothing at all and felt dead for it. */
const outcome = ref({})

/* slot key -> 'empty' | 'loading' | 'ok' | 'error', from the browser actually
   trying to fetch the image. Nothing else can tell us a stored id is dead. */
const shown = ref({})

/*
  slot key -> how many times this picture has been asked for again.

  ImageService::store content-hashes the bytes, so re-uploading the same file
  after an operator has fixed whatever was 404ing hands back the id the slot
  already holds. Nothing about the slot changes, and the card would keep the
  'error' it is in and go on insisting the picture will not load. The count goes
  into the <VImg> key AND into the query string, because a fresh element is not
  enough on its own: a browser is entitled to answer it out of the failed fetch
  it already has.
*/
const probe = ref({})

const confirming = ref(null)
const clearing = ref(false)

const emptySlots = computed(() => slots.value.filter(s => !s.imgId))
const emptyCount = computed(() => emptySlots.value.length)

/* Of those, the ones where empty means a drawing rather than a photograph. */
const emptyMockups = computed(() => emptySlots.value.filter(s => fallbackOf(s)))

const brokenCount = computed(() =>
  slots.value.filter(s => s.imgId && shown.value[s.key] === 'error').length)

/*
  img_id is a path-style id: '/storage/media/<hash>.jpg' for a managed upload,
  or a bundled 'assets/img/x.jpg'. Both are served from the site root, so the
  work is making the relative one absolute - and, on a retry, defeating the
  browser's own memory of a fetch that failed. Same helper as the collections
  screen, which has no retry to make.
*/
function imageSrc(id, attempt) {
  if (!id)
    return null

  const url = id.startsWith('/') || id.startsWith('http') ? id : `/${id}`

  // A managed id is content-addressed and immutable, so `retry` can only ever
  // be a cache-buster. Left off the first attempt so that the address this
  // console asks for is byte-for-byte the one a visitor's browser asks for.
  return attempt ? `${url}${url.includes('?') ? '&' : '?'}retry=${attempt}` : url
}

/* A new id starts as 'loading' again, or a slot that failed once keeps
   reporting a failure after it has been replaced with something that works. */
function watchImage(key, id) {
  shown.value[key] = id ? 'loading' : 'empty'
}

/* The same, for an id that has NOT changed - see `probe`. */
function recheckImage(key, id) {
  probe.value[key] = (probe.value[key] || 0) + 1
  watchImage(key, id)
}

function say(key, ok, text) {
  outcome.value[key] = { ok, text }
}

/*
  Both write endpoints answer with the whole media map, so the screen shows what
  the database now holds rather than what it hoped it would. Only the declared
  slots are read out of it: the same row is where the legacy editor also put its
  per-country and per-region keys, and a screen that does not know about those
  has no business touching them.
*/
function applyMedia(media, justUploaded = null) {
  for (const s of slots.value) {
    const stored = media?.[s.key]
    const next = typeof stored === 'string' && stored !== '' ? stored : null

    if (next !== s.imgId) {
      s.imgId = next
      watchImage(s.key, next)
    }
    else if (s.key === justUploaded) {
      // The id came back unchanged after an upload, which is what a re-upload of
      // the identical file does. The slot is the same but the question "does
      // this load?" is open again, and it is the whole point of re-uploading.
      recheckImage(s.key, next)
    }
  }
}

async function load() {
  loadError.value = null
  try {
    const res = await api.get('/api/admin/media/slots')

    slots.value = res.slots || []
    version.value = res.version ?? 0
    shown.value = {}
    for (const s of slots.value)
      watchImage(s.key, s.imgId)
  }
  catch (e) {
    loadError.value = e.message || 'Could not load the picture list.'
  }
}

async function reloadFromServer() {
  note.value = null
  outcome.value = {}
  await load()
  if (!loadError.value)
    note.value = 'Reloaded. This is what is stored now.'
}

/*
  Two requests, in order: the file becomes a managed image, then the slot is
  pointed at it. They are reported separately because a failure between them
  leaves the upload on the server with no page using it, and the section still
  showing what it showed before - worth saying plainly rather than reporting as
  one flat "save failed".
*/
async function choose(slot, files) {
  const file = Array.isArray(files) ? files[0] : files

  if (!file)
    return

  busy.value = slot.key
  outcome.value[slot.key] = null

  let uploaded = null

  try {
    const body = new FormData()

    body.append('file', file)

    // No max_width: the server's default of 1400px is wider than the largest
    // box any of these slots renders into, and asking for less would throw away
    // detail on a high-density screen. The server re-encodes and content-hashes
    // it; the browser never decides the filename.
    uploaded = (await api.post('/api/admin/media', body)).imgId

    // The key came from the server's own allow-list, but it is being put into a
    // URL path, so it is encoded on the way in regardless.
    const res = await api.put(
      `/api/admin/media/slot/${encodeURIComponent(slot.key)}`,
      { imgId: uploaded, version: version.value },
    )

    version.value = res.version ?? version.value + 1
    applyMedia(res.media, slot.key)
    say(slot.key, true, `Saved. ${slot.label} now uses ${uploaded}. `
      + 'The public pages pick it up within a minute — they hold the picture list for 60 seconds. '
      + 'If the old photo is still there after that, open the page in a private window: a browser '
      + 'that was ever used with the old /admin.html editors keeps its own copy of these settings '
      + 'and prefers it over the server\'s.')
  }
  catch (e) {
    if (e.status === 409) {
      /*
        Someone else changed a picture while this page was open. Their save
        stands; this one was refused rather than applied on top of it. Re-read
        so the next attempt carries a version the server will accept, and say
        which of the two things happened - the upload is on the server either
        way.
      */
      await load()
      say(slot.key, false, `${e.message} The picture uploaded as ${uploaded}, `
        + 'so choosing it again will not cost you another upload.')
    }
    else if (uploaded) {
      say(slot.key, false, `The picture uploaded as ${uploaded}, but the slot was not changed: ${e.message} `
        + 'Nothing on the site has changed - try again.')
    }
    else {
      say(slot.key, false, e.message || 'That picture could not be uploaded.')
    }
  }
  finally {
    busy.value = null
  }
}

async function clearSlot() {
  const slot = confirming.value
  const was = slot.imgId

  clearing.value = true
  outcome.value[slot.key] = null
  try {
    const res = await api.put(
      `/api/admin/media/slot/${encodeURIComponent(slot.key)}`,
      { imgId: null, version: version.value },
    )

    version.value = res.version ?? version.value + 1
    applyMedia(res.media)
    confirming.value = null
    say(slot.key, true, `Cleared. ${slot.label} is back to `
      + `${fallbackOf(slot) || 'the photograph built into the page'}, `
      + `within a minute. It was ${was}.`)
  }
  catch (e) {
    confirming.value = null
    if (e.status === 409) {
      await load()
      say(slot.key, false, `${e.message} Nothing was cleared.`)
    }
    else {
      say(slot.key, false, e.message || 'That slot could not be cleared.')
    }
  }
  finally {
    clearing.value = false
  }
}

onMounted(async () => {
  // AWAIT the user before deciding what to draw - checking can() before
  // /api/admin/me has answered is what made the applications screen claim no
  // access while the API was returning every row.
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

    <template v-else>
      <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
        <div>
          <h4 class="text-h4 mb-1">
            Page images
          </h4>
          <p class="text-body-2 mb-0 text-medium-emphasis">
            The photographs set into the layout of the home page and the public partner page.
            Each has one fixed place, named under the picture. A new upload is stored the moment
            it is saved and reaches the public pages within a minute, which is how long they hold
            on to the picture list. Every upload is scaled to at most 1400px wide and saved again
            as a JPEG with its camera data stripped, so a transparent background comes back
            white.
          </p>
        </div>

        <VBtn
          variant="text"
          prepend-icon="ri-refresh-line"
          :disabled="!!busy || booting"
          @click="reloadFromServer"
        >
          Reload
        </VBtn>
      </div>

      <VProgressLinear
        v-if="booting"
        indeterminate
        class="mb-4"
      />

      <VAlert
        v-if="loadError"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ loadError }}
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

      <!--
        A stored id the browser cannot fetch is a fault on the live site, not a
        cosmetic one: this console loads the picture from the same address a
        visitor's browser does.
      -->
      <VAlert
        v-if="!loadError && brokenCount"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ brokenCount }} {{ brokenCount === 1 ? 'picture is' : 'pictures are' }} set to something
        this browser cannot load. A visitor's browser fetches them from the same address, so
        {{ brokenCount === 1 ? 'that section is' : 'those sections are' }} broken on the site too.
      </VAlert>

      <!--
        Split, because "the page falls back to its built-in picture" is only true
        of the eight home-page slots. The warning type when
        a partner slot is empty carries its sentence in the theme's ink, not the
        gold: a tonal alert paints its own colour over a 16% tint of itself,
        which measures 1.81:1 and cannot be read.
      -->
      <VAlert
        v-if="!loadError && emptyCount"
        :type="emptyMockups.length ? 'warning' : 'info'"
        variant="tonal"
        class="mb-4"
      >
        <p class="mb-0 text-high-emphasis">
          {{ emptyCount }} of the {{ slots.length }} pictures
          {{ emptyCount === 1 ? 'is' : 'are' }} not set here.
          <template v-if="emptyCount > emptyMockups.length">
            The home-page {{ emptyCount - emptyMockups.length === 1 ? 'one falls' : 'ones fall' }}
            back to the photograph built into the page.
          </template>
          <template v-if="emptyMockups.length">
            <strong>{{ emptyMockups.map(s => s.label).join(' and ') }}</strong>
            {{ emptyMockups.length === 1 ? 'has' : 'have' }} no photograph behind
            {{ emptyMockups.length === 1 ? 'it' : 'them' }}: the partner page draws its own
            mock-up of the product in that space until a picture is set here.
          </template>
        </p>
      </VAlert>

      <VRow>
        <VCol
          v-for="slot in slots"
          :key="slot.key"
          cols="12"
          md="6"
        >
          <VCard>
            <VProgressLinear
              v-if="busy === slot.key"
              indeterminate
            />

            <VCardItem>
              <VCardTitle>{{ slot.label }}</VCardTitle>
              <VCardSubtitle>Best at {{ slot.size }}</VCardSubtitle>
            </VCardItem>

            <div class="px-4">
              <!--
                The retry count is part of the key as well as the address, so a
                re-upload that hands back the same content hash still rebuilds
                the element and asks for the picture again - see `probe`.
              -->
              <VImg
                v-if="slot.imgId && shown[slot.key] !== 'error'"
                :key="`${slot.imgId}#${probe[slot.key] || 0}`"
                :src="imageSrc(slot.imgId, probe[slot.key])"
                :alt="slot.label"
                height="180"
                cover
                class="rounded border"
                @load="shown[slot.key] = 'ok'"
                @error="shown[slot.key] = 'error'"
              />

              <div
                v-else-if="slot.imgId"
                class="rounded border border-dashed d-flex flex-column align-center justify-center text-center pa-4"
                style="height: 180px;"
              >
                <VIcon
                  icon="ri-error-warning-line"
                  size="28"
                  color="error"
                  class="mb-2"
                />
                <div class="text-body-2 text-error">
                  This picture will not load.
                </div>
                <div class="text-caption text-medium-emphasis">
                  The id below is stored, but nothing is served at it. Upload a replacement —
                  or, once the cause is fixed on the server, the same file again: this card
                  looks for the picture afresh every time you upload.
                </div>
              </div>

              <div
                v-else
                class="rounded border border-dashed d-flex flex-column align-center justify-center text-center pa-4"
                style="height: 180px;"
              >
                <VIcon
                  icon="ri-image-add-line"
                  size="28"
                  class="mb-2 text-disabled"
                />
                <div class="text-body-2 text-medium-emphasis">
                  No picture set.
                </div>
                <div class="text-caption text-medium-emphasis">
                  <template v-if="fallbackOf(slot)">
                    There is no photograph behind this one: the page draws
                    {{ fallbackOf(slot) }} in this space instead, and a picture set here
                    replaces it.
                  </template>
                  <template v-else>
                    This section shows the photograph built into the page.
                  </template>
                </div>
              </div>
            </div>

            <VCardText>
              <p class="text-body-2 mb-4">
                {{ slot.where }}
              </p>

              <!--
                The stored id, shown rather than hidden: it is what the public
                page loads, so when a picture looks wrong this is the line that
                says why.
              -->
              <div class="text-caption text-medium-emphasis mb-1">
                Stored image id
              </div>
              <div class="mb-4">
                <code
                  v-if="slot.imgId"
                  class="text-caption"
                >{{ slot.imgId }}</code>
                <span
                  v-else
                  class="text-caption text-medium-emphasis"
                >nothing stored for <code>{{ slot.key }}</code></span>
              </div>

              <!--
                Every input is disabled while any one slot is saving. The media
                row is a single JSON value and a write rewrites all of it, so two
                saves started together would end as the second one winning
                silently.
              -->
              <VFileInput
                :label="slot.imgId ? 'Replace this picture' : 'Choose a picture'"
                accept="image/jpeg,image/png,image/webp,image/gif"
                density="compact"
                prepend-icon=""
                prepend-inner-icon="ri-upload-2-line"
                :loading="busy === slot.key"
                :disabled="!!busy"
                hint="JPEG, PNG, WebP or GIF, up to 6MB."
                persistent-hint
                hide-details="auto"
                @update:model-value="files => choose(slot, Array.isArray(files) ? files : [files])"
              />

              <VBtn
                v-if="slot.imgId"
                variant="text"
                size="small"
                color="error"
                prepend-icon="ri-delete-bin-line"
                class="mt-3"
                :disabled="!!busy"
                @click="confirming = slot"
              >
                Clear this picture
              </VBtn>

              <VAlert
                v-if="outcome[slot.key]"
                :type="outcome[slot.key].ok ? 'success' : 'error'"
                variant="tonal"
                density="compact"
                class="mt-4"
                closable
                @click:close="outcome[slot.key] = null"
              >
                {{ outcome[slot.key].text }}
              </VAlert>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <p
        v-if="!loadError && !booting && !slots.length"
        class="text-body-2 text-medium-emphasis"
      >
        The server sent no picture slots.
      </p>
    </template>

    <!-- 👉 clearing is destructive, for the reason in the header comment -->
    <VDialog
      :model-value="!!confirming"
      max-width="480"
      @update:model-value="v => { if (!v) confirming = null }"
    >
      <VCard v-if="confirming">
        <VCardItem>
          <VCardTitle>Clear the picture in {{ confirming.label }}?</VCardTitle>
        </VCardItem>
        <VCardText>
          <p class="mb-2">
            <template v-if="fallbackOf(confirming)">
              That section goes back to {{ fallbackOf(confirming) }} — there is no
              photograph behind it — within a minute.
            </template>
            <template v-else>
              That section goes back to the photograph built into the page, within a minute.
            </template>
          </p>
          <p class="text-body-2 text-medium-emphasis mb-0">
            <code>{{ confirming.imgId }}</code> is deleted from the server unless another page
            or list still uses it, so this cannot be undone by typing the id back in. Uploading
            a replacement instead leaves nothing missing in the meantime.
          </p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="clearing"
            @click="confirming = null"
          >
            Keep it
          </VBtn>
          <VBtn
            color="error"
            :loading="clearing"
            @click="clearSlot"
          >
            Clear it
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
