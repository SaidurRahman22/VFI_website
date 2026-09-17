<script setup>
import VerticalNavSectionTitle from '@/@layouts/components/VerticalNavSectionTitle.vue'
import VerticalNavGroup from '@layouts/components/VerticalNavGroup.vue'
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
const route = useRoute()

/*
  BUILT HERE, natively.

  The remaining screens (Students, Partner agencies, Universities, Staff &
  roles) join this list in the commit that adds their page AND their API. A link
  to an empty screen is exactly the decoration the client has spent days
  finding.

  Website content is a GROUP of two, because its ten collections fill two
  different products: the public marketing site, and the console partner
  agencies sign in to. Those two labels are written here rather than fetched,
  because the sidebar has to render before any request answers; the server holds
  the authoritative copy in AdminContentCollectionController::GROUPS and decides
  which collection belongs to which.
*/
const sections = [
  {
    heading: 'Daily work',
    items: [
      { title: 'Dashboard', icon: 'ri-home-smile-line', to: '/' },
      { title: 'Applications', icon: 'ri-file-list-3-line', to: '/applications', ability: 'applications.process' },
    ],
  },
  {
    heading: 'The website',
    items: [
      {
        title: 'Website content',
        icon: 'ri-pages-line',
        ability: 'content.manage',
        children: [
          { title: 'Public website', icon: 'ri-global-line', to: '/content/public' },
          { title: 'Partner console', icon: 'ri-briefcase-line', to: '/content/partner' },
        ],
      },
    ],
  },
]

/*
  NOT YET REBUILT HERE, so these point at the screens that still own them.

  Signing in lands on this console, which means anything it cannot reach is
  effectively gone. The ten content collections are now native (above), so what
  is left on the legacy page is the fixed page furniture - which pages are
  switched on, the home-page images, and the backup export/import - while
  /manage still owns document review, agencies and GDPR requests.

  Linking out is not decoration: these go somewhere that works today. They are
  labelled and grouped apart so it is obvious which parts of the console are
  finished, and each disappears from here as its native screen lands.
*/
const external = [
  {
    heading: 'Not yet rebuilt here',
    items: [
      // Short enough not to be truncated by the sidebar at its own width.
      { title: 'Pages & backup', icon: 'ri-layout-4-line', href: '/admin.html', ability: 'content.manage' },
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

/* A group holding the current page opens itself - see VerticalNavGroup. */
function holdsCurrentPage(item) {
  return (item.children || []).some(c => route.path.startsWith(c.to))
}
</script>

<template>
  <template
    v-for="section in visible"
    :key="section.heading"
  >
    <VerticalNavSectionTitle :item="{ heading: section.heading }" />

    <template
      v-for="item in section.items"
      :key="item.to || item.title"
    >
      <VerticalNavGroup
        v-if="item.children"
        :item="{ ...item, open: holdsCurrentPage(item) }"
      >
        <VerticalNavLink
          v-for="child in item.children"
          :key="child.to"
          :item="child"
        />
      </VerticalNavGroup>

      <VerticalNavLink
        v-else
        :item="item"
      />
    </template>
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
