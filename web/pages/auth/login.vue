<script setup lang="ts">
/** Login (ACC-01) — email+password, then 2FA challenge step when enabled (ACC-05). */
import { z } from 'zod'
import { currentPasswordField, emailField, toLatinDigits, twoFactorCodeField, RULES } from '~/utils/validation'
import { toastApiError, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t, locale } = useI18n()
useHead({ title: t('auth.login.metaTitle') })
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const localePath = useLocalePath()
const { mock } = useApiBase()
const form = useZodForm(z.object({ email: emailField, password: currentPasswordField }), { email: typeof route.query.email === 'string' ? route.query.email : '', password: '' })
// `?step=2fa`: the API answered 403 `two_factor_challenge_required` (session logged in, TOTP still pending).
const step = ref<'credentials' | '2fa'>(route.query.step === '2fa' && auth.twoFactorPending ? '2fa' : 'credentials')
// 6-digit TOTP or a 10-character recovery code
const codeForm = useZodForm(z.object({ code: twoFactorCodeField }), { code: '' })

/** Login completes here (credentials without 2FA, or after the 2FA challenge): welcome toast, then redirect. */
const welcome = () => {
  const name = auth.me?.name?.trim()
  toastSuccess(t('notify.welcome', { name: name ? (locale.value === 'ar' ? `، ${name}` : `, ${name}`) : '' }))
  const redirect = route.query.redirect as string | undefined
  router.push(redirect && redirect.startsWith('/') ? redirect : localePath(auth.homePath))
}
const submit = () => form.submit(async (v) => {
  const res = await auth.login(v.email.trim(), v.password)
  if (res.twoFactor) {
    codeForm.reset()
    step.value = '2fa'
  } else welcome()
})
const verify = () => codeForm.submit(async (v) => {
  await auth.challenge2fa(v.code.trim().toUpperCase())
  welcome()
})
// ACC-05 — no authenticator app at hand: a one-time sign-in code by e-mail, or SMS when the phone is verified and SMS works.
const tax = useTaxonomyStore()
watch(step, (s) => { if (s === '2fa') tax.load().catch(() => {}) }, { immediate: true })
const smsAvailable = computed(() => tax.settings?.phone_verification !== false && !!auth.me?.phone_verified)
const sendingCode = ref<'email' | 'phone' | null>(null)
const sendLoginCode = async (channel: 'email' | 'phone') => {
  sendingCode.value = channel
  try {
    await useApi().post('/auth/2fa/send-code', { channel })
    toastSuccess(t(channel === 'email' ? 'notify.codeSentEmail' : 'notify.codeSentPhone'))
  } catch (e) {
    toastApiError(e)
  } finally {
    sendingCode.value = null
  }
}
const cancel2fa = async () => {
  await auth.logout()
  step.value = 'credentials'
}
const demo = [
  { key: 'investor', email: 'investor@demo.sciis.sa' },
  { key: 'investorInst', email: 'familyoffice@demo.sciis.sa' },
  { key: 'entrepreneur', email: 'entrepreneur@demo.sciis.sa' },
  { key: 'admin', email: 'admin@demo.sciis.sa' },
  { key: 'reviewer', email: 'reviewer@demo.sciis.sa' },
  { key: 'evaluator', email: 'evaluator1@demo.sciis.sa' },
  { key: 'matching', email: 'matching@demo.sciis.sa' },
  { key: 'membership', email: 'membership@demo.sciis.sa' },
]
const fill = (email: string) => { form.values.email = email; form.values.password = 'password'; form.clearErrors(); toastInfo(t('auth.login.demoHint')) }
</script>

<template>
  <div class="card p-6 sm:p-8 mx-auto max-w-lg">
    <template v-if="step === 'credentials'">
      <h1 class="text-2xl">{{ $t('auth.login.title') }}</h1>
      <p class="mt-2 text-base text-muted">{{ $t('auth.login.sub') }}</p>
      <form class="mt-7 space-y-5" novalidate @submit.prevent="submit">
        <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required autocomplete="email" ltr />
        <div class="space-y-2">
          <FormField v-model="form.values.password" name="password" type="password" :label="$t('common.password')" :error="form.errors.value.password" required autocomplete="current-password" ltr />
          <div class="flex items-center justify-end text-sm"><NuxtLink :to="$localePath('/auth/forgot')" class="link">{{ $t('auth.login.forgot') }}</NuxtLink></div>
        </div>
        <FormAlert :message="form.formError.value" />
        <AppButton type="submit" block size="lg" :loading="form.submitting.value">{{ $t('auth.login.submit') }}</AppButton>
      </form>
      <p class="mt-6 border-t border-border pt-5 text-sm text-muted text-center">{{ $t('auth.login.noAccount') }} <NuxtLink :to="$localePath('/auth/register')" class="link">{{ $t('auth.login.register') }}</NuxtLink></p>
      <div v-if="mock" class="mt-6 rounded-xl border border-dashed border-ink/20 bg-ink/5 p-4">
        <p class="text-xs font-medium text-ink">{{ $t('auth.login.demoAccounts') }}</p>
        <p class="text-xs text-muted mt-0.5">{{ $t('auth.login.demoHint') }}</p>
        <div class="mt-3 flex flex-wrap gap-2"><button v-for="d in demo" :key="d.key" type="button" class="chip text-xs" @click="fill(d.email)">{{ $t(`auth.login.demoRoles.${d.key}`) }}</button></div>
      </div>
    </template>
    <template v-else>
      <span class="flex h-12 w-12 items-center justify-center rounded-full bg-accent/10 text-accent-text ring-1 ring-accent/30" aria-hidden="true">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5" /><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" /><path d="M12 14.5v2.5" /></svg>
      </span>
      <h1 class="mt-5 text-2xl">{{ $t('auth.login.twoFactorTitle') }}</h1>
      <p class="mt-2 text-base text-muted">{{ $t('auth.login.twoFactorText') }}</p>
      <form class="mt-7 space-y-5" novalidate @submit.prevent="verify">
        <FormField :model-value="codeForm.values.code" name="code" type="text" :label="$t('auth.login.twoFactorCode')" :help="$t('auth.login.twoFactorRecoveryHint')" :error="codeForm.errors.value.code" required ltr autocomplete="one-time-code" :maxlength="RULES.recoveryCodeLength" placeholder="000000" @update:model-value="codeForm.values.code = toLatinDigits(String($event ?? ''))" />
        <p v-if="mock" class="text-xs text-muted">{{ $t('auth.verify.demoHint') }}</p>
        <FormAlert :message="codeForm.formError.value" />
        <div class="space-y-2">
          <AppButton type="submit" block size="lg" :loading="codeForm.submitting.value">{{ $t('auth.login.verify') }}</AppButton>
          <AppButton type="button" variant="ghost" block @click="cancel2fa">{{ $t('common.back') }}</AppButton>
        </div>
      </form>
      <div class="mt-6 border-t border-border pt-5">
        <p class="text-sm text-muted">{{ $t('auth.login.twoFactorAlt') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
          <AppButton size="sm" variant="secondary" :loading="sendingCode === 'email'" :disabled="!!sendingCode" @click="sendLoginCode('email')">{{ $t('auth.login.sendCodeEmail') }}</AppButton>
          <AppButton v-if="smsAvailable" size="sm" variant="secondary" :loading="sendingCode === 'phone'" :disabled="!!sendingCode" @click="sendLoginCode('phone')">{{ $t('auth.login.sendCodePhone') }}</AppButton>
        </div>
      </div>
    </template>
  </div>
</template>
