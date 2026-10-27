<script setup lang="ts">
/** AGR-02..05 — where the agreement stands: draft → legal review → approved → signature → signed → archived. */
import type { AgreementStatus } from '~/types/enums'

const props = defineProps<{ status: AgreementStatus }>()
const { t } = useI18n()
const KEYS = ['draft', 'legal_review', 'approved', 'sent_for_signature', 'signed', 'archived'] as const
const steps = computed(() => KEYS.map((k) => ({ key: k, label: t(`agreements.flow.${k}`) })))
const current = computed(() => {
  if (props.status === 'changes_requested') return 1
  const i = KEYS.indexOf(props.status as (typeof KEYS)[number])
  return i < 0 ? 0 : i
})
</script>

<template>
  <div>
    <p v-if="status === 'cancelled'" class="text-sm text-muted">{{ $t('agreements.flow.cancelled') }}</p>
    <StageTrack v-else :stages="steps.map((s) => s.label)" :current="current" :label="$t('common.status')" />
  </div>
</template>
