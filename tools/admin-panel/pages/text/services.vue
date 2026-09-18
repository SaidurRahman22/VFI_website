<script setup>
/*
  The service blocks on the public services page. One of a kind, so this key has
  no slug level - the editor renders its list straight away.

  A thin wrapper. The screen is components/GroupedContentEditor.vue.
*/
definePageMeta({ title: 'Services page' })
</script>

<template>
  <GroupedContentEditor singleton-key="servicesPage" heading="Services page" />
</template>
