<script setup>
/*
  The collections that fill the public marketing site.

  A route of its own, not a tab: the sidebar is where the two content groups are
  chosen, so each needs a URL that can be linked, refreshed and shown as the
  active nav item. Everything on the screen lives in ContentCollections.vue,
  which this and pages/content/partner.vue both use.
*/
definePageMeta({ title: 'Public website' })
</script>

<template>
  <ContentCollections
    group="public-website"
    heading="Public website"
    blurb="Events, articles, news and photos on vfi-fc.com. Changes are live as soon as you save."
  />
</template>
