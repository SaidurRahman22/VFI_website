<script setup>
/*
  The Asia and Europe pages: hero wording and the per-country bands.

  A thin wrapper. The screen is components/GroupedContentEditor.vue.
*/
definePageMeta({ title: 'Region pages' })
</script>

<template>
  <GroupedContentEditor singleton-key="regions" heading="Region pages" />
</template>
