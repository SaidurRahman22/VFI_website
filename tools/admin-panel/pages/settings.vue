<script setup>
/*
  Site settings: the brand, the contact details and the social links.

  These appear in the footer of every page, so they are the most visible content
  in the project - and the only screen that used to edit them wrote to the
  editor's own localStorage, so changing the phone number changed it for that
  person's browser and for nobody else.

  A thin wrapper. The screen is components/SingletonEditor.vue, built from the
  form schema AdminContentController declares for this key.
*/
definePageMeta({ title: 'Site settings' })
</script>

<template>
  <SingletonEditor singleton-key="settings" heading="Site settings" />
</template>
