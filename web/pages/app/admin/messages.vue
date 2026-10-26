<script setup lang="ts">
/** MTG-03 — staff inbox: owner threads (opportunities.transition) and investor threads (nominations.manage). */
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('collab.messages.metaTitle') })
const auth = useAuthStore()
const allowed = computed(() => auth.canAny('opportunities.transition', 'nominations.manage'))
</script>

<template>
  <div>
    <PageHeader :title="$t('collab.messages.title')" :sub="$t('collab.messages.staffSub')" />
    <MessageInbox v-if="allowed" staff />
    <EmptyState v-else :title="$t('notify.forbidden')" />
  </div>
</template>
