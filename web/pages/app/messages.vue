<script setup lang="ts">
/** MTG-03 — owner / investor inbox: threads with the council on their opportunity files, interests and nominations. */
definePageMeta({ layout: 'portal', middleware: ['auth'] })
const { t } = useI18n()
useHead({ title: t('collab.messages.metaTitle') })
const auth = useAuthStore()
const localePath = useLocalePath()
// Staff work from the admin inbox (2FA-enforced area).
if (auth.isStaff) await navigateTo(localePath('/app/admin/messages'), { replace: true })
</script>

<template>
  <div>
    <PageHeader :title="$t('collab.messages.title')" :sub="$t('collab.messages.sub')" />
    <MessageInbox />
  </div>
</template>
