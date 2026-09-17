/*
  The one way this panel talks to Laravel.

  Same-origin by design: the panel is served from /admin-panel/ on the same host
  as the API, so the existing session cookie authenticates every call and there
  is no token to store, leak, or refresh. That is also why the login page is left
  exactly as it was - it already establishes this session.

  Three things every call needs and would otherwise be forgotten one at a time:
    credentials  - without it the browser sends no cookie and everything 401s
    X-XSRF-TOKEN - Laravel rejects any unsafe method without it
    401 handling - an expired session must land on the login page, not render a
                   screen full of empty tables that looks like missing data
*/

const CSRF_COOKIE = 'XSRF-TOKEN'
const LOGIN_PAGE = '/admin-login.html'

function readCookie(name) {
  const hit = document.cookie.split('; ').find(row => row.startsWith(`${name}=`))

  // Laravel URL-encodes the cookie and the header must be the decoded value.
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null
}

/*
  Sanctum sets the CSRF cookie on request. Only fetched when it is missing, so a
  normal page of reads costs one round trip rather than one per write.
*/
async function ensureCsrf() {
  if (readCookie(CSRF_COOKIE))
    return readCookie(CSRF_COOKIE)

  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })

  return readCookie(CSRF_COOKIE)
}

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Request failed (${status})`)
    this.status = status
    this.body = body
    // Laravel's validation shape, surfaced so forms can show per-field errors
    // instead of one opaque banner.
    this.errors = body?.errors || null
  }
}

export function useVfiApi() {
  async function request(method, path, payload) {
    const unsafe = method !== 'GET'
    const headers = { Accept: 'application/json' }

    if (unsafe) {
      const token = await ensureCsrf()

      if (token)
        headers['X-XSRF-TOKEN'] = token
    }

    const init = { method, credentials: 'same-origin', headers }

    if (payload instanceof FormData) {
      // Deliberately no Content-Type: the browser has to set the multipart
      // boundary itself, and setting it by hand silently breaks the upload.
      init.body = payload
    }
    else if (payload !== undefined) {
      headers['Content-Type'] = 'application/json'
      init.body = JSON.stringify(payload)
    }

    const res = await fetch(path, init)

    if (res.status === 401) {
      // Send them back where they came from, so a session that expires
      // mid-task does not cost them their place.
      const back = encodeURIComponent(window.location.pathname + window.location.search)

      window.location.assign(`${LOGIN_PAGE}?next=${back}`)
      throw new ApiError(401, { message: 'Session expired.' })
    }

    if (res.status === 204)
      return null

    let body = null

    try {
      body = await res.json()
    }
    catch {
      body = null
    }

    if (!res.ok)
      throw new ApiError(res.status, body)

    return body
  }

  return {
    get: path => request('GET', path),
    post: (path, payload) => request('POST', path, payload),
    put: (path, payload) => request('PUT', path, payload),
    patch: (path, payload) => request('PATCH', path, payload),
    del: path => request('DELETE', path),
  }
}
