<script setup>
/*
  The stock footer carried four vendor links (License, More Themes,
  Documentation, Support) pointing at themeselection.com. Those belong in a demo,
  not in a tool staff use all day.

  The template is MIT-licensed, which requires the notice to be retained in the
  SOURCE - see tools/admin-panel/LICENSE - not advertised in the chrome. A
  single discreet credit is kept as a courtesy.
*/
const year = new Date().getFullYear()
</script>

<template>
  <div class="h-100 d-flex align-center justify-space-between">
    <span class="text-disabled text-sm">
      &copy; {{ year }} VFI Foreign Consultancy &mdash; staff console
    </span>

    <span class="text-disabled text-sm d-none d-md-inline">
      UI based on Materio (MIT)
    </span>
  </div>
</template>
