<script setup>
import VerticalNavSectionTitle from '@/@layouts/components/VerticalNavSectionTitle.vue'
import VerticalNavLink from '@layouts/components/VerticalNavLink.vue'

/*
  The whole navigation, deliberately.

  The Filament panel this replaces exposed 21 sidebar entries - one per Eloquent
  resource, because that is what generating an admin from models gives you. Ten
  were separate content collections, which puts a database schema on screen
  instead of a tool someone can work in.

  `ability` hides a link the person cannot use. That is a courtesy, not a
  security boundary - the endpoint and the screen both refuse independently.
*/
const { can } = useVfiUser()

/*
  BUILT HERE, natively.

  The remaining screens (Students, Partner agencies, Universities, Website
  content, Staff & roles) join this list in the commit that adds their page AND
  their API. A link to an empty screen is exactly the decoration the client has
  spent days finding.
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

/*
  NOT YET REBUILT HERE, so these point at the screens that still own them.

  Signing in now lands on this console, which means anything it cannot reach is
  effectively gone - and the legacy panel still owns every website-content
  editor (events, blogs, news, photos, home images, Pages On/Off, backup) while
  /manage still owns document review, agencies and GDPR requests.

  Linking out is not decoration: these go somewhere that works today. They are
  labelled and grouped apart so it is obvious which parts of the console are
  finished, and each disappears from here as its native screen lands.
*/
const external = [
  {
    heading: 'Not yet rebuilt here',
    items: [
      { title: 'Website content', icon: 'ri-pages-line', href: '/admin.html', ability: 'content.manage' },
      { title: 'Staff tools', icon: 'ri-tools-line', href: '/manage', ability: 'documents.review' },
    ],
  },
]

function usable(list) {
  return list
    .map(s => ({ ...s, items: s.items.filter(i => !i.ability || can(i.ability)) }))
    .filter(s => s.items.length > 0)
}

const visible = computed(() => usable(sections))
const legacy = computed(() => usable(external))
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

  <template
    v-for="section in legacy"
    :key="section.heading"
  >
    <VerticalNavSectionTitle :item="{ heading: section.heading }" />
    <VerticalNavLink
      v-for="item in section.items"
      :key="item.href"
      :item="item"
    />
  </template>
</template>
