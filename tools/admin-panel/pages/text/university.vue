<script setup>
/*
  The fallback copy behind every university detail page: the cost intro and its
  footnote, the note shown when a university has no scholarship cards, the
  intake-card wording, the default FAQ set and the enquiry form's "I'm
  interested in" options.

  PublicUniversityController::pageDefaults() hands this to the template
  whenever a university carries no editorial content of its own, which for most
  of the catalogue is every section - so this is the wording visitors actually
  read. It was also the last singleton with no native screen: the only editor
  that ever reached it is the Filament page at /manage/university-defaults, one
  of the few forms keeping that old panel alive.

  A thin wrapper. The screen is components/GroupedContentEditor.vue - this key
  is declared in AdminContentController::GROUPED, not SCHEMA, so the flat
  editor would render a form with no fields in it.
*/
definePageMeta({ title: 'University page defaults' })
</script>

<template>
  <GroupedContentEditor singleton-key="universityPage" heading="University page defaults" />
</template>
