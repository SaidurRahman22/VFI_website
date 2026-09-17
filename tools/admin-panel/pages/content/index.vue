<script setup>
/*
  /content on its own is not a screen — the two groups are.

  It exists so that an old link, a bookmark or a typed URL lands somewhere
  useful instead of on the 404 page. Redirecting in onMounted rather than in
  middleware because these pages are prerendered to static HTML and served by
  nginx: there is no server to answer with a 302.
*/
definePageMeta({ title: 'Website content' })

onMounted(() => {
  useRouter().replace('/content/public')
})
</script>

<template>
  <div class="d-flex align-center gap-3 pa-6">
    <VProgressCircular
      indeterminate
      size="20"
    />
    <span class="text-body-2">Opening website content…</span>
  </div>
</template>
