<script setup lang="ts">
/** AGR-04 — the agreements a project owner (/entrepreneur) or investor (/investor) is a party to (approved ones only). */
import type { AgreementListItem } from '~/types/api'

const props = defineProps<{ portal: 'investor' | 'entrepreneur' }>()
const api = useApi()
const { date } = useFormat()
const { data, loading } = useAsyncResource(() => api.get<{ data: { agreements: AgreementListItem[] } }>(`/${props.portal}/agreements`).then((r) => r.data.agreements))
const counterparty = (a: AgreementListItem) => (props.portal === 'investor' ? a.owner?.name : a.investor?.name) ?? '—'
</script>

<template>
  <div>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('agreements.party.empty')" :text="$t('agreements.party.emptyText')" />
    <div v-else class="space-y-4">
      <article v-for="a in data" :key="a.uuid" class="card card-pad">
        <div class="flex flex-col md:flex-row md:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <AppBadge kind="agreement" :value="a.status" size="md" />
              <AppBadge v-if="a.awaiting_my_signature" kind="generic" value="pending" :label="$t('agreements.party.awaitingYou')" />
              <span class="text-xs text-muted num">{{ a.number }}</span>
            </div>
            <h2 class="mt-2 text-lg font-semibold"><NuxtLink :to="$localePath(`/app/${portal}/agreements/${a.uuid}`)" class="hover:text-blue">{{ a.title }}</NuxtLink></h2>
            <p class="text-sm text-muted">{{ a.opportunity?.name }} · {{ $t(`enums.agreementParty.${portal === 'investor' ? 'owner' : 'investor'}`) }}: {{ counterparty(a) }}</p>
            <p class="mt-2 text-xs text-muted num">
              <template v-if="a.signed_at">{{ $t('agreements.party.signedOn', { date: date(a.signed_at) }) }}</template>
              <template v-else-if="a.sent_for_signature_at">{{ $t('agreements.party.sentOn', { date: date(a.sent_for_signature_at) }) }}</template>
              <template v-else>{{ $t('agreements.party.updatedOn', { date: date(a.updated_at) }) }}</template>
            </p>
          </div>
          <div class="shrink-0"><AppButton :variant="a.awaiting_my_signature ? 'primary' : 'secondary'" size="sm" :to="`/app/${portal}/agreements/${a.uuid}`">{{ a.awaiting_my_signature ? $t('agreements.party.reviewAndSign') : $t('common.view') }}</AppButton></div>
        </div>
      </article>
    </div>
  </div>
</template>
