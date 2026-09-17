<script setup>
const props = defineProps({
  item: {
    type: Object,
    required: true,
  },
})

/*
  Open when it holds the page you are on.

  The template shipped this hardcoded to false, so a nav group was always
  collapsed on load - land on /content/public from a link or a refresh and the
  sidebar showed a closed "Website content" with no indication that the page you
  are looking at is inside it. `item.open` lets the caller say so.
*/
const isOpen = ref(Boolean(props.item.open))

watch(() => props.item.open, v => {
  if (v)
    isOpen.value = true
})
</script>

<template>
  <li
    class="nav-group"
    :class="isOpen && 'open'"
  >
    <div
      class="nav-group-label"
      @click="isOpen = !isOpen"
    >
      <VIcon
        :icon="item.icon || 'ri-checkbox-blank-circle-line'"
        class="nav-item-icon"
      />
      <span class="nav-item-title">{{ item.title }}</span>
      <!--
        Only when there IS a badge. The template rendered this span
        unconditionally, and an empty one still costs its padding-inline
        (0.75rem each side) plus the item's flex gap - about 32px of dead width
        on every row. On the nav group, which also carries a chevron, that was
        enough to truncate "Website content" to "Website cont...".
      -->
      <span
        v-if="item.badgeContent"
        class="nav-item-badge"
        :class="item.badgeClass"
      >
        {{ item.badgeContent }}
      </span>
      <VIcon
        icon="ri-arrow-right-s-line"
        class="nav-group-arrow"
      />
    </div>
    <div class="nav-group-children-wrapper">
      <ul class="nav-group-children">
        <slot />
      </ul>
    </div>
  </li>
</template>

<style lang="scss">
.layout-vertical-nav {
  .nav-group {
    &-label {
      display: flex;
      align-items: center;
      cursor: pointer;
    }

    .nav-group-children-wrapper {
      display: grid;
      grid-template-rows: 0fr;
      transition: grid-template-rows 0.3s ease-in-out;

      .nav-group-children {
        overflow: hidden;
      }
    }

    &.open {
      .nav-group-children-wrapper {
        grid-template-rows: 1fr;
      }
    }
  }
}
</style>
