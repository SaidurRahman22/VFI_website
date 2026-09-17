<script setup>
import NavItems from '@/layouts/components/NavItems.vue'
import VerticalNavLayout from '@layouts/components/VerticalNavLayout.vue'

import Footer from '@/layouts/components/Footer.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'

/*
  The shell. Three things were removed from the stock template because they were
  demo furniture, not features:

    - a GitHub link to the template's own repository
    - a Search affordance with no search behind it
    - a notification bell with no notifications behind it

  A control that does nothing is worse than a missing one: the client has spent
  days finding buttons that looked real and were not. They come back when the
  behaviour does.

  The user is loaded once, here, so the navigation and every screen read the same
  answer. A failure to load it is shown rather than swallowed - a blank shell
  with a working sidebar looks like "no data" and is actually "not signed in".
*/
const { user, loading, failed, load } = useVfiUser()

/*
  Bound as a variable, not written as a literal src. The emblem is served by
  nginx from the SITE root (/assets/img/...), shared with the public pages - it
  is not a bundled asset. A literal `src="/assets/..."` makes Vite try to
  resolve it at build time and the build fails.
*/
const logoUrl = '/assets/img/vfi-emblem.png'

onMounted(() => load())
</script>

<template>
  <VerticalNavLayout>
    <template #navbar="{ toggleVerticalOverlayNavActive }">
      <div class="d-flex h-100 align-center">
        <IconBtn
          class="ms-n3 d-lg-none"
          @click="toggleVerticalOverlayNavActive(true)"
        >
          <VIcon icon="ri-menu-line" />
        </IconBtn>

        <VSpacer />

        <!-- Surfaced, not hidden: a session problem must be visible. -->
        <VChip
          v-if="failed"
          color="error"
          size="small"
          variant="tonal"
          class="me-3"
        >
          Could not load your account
        </VChip>
        <VProgressCircular
          v-else-if="loading && !user"
          indeterminate
          size="20"
          width="2"
          class="me-3"
        />

        <NavbarThemeSwitcher class="me-2" />
        <UserProfile />
      </div>
    </template>

    <template #vertical-nav-header="{ toggleIsOverlayNavActive }">
      <NuxtLink
        to="/"
        class="app-logo app-title-wrapper"
      >
        <img
          :src="logoUrl"
          alt="VFI"
          width="34"
          height="34"
          style="object-fit: contain;"
        >
        <h1 class="font-weight-medium leading-normal text-xl">
          VFI Admin
        </h1>
      </NuxtLink>

      <IconBtn
        class="d-block d-lg-none"
        @click="toggleIsOverlayNavActive(false)"
      >
        <VIcon icon="ri-close-line" />
      </IconBtn>
    </template>

    <template #vertical-nav-content>
      <NavItems />
    </template>

    <slot />

    <template #footer>
      <Footer />
    </template>
  </VerticalNavLayout>
</template>

<style lang="scss" scoped>
.app-logo {
  display: flex;
  align-items: center;
  column-gap: 0.75rem;
  text-decoration: none;

  h1 {
    color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  }
}
</style>
