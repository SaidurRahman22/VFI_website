<script setup>
/*
  The stock version rendered "John Doe / Admin" with a bundled avatar and four
  dead menu items (Profile, Settings, Pricing, FAQ). This shows who is actually
  signed in, which roles they hold, and gives them a sign-out that works.

  Sign-out mattering is not hypothetical: the audit of the old admin recorded
  "no logout control anywhere" while POST /api/admin/logout sat there with zero
  callers.

  Initials rather than a photo. There is no avatar upload, and shipping a stock
  stranger's face as every staff member's picture is the kind of placeholder
  that makes a tool feel fake.
*/
const { user } = useVfiUser()
const api = useVfiApi()
const signingOut = ref(false)

const initials = computed(() => {
  const name = user.value?.name || user.value?.email || '?'

  return name
    .split(/[\s@._-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(part => part[0].toUpperCase())
    .join('')
})

/* staff_partner_ops -> Staff partner ops */
const roleLabel = computed(() => {
  const roles = user.value?.roles || []

  if (!roles.length)
    return 'No role assigned'

  return roles
    .map(r => r.replace(/_/g, ' '))
    .map(r => r.charAt(0).toUpperCase() + r.slice(1))
    .join(', ')
})

async function signOut() {
  signingOut.value = true
  try {
    await api.post('/api/admin/logout')
  }
  catch {
    // Even a failed call should land them on the login page rather than leave
    // them sitting in a console they believe they have left.
  }
  finally {
    window.location.assign('/admin-login.html')
  }
}
</script>

<template>
  <VAvatar
    class="cursor-pointer"
    color="primary"
    variant="tonal"
  >
    <span class="text-sm font-weight-medium">{{ initials }}</span>

    <VMenu
      activator="parent"
      width="250"
      location="bottom end"
      offset="14px"
    >
      <VList>
        <VListItem>
          <template #prepend>
            <VListItemAction start>
              <VAvatar
                color="primary"
                variant="tonal"
                size="38"
              >
                <span class="text-sm font-weight-medium">{{ initials }}</span>
              </VAvatar>
            </VListItemAction>
          </template>

          <VListItemTitle class="font-weight-semibold">
            {{ user?.name || 'Loading…' }}
          </VListItemTitle>
          <VListItemSubtitle>{{ user?.email }}</VListItemSubtitle>
        </VListItem>

        <VDivider class="my-2" />

        <VListItem>
          <template #prepend>
            <VIcon
              class="me-2"
              icon="ri-shield-user-line"
              size="20"
            />
          </template>
          <VListItemTitle class="text-sm">
            {{ roleLabel }}
          </VListItemTitle>
        </VListItem>

        <VDivider class="my-2" />

        <VListItem
          :disabled="signingOut"
          @click="signOut"
        >
          <template #prepend>
            <VIcon
              class="me-2"
              icon="ri-logout-box-r-line"
              size="20"
            />
          </template>
          <VListItemTitle>{{ signingOut ? 'Signing out…' : 'Sign out' }}</VListItemTitle>
        </VListItem>
      </VList>
    </VMenu>
  </VAvatar>
</template>
