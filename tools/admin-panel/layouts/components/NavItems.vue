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
const { can, isSuperAdmin } = useVfiUser()
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

        /*
          Open on arrival. A collapsed group is two rows nobody can see, and a
          collapsed child still occupies layout space while being clipped - so
          it looks present, reports itself visible, and a click on it lands on
          the group label instead. With two children there is nothing to gain by
          folding them away.
        */
        open: true,
        children: [
          { title: 'Public website', icon: 'ri-global-line', to: '/content/public' },
          { title: 'Partner console', icon: 'ri-briefcase-line', to: '/content/partner' },
        ],
      },
      { title: 'Page images', icon: 'ri-image-line', to: '/images', ability: 'content.manage' },
      { title: 'Site settings', icon: 'ri-settings-3-line', to: '/settings', ability: 'content.manage' },

      /*
        Owner only, and the nav says so by asking `isSuperAdmin` rather than an
        ability: AdminPageController requires isSuperAdmin(), so a content editor
        can write every word on the site and still not remove a page from it.
      */
      { title: 'Pages', icon: 'ri-file-list-line', to: '/pages', owner: true },
    ],
  },
  {
    heading: 'Administration',
    items: [
      /*
        Owner only for the same reason as Pages, and more so: import REPLACES all
        site content, which makes it the most destructive action in the console.
        AdminBackupController gates on isOwner().
      */
      { title: 'Backup', icon: 'ri-database-2-line', to: '/backup', owner: true },
    ],
  },
]

/*
  NOT YET REBUILT HERE, so these point at the screens that still own them.

  Signing in lands on this console, which means anything it cannot reach is
  effectively gone. Native now: the ten content collections, the site settings,
  page visibility, the page images and the backup. /manage still owns document
  review, agencies, universities, roles and the GDPR register.

  admin.html is NOT linked from here any more, deliberately. Every editor on
  that page writes to the editor's own localStorage - it makes one API call in
  its whole length, and that one is logout - so its forms say "Saved" and change
  nothing for a visitor or for another member of staff. The only content it
  still nominally owned was the per-country and per-region page text, which
  therefore never worked either; those overrides are empty on the server and
  need a native screen. Linking to a page that convincingly pretends to save is
  worse than having no link at all.

  Linking out is not decoration: these go somewhere that works today. They are
  labelled and grouped apart so it is obvious which parts of the console are
  finished, and each disappears from here as its native screen lands.
*/
const external = [
  {
    heading: 'Not yet rebuilt here',
    items: [
      { title: 'Staff tools', icon: 'ri-tools-line', href: '/manage', ability: 'documents.review' },
    ],
  },
]

function usable(list) {
  return list
    .map(s => ({
      ...s,
      items: s.items.filter(i =>
        (!i.ability || can(i.ability)) && (!i.owner || isSuperAdmin.value)),
    }))
    .filter(s => s.items.length > 0)
}

const visible = computed(() => usable(sections))
const legacy = computed(() => usable(external))

/* Open if it says so, or if it holds the page you are on. */
function groupOpen(item) {
  return Boolean(item.open) || (item.children || []).some(c => route.path.startsWith(c.to))
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
        :item="{ ...item, open: groupOpen(item) }"
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
