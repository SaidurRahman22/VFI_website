<script setup>
/*
  UpgradeToPro was removed: the stock template renders a floating advert for the
  paid edition over every screen. This is the product, not a demo.
*/
const { isMobile } = useDevice()

if (isMobile)
  configStore.appContentLayoutNav = 'vertical'
</script>

<template>
  <VApp>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </VApp>
</template>
