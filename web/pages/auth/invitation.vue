<script setup lang="ts">
/**
 * ACC-08 — join an organisation account from the e-mailed invitation (`?token=`). The invitation fixes the e-mail
 * (pre-verified by the link), the organisation and the account type; the colleague adds their name, mobile and
 * password and accepts the policies. GET /auth/invitations/{token} → preview (404 invalid · 410 expired);
 * POST /auth/invitations/{token}/accept → signed in like /auth/register, then the phone OTP step when required.
 */
import { z } from 'zod'
import type { InvitationPreview } from '~/types/collab'
import { acceptField, normalizePhone, parseApiError, passwordConfirmField, passwordField, phoneField, requiredSelect, staffNameField, vmsg } from '~/utils/validation'
import { toastError, toastSuccess } from '~/utils/notify'
import type { Me } from '~/types/api'
definePageMeta({ layout: 'auth' })
const { t, locale } = useI18n()
useHead({ title: t('collab.invitation.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const token = typeof route.query.token === 'string' ? route.query.token : ''

const preview = ref<InvitationPreview | null>(null)
const state = ref<'loading' | 'ready' | 'invalid' | 'expired'>('loading')
onMounted(async () => {
  if (!auth.loaded) await auth.fetchMe().catch(() => null)
  if (!token) return void (state.value = 'invalid')
  try {
    preview.value = (await api.get<{ data: InvitationPreview }>(`/auth/invitations/${encodeURIComponent(token)}`)).data
    form.values.name = preview.value.name ?? ''
    state.value = 'ready'
  } catch (e) {
    const p = parseApiError(e)
    state.value = p.status === 410 || p.code === 'invitation_expired' ? 'expired' : 'invalid'
  }
})
const isInvestor = computed(() => !!preview.value?.type.startsWith('investor'))

const schema = z
  .object({
    name: staffNameField,
    phone: phoneField,
    password: passwordField,
    password_confirmation: passwordConfirmField,
    preferred_contact: requiredSelect('auth.register.preferredContact'),
    consent_privacy: acceptField,
    consent_terms: acceptField,
    consent_accuracy: z.boolean(),
  })
  .superRefine((v, ctx) => {
    if (v.password && v.password_confirmation && v.password !== v.password_confirmation) ctx.addIssue({ code: 'custom', path: ['password_confirmation'], message: vmsg('passwordMatch') })
    if (isInvestor.value && !v.consent_accuracy) ctx.addIssue({ code: 'custom', path: ['consent_accuracy'], message: vmsg('mustAccept') })
  })
const form = useZodForm(schema as never, { name: '', phone: '', password: '', password_confirmation: '', preferred_contact: 'email', consent_privacy: false, consent_terms: false, consent_accuracy: false }, { aliases: { consents: 'consent_terms', 'consents.data_accuracy': 'consent_accuracy' } })
const contactOpts = [{ value: 'email', label: t('common.email') }, { value: 'phone', label: t('common.phone') }, { value: 'whatsapp', label: t('contact.whatsapp') }]

/** The accept call already sent the phone code — /auth/verify starts its resend countdown from this. */
const otpSentAt = useState<number | null>('otp-sent-at', () => null)
const accept = () => form.submit(async (v) => {
  const res = await api.post<{ data: Me; message?: string }>(`/auth/invitations/${encodeURIComponent(token)}/accept`, {
    name: v.name.trim(),
    phone: normalizePhone(v.phone) || v.phone,
    password: v.password,
    password_confirmation: v.password_confirmation,
    locale: locale.value === 'en' ? 'en' : 'ar',
    preferred_contact: v.preferred_contact,
    consents: isInvestor.value ? ['privacy_policy', 'terms', 'data_accuracy'] : ['privacy_policy', 'terms'],
    consents_version: tax.settings?.legal_versions?.terms,
  })
  auth.me = res.data
  auth.loaded = true
  await auth.fetchMe(true).catch(() => null)
  otpSentAt.value = Date.now()
  toastSuccess(t('collab.invitation.joined', { name: preview.value?.organization.name ?? '' }))
  const gate = await auth.gate()
  await router.push(gate ? { path: localePath(gate.path), query: gate.query } : localePath(auth.homePath))
})

const signingOut = ref(false)
const signOut = async () => {
  signingOut.value = true
  try {
    if (!(await auth.logout())) toastError(t('notify.logoutFailed'))
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <div class="card p-6 sm:p-8">
    <h1 class="text-2xl">{{ $t('collab.invitation.title') }}</h1>
    <p v-if="state === 'loading'" class="mt-4 text-base text-muted">{{ $t('common.loading') }}</p>

    <template v-else-if="state !== 'ready'">
      <InlineAlert tone="warning" class="mt-5" :title="$t(state === 'expired' ? 'collab.invitation.expiredTitle' : 'collab.invitation.invalidTitle')">{{ $t(state === 'expired' ? 'collab.invitation.expiredText' : 'collab.invitation.invalidText') }}</InlineAlert>
      <div class="mt-6 flex flex-wrap gap-2"><AppButton to="/auth/login" variant="secondary">{{ $t('auth.login.submit') }}</AppButton><AppButton to="/" variant="ghost">{{ $t('nav.home') }}</AppButton></div>
    </template>

    <template v-else-if="preview">
      <p class="mt-2 text-base text-muted">{{ $t('collab.invitation.sub', { org: preview.organization.name }) }}</p>
      <dl class="mt-6 divide-y divide-border overflow-hidden rounded-xl border border-border bg-ink/5 text-sm">
        <div class="grid sm:grid-cols-[160px_1fr] gap-1 px-4 py-3"><dt class="text-muted">{{ $t('collab.invitation.organization') }}</dt><dd class="font-medium text-ink">{{ preview.organization.name }}</dd></div>
        <div class="grid sm:grid-cols-[160px_1fr] gap-1 px-4 py-3"><dt class="text-muted">{{ $t('auth.register.typeTitle') }}</dt><dd class="font-medium text-ink">{{ preview.type_label }}</dd></div>
        <div class="grid sm:grid-cols-[160px_1fr] gap-1 px-4 py-3"><dt class="text-muted">{{ $t('common.email') }}</dt><dd class="font-medium text-ink ltr text-start" dir="ltr">{{ preview.email }}</dd></div>
      </dl>

      <div v-if="auth.isAuthenticated" class="mt-6">
        <InlineAlert tone="warning">{{ $t('collab.invitation.signedIn', { name: auth.me?.name ?? '' }) }}</InlineAlert>
        <AppButton class="mt-4" variant="secondary" :loading="signingOut" @click="signOut">{{ $t('common.logout') }}</AppButton>
      </div>

      <form v-else class="mt-7 space-y-5" novalidate @submit.prevent="accept">
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="form.values.name" name="name" :label="$t('common.name')" :error="form.errors.value.name" required autocomplete="name" class="sm:col-span-2" />
          <PhoneField v-model="form.values.phone" name="phone" :label="$t('common.phone')" :error="form.errors.value.phone" required />
          <FormField v-model="form.values.preferred_contact" name="preferred_contact" type="select" :label="$t('auth.register.preferredContact')" :options="contactOpts" :error="form.errors.value.preferred_contact" required />
          <div>
            <FormField v-model="form.values.password" name="password" type="password" :label="$t('common.password')" :error="form.errors.value.password" required autocomplete="new-password" ltr />
            <PasswordChecklist :password="form.values.password" />
          </div>
          <FormField v-model="form.values.password_confirmation" name="password_confirmation" type="password" :label="$t('common.passwordConfirm')" :error="form.errors.value.password_confirmation" required autocomplete="new-password" ltr />
        </div>
        <fieldset class="rounded-xl border border-border p-4 sm:p-5 space-y-3">
          <legend class="px-1 text-sm font-semibold text-ink">{{ $t('auth.register.declarations') }}</legend>
          <FormField v-if="isInvestor" v-model="form.values.consent_accuracy" name="consent_accuracy" type="checkbox" :label="$t('auth.register.consentAccuracy')" :error="form.errors.value.consent_accuracy" required />
          <FormField v-model="form.values.consent_privacy" name="consent_privacy" type="checkbox" :error="form.errors.value.consent_privacy" required>
            <template #label>{{ $t('auth.register.consentPrivacy') }} (<NuxtLink :to="$localePath('/legal/privacy')" class="link" target="_blank">{{ $t('footer.privacy') }}</NuxtLink>)</template>
          </FormField>
          <FormField v-model="form.values.consent_terms" name="consent_terms" type="checkbox" :error="form.errors.value.consent_terms" required>
            <template #label>{{ $t('auth.register.consentTerms') }} (<NuxtLink :to="$localePath('/legal/terms')" class="link" target="_blank">{{ $t('footer.terms') }}</NuxtLink>)</template>
          </FormField>
        </fieldset>
        <p class="text-xs text-muted">{{ $t('collab.invitation.colleagueNote') }}</p>
        <FormAlert />
        <AppButton type="submit" block size="lg" :loading="form.submitting.value">{{ $t('collab.invitation.submit') }}</AppButton>
      </form>
    </template>
  </div>
</template>
