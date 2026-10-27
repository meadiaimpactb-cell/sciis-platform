<script setup lang="ts">
/**
 * MTG-04 — on-platform event registration (lazy-loaded by the static event page, so the page itself stays as fast as
 * before). Status is fetched client-side (not cached): open / full / deadline / closed, seats left, and — for a
 * signed-in visitor — whether they are already registered (form prefilled). POST is rate-limited with a honeypot
 * like the contact form (CMS-05); the confirmation e-mail carries an .ics.
 */
import { z } from 'zod'
import type { EventRegistrationReceipt, EventRegistrationStatus } from '~/types/collab'
import { emailField, normalizePhone, optionalPhoneField, optionalText, parseApiError, requiredString, RULES } from '~/utils/validation'
import { toastSuccess } from '~/utils/notify'

const props = defineProps<{ slug: string }>()
const api = useApi()
const { t } = useI18n()
const { dateTime, number } = useFormat()
const path = `/public/events/${encodeURIComponent(props.slug)}`

const status = ref<EventRegistrationStatus | null>(null)
const loading = ref(true)
const receipt = ref<EventRegistrationReceipt | null>(null)

const form = useZodForm(
  z.object({ name: requiredString('common.name', RULES.nameMin, RULES.staffNameMax), email: emailField, phone: optionalPhoneField, organization: optionalText(RULES.titleMax), website: z.string().optional() }),
  { name: '', email: '', phone: '', organization: '' as string | null, website: '' },
)

const loadStatus = async () => {
  try {
    status.value = (await api.get<{ data: EventRegistrationStatus }>(`${path}/registration`)).data
    const me = status.value?.me
    if (me && !form.values.name && !form.values.email) form.reset({ name: me.name ?? '', email: me.email ?? '', phone: me.phone ?? '', organization: me.organization ?? '' })
  } catch {
    status.value = null
  } finally {
    loading.value = false
  }
}
onMounted(loadStatus)

const register = () => form.submit(async (v) => {
  try {
    const r = await api.post<{ data: EventRegistrationReceipt; message?: string }>(`${path}/registrations`, {
      name: v.name.trim(), email: v.email.trim().toLowerCase(), phone: normalizePhone(v.phone ?? '') || undefined, organization: v.organization?.trim() || undefined, website: v.website ?? '',
    })
    receipt.value = r.data
    toastSuccess(t('collab.events.registered'))
  } catch (e) {
    // Closed or full meanwhile → show the current state instead of the form.
    if (parseApiError(e).errors.registration) void loadStatus()
    throw e
  }
})

const closedText = computed(() => (status.value?.reason ? t(`collab.events.reasons.${status.value.reason}`) : t('collab.events.reasons.closed')))
</script>

<template>
  <div v-if="loading" class="mt-6 h-10 rounded-full bg-ink/10 animate-pulse" aria-hidden="true" />
  <div v-else-if="status?.enabled" class="mt-6 pt-6 border-t border-border">
    <!-- registered (now or before) -->
    <div v-if="receipt || status.me?.registered" class="text-center">
      <span class="mx-auto h-12 w-12 rounded-full bg-success/10 text-success flex items-center justify-center" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg></span>
      <p class="card-title mt-3">{{ $t('collab.events.youAreRegistered') }}</p>
      <p class="mt-1 text-sm text-muted">{{ receipt ? $t('collab.events.confirmationSent', { email: receipt.email }) : $t('collab.events.alreadyRegistered') }}</p>
      <p class="mt-1 text-xs text-muted">{{ $t('collab.events.addBelow') }}</p>
    </div>

    <!-- closed / full / deadline passed -->
    <InlineAlert v-else-if="!status.open" tone="warning" :title="$t('collab.events.closedTitle')">{{ closedText }}</InlineAlert>

    <!-- open -->
    <form v-else class="space-y-4" novalidate @submit.prevent="register">
      <div>
        <h2 class="card-title">{{ $t('collab.events.formTitle') }}</h2>
        <p class="mt-1 text-xs text-muted">
          <template v-if="status.seats_left !== null">{{ $t('collab.events.seatsLeft', { n: number(status.seats_left) }) }}</template>
          <template v-if="status.seats_left !== null && status.deadline"> · </template>
          <template v-if="status.deadline">{{ $t('collab.events.deadline') }}: <span class="num">{{ dateTime(status.deadline) }}</span></template>
        </p>
      </div>
      <FormField v-model="form.values.name" name="name" :label="$t('common.name')" :error="form.errors.value.name" required autocomplete="name" />
      <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required autocomplete="email" ltr />
      <PhoneField v-model="form.values.phone" name="phone" :label="$t('common.phone')" :error="form.errors.value.phone" />
      <FormField v-model="form.values.organization" name="organization" :label="$t('collab.events.organization')" :error="form.errors.value.organization" autocomplete="organization" :maxlength="RULES.titleMax" />
      <!-- honeypot: hidden from humans, bots fill it -->
      <div class="hidden" aria-hidden="true"><label for="event-website">Website</label><input id="event-website" v-model="form.values.website" type="text" tabindex="-1" autocomplete="off"></div>
      <FormAlert />
      <AppButton type="submit" variant="accent" block :loading="form.submitting.value">{{ $t('collab.events.submit') }}</AppButton>
      <p class="text-xs text-muted leading-5">{{ $t('collab.events.privacy') }}</p>
    </form>
  </div>
</template>
