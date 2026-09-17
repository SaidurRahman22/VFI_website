<script setup>
/*
  The collections that fill the partner console agencies sign in to.

  Same screen as pages/content/public.vue, different group — see the note there
  for why each group is a route rather than a tab.
*/
definePageMeta({ title: 'Partner console' })
</script>

<template>
  <ContentCollections
    group="partner-console"
    heading="Partner console"
    blurb="What partner agencies see when they sign in: their managers, updates, documents and notifications."
  />
</template>
