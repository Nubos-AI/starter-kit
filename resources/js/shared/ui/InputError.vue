<script setup lang="ts">
defineProps<{
  message?: string | null;
  role?: 'alert';
}>();
</script>

<template>
  <div v-show="message" :role="role">
    <p class="text-sm text-danger">
      {{ message }}
    </p>
  </div>
</template>
