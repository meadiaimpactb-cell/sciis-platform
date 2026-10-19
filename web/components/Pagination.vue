<script setup lang="ts">
defineProps<{ page: number; lastPage: number; total: number }>()
const emit = defineEmits<{ (e: 'update:page', v: number): void }>()
</script>

<template>
  <nav v-if="lastPage > 1" class="flex items-center justify-between gap-3 text-sm" aria-label="pagination">
    <span class="text-muted">{{ $t('common.pageOf', { page, total: lastPage }) }} · {{ $t('common.resultCount', { n: total }) }}</span>
    <div class="flex gap-1">
      <AppButton variant="secondary" size="sm" :disabled="page <= 1" @click="emit('update:page', page - 1)">{{ $t('common.previous') }}</AppButton>
      <AppButton variant="secondary" size="sm" :disabled="page >= lastPage" @click="emit('update:page', page + 1)">{{ $t('common.next') }}</AppButton>
    </div>
  </nav>
</template>
