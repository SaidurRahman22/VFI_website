<script setup>
import VerticalNavSectionTitle from '@/@layouts/components/VerticalNavSectionTitle.vue'
import VerticalNavLink from '@layouts/components/VerticalNavLink.vue'

/*
  The whole navigation, deliberately.

  The Filament panel this replaces exposed 21 sidebar entries - one per Eloquent
  resource, because that is what generating an admin from models gives you. Ten
  were separate content collections (Blogs, Events, News, Photos and six
  partner-console ones), which puts a database schema on screen instead of a
  tool someone can work in.

  These are the actual jobs: process an application, look after a student, look
  after an agency, keep the catalogue right, edit the website, and - superadmin
  only - manage who can do what. Everything the old panel could reach is still
  reachable; the ten content collections are TABS inside Website content rather
  than sidebar items.

  Nothing links off-site. The stock template's nav was mostly upsell links to
  the vendor's own demo pages.

  `ability` hides a link the person cannot use. That is a courtesy, not a
  security boundary - the endpoint and the screen both refuse independently.
*/
const { can } = useVfiUser()

/*
  ONLY BUILT SCREENS APPEAR HERE.

  Students, Partner agencies, Universities and Website content are the remaining
  four, and each is added to this list in the same commit that adds its page and
  its API. A sidebar link that leads to an empty screen is the exact thing the
  client has spent days finding and calling decoration - so the sidebar grows as
  the panel does, and never ahead of it.
*/
const sections = [
  {
    heading: 'Daily work',
    items: [
      { title: 'Dashboard', icon: 'ri-home-smile-line', to: '/' },
      { title: 'Applications', icon: 'ri-file-list-3-line', to: '/applications', ability: 'applications.process' },
    ],
  },
]

/* A section with nothing visible in it must not leave a stray heading behind. */
const visible = computed(() => sections
  .map(s => ({ ...s, items: s.items.filter(i => !i.ability || can(i.ability)) }))
  .filter(s => s.items.length > 0))
</script>

<template>
  <template
    v-for="section in visible"
    :key="section.heading"
  >
    <VerticalNavSectionTitle :item="{ heading: section.heading }" />
    <VerticalNavLink
      v-for="item in section.items"
      :key="item.to"
      :item="item"
    />
  </template>

  <!--
    Staff & roles (superadmin only) lands with its own screen and API. Kept out
    until then for the same reason as the four above.
  -->
</template>
