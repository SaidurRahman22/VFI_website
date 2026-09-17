/*
  Who is signed in, and what they are allowed to see.

  Loaded once per page load from /api/admin/me and shared through useState, so
  the layout, the navigation and any screen that needs it all read the same
  answer rather than each fetching it.

  `abilities` is resolved SERVER-side by App\Support\StaffAbilities. The panel
  deliberately does not carry its own copy of the role-to-ability map: two
  copies of a permission rule drift, and the browser copy is the one that would
  be wrong. Everything here decides only what to DRAW - every screen and every
  endpoint still refuses the request on its own.
*/
export function useVfiUser() {
  const user = useState('vfi-user', () => null)
  const loading = useState('vfi-user-loading', () => false)
  const failed = useState('vfi-user-failed', () => false)

  async function load(force = false) {
    if (user.value && !force)
      return user.value
    if (loading.value)
      return null

    loading.value = true
    failed.value = false

    try {
      user.value = await useVfiApi().get('/api/admin/me')
    }
    catch {
      // A 401 has already redirected to the login page inside useVfiApi, so
      // reaching here means the API answered but not usefully. Flagged rather
      // than thrown: the shell should say so, not white-screen.
      failed.value = true
    }
    finally {
      loading.value = false
    }

    return user.value
  }

  /** Deny by default: an unknown ability, or no loaded user, is false. */
  function can(ability) {
    return Boolean(user.value?.abilities?.[ability])
  }

  const isSuperAdmin = computed(() => Boolean(user.value?.is_superadmin))

  return { user, loading, failed, load, can, isSuperAdmin }
}
