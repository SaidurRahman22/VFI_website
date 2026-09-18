<script setup>
/*
  The six "Study in ..." pages: hero wording, universities, scholarships,
  graduate salaries and FAQs, per country.

  A thin wrapper. The screen is components/GroupedContentEditor.vue, built from
  the shape AdminContentController::GROUPED declares for this key.
*/
definePageMeta({ title: 'Country pages' })
</script>

<template>
  <GroupedContentEditor singleton-key="countries" heading="Country pages" />
</template>
