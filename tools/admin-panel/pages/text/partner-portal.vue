<script setup>
/*
  The wording inside the console partner agencies sign in to.

  Same story as the partner page: a complete field schema on the server, and no
  screen anywhere that rendered it.

  A thin wrapper. The screen is components/SingletonEditor.vue.
*/
definePageMeta({ title: 'Partner console text' })
</script>

<template>
  <SingletonEditor singleton-key="partnerPortal" heading="Partner console text" />
</template>
