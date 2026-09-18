<script setup>
/*
  The wording on the public "become a partner" page.

  This had no editor anywhere. The Filament resource that once reached it went
  with the content prune, and the legacy admin page only ever wrote to the
  editor's own browser - while the fields were declared on the server the whole
  time, in AdminContentController::SCHEMA.

  A thin wrapper. The screen is components/SingletonEditor.vue.
*/
definePageMeta({ title: 'Partner page text' })
</script>

<template>
  <SingletonEditor singleton-key="partnerPage" heading="Partner page" />
</template>
