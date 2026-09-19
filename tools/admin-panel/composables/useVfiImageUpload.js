/*
  Putting a picture on a row, and being honest about it when that fails.

  ContentCollections.vue held the only copy of this: FormData to
  POST /api/admin/media, and the id the server hands back written into the
  model. The country/region editor needs the same three steps for its new
  per-row image fields, and a second copy is how js/admin.js ended up carrying
  three separate media-slot lists that had already drifted apart about a card's
  aspect ratio. So it lives here once and both screens call it.

  Two things differ from the dialog it was lifted out of, and both exist
  because the grouped editor has many rows on screen at the same time rather
  than one form in a dialog:

    * the in-flight token is supplied by the caller, so a single row can show a
      spinner instead of the whole screen doing so;
    * a failure is recorded against that token, so the server's sentence lands
      under the input that produced it. A banner at the top of a page listing
      forty universities is a message nobody reads, and an upload that quietly
      leaves the avatar as it was looks exactly like one that worked.
*/

/*
  What an image id is allowed to look like, mirrored from the server's
  App\Support\ImageIdGuard.

  The server is the authority: it refuses the save on its own and nothing here
  can let a bad id through. This copy decides only what to DRAW - whether to
  paint a preview and whether to warn - which is why it is deliberately the
  more permissive of the two. A browser copy stricter than the server would
  mark a perfectly good id as broken and stop someone working; a looser one
  only misses a warning that the save then gives anyway.

  It is here at all because the stored-id box is free text. Without it, an id
  pasted as https://evil.example/beacon.png would be fetched by the editor's
  own browser the moment it was typed, from a screen that is signed in.
*/
const MANAGED_UPLOAD = /^\/storage\/media\/[0-9a-f]{64}\.jpg$/
const BUNDLED_ASSET = /^assets\/img\/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif)$/i

export function useVfiImageUpload() {
  /* The token of the upload in flight, or null. One at a time is not a
     limitation worth lifting: the screens that use this disable Save while it
     is set, and two concurrent uploads would mean two moments at which a save
     is unsafe rather than one. */
  const uploading = ref(null)

  /* token -> what the server said about that upload. Kept per token because
     the only useful place for the message is beside the input that failed. */
  const uploadErrors = ref({})

  function isKnownImageId(id) {
    if (!id || typeof id !== 'string')
      return false

    // Rejected before the patterns rather than trusted to them: a bundled name
    // is matched case-insensitively, and `..` inside one is a traversal
    // attempt, not a filename.
    if (id.includes('..'))
      return false

    return MANAGED_UPLOAD.test(id) || BUNDLED_ASSET.test(id)
  }

  /*
    The <img> src for a stored id, or null when there is nothing safe to show.

    Both accepted shapes are served from the site root, so the only work is
    making the bundled one absolute. Anything else returns null so the preview
    falls back to a placeholder instead of the console issuing a request to
    whatever was typed.
  */
  function imageSrc(id) {
    if (!isKnownImageId(id))
      return null

    return id.startsWith('/') ? id : `/${id}`
  }

  /** True for a value that is present but that the server will refuse on save. */
  function isUnusableImageId(id) {
    return Boolean(id) && !isKnownImageId(id)
  }

  /*
    Laravel answers a rejected upload two different ways: a validation failure
    puts the specific sentence under `errors.file` ("The file must not be
    greater than 6144 kilobytes."), while ImageService's own refusal arrives as
    `message` alone. Reading the field error first means the editor is told
    which limit they actually hit instead of a generic line - or, when the
    response carried no JSON at all, of "Request failed (422)".
  */
  function messageFrom(error) {
    const field = error?.errors?.file

    if (Array.isArray(field) && field.length)
      return field[0]

    return error?.message || 'That image could not be uploaded.'
  }

  /*
    Upload one file and write the resulting id onto `target[fieldKey]`.

    `target` is the object being edited - a repeater row here, a form object in
    the collections dialog - and `token` is whatever string the caller uses to
    identify this control on screen.
  */
  async function uploadImage(target, fieldKey, token, files) {
    const file = Array.isArray(files) ? files[0] : files

    if (!file)
      return false

    uploading.value = token
    delete uploadErrors.value[token]

    try {
      const body = new FormData()

      body.append('file', file)

      const res = await useVfiApi().post('/api/admin/media', body)

      // The server re-encodes, downscales, strips EXIF and content-hashes the
      // bytes, then hands back the id. The browser never decides the filename,
      // and never stores the one the file arrived with.
      target[fieldKey] = res.imgId

      return true
    }
    catch (e) {
      // Recorded, never swallowed: the row keeps whatever picture it had, and
      // the caller renders this against the same token so the difference
      // between "nothing happened" and "the server said no" is visible.
      uploadErrors.value[token] = messageFrom(e)

      return false
    }
    finally {
      uploading.value = null
    }
  }

  /** Drop a stale message, e.g. when the row's image is cleared by hand. */
  function clearUploadError(token) {
    delete uploadErrors.value[token]
  }

  return {
    uploading,
    uploadErrors,
    imageSrc,
    isKnownImageId,
    isUnusableImageId,
    uploadImage,
    clearUploadError,
  }
}
