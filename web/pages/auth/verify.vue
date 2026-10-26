<script setup lang="ts">
/**
 * Email + phone OTP verification (ACC-01) — standard contract:
 *  POST /auth/otp/send {channel: email|phone} → {data: {resend_in}}   (429 `otp_cooldown` while the 60 s cooldown runs)
 *  POST /auth/verify-otp {channel, code}      → {data: {email_verified, phone_verified, fully_verified}} (then /me)
 * Registration already sent both codes, so the resend countdown starts at 60 s when arriving from /auth/register.
 */
import { z } from 'zod'
import { codeField, parseApiError, RULES, toLatinDigits } from '~/utils/validation'
import { toastApiError, toastInfo, toastSuccess, toastWarning } from '~/utils/notify'
definePageMeta({ layout: 'auth', middleware: 'auth' })
const { t } = useI18n()
useHead({ title: t('auth.verify.metaTitle') })
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const router = useRouter()
const localePath = useLocalePath()
const { mock } = useApiBase()

type Channel = 'email' | 'phone'
const emailForm = useZodForm(z.object({ email_code: codeField }), { email_code: '' }, { aliases: { code: 'email_code' } })
const phoneForm = useZodForm(z.object({ phone_code: codeField }), { phone_code: '' }, { aliases: { code: 'phone_code' } })
const forms = { email: emailForm, phone: phoneForm }
const codeKey = (ch: Channel) => (ch === 'email' ? 'email_code' : 'phone_code')
const codeOf = (ch: Channel) => (ch === 'email' ? emailForm.values.email_code : phoneForm.values.phone_code)
/** Accept Arabic-Indic digits and pasted spaces; keep at most the code length. */
const onCode = (ch: Channel, raw: string) => {
  const v = toLatinDigits(raw).slice(0, RULES.codeLength)
  if (ch === 'email') emailForm.values.email_code = v
  else phoneForm.values.phone_code = v
}

// ---- resend countdown (per channel) ----
/** Set by the register page right after the account (and both codes) were created. */
const sentAt = useState<number | null>('otp-sent-at', () => null)
const cooldown = reactive<Record<Channel, number>>({ email: 0, phone: 0 })
let ticker: ReturnType<typeof setInterval> | null = null
const tick = () => {
  for (const ch of ['email', 'phone'] as Channel[]) if (cooldown[ch] > 0) cooldown[ch]--
  if (!cooldown.email && !cooldown.phone && ticker) { clearInterval(ticker); ticker = null }
}
const startCooldown = (ch: Channel, seconds: number = RULES.otpResendSeconds) => {
  cooldown[ch] = Math.max(0, Math.round(seconds))
  if (!ticker && import.meta.client) ticker = setInterval(tick, 1000)
}
onMounted(() => {
  if (!sentAt.value) return
  const left = RULES.otpResendSeconds - Math.floor((Date.now() - sentAt.value) / 1000)
  if (left > 0) { startCooldown('email', left); startCooldown('phone', left) }
})
onBeforeUnmount(() => { if (ticker) clearInterval(ticker) })

const sending = ref<Channel | null>(null)
const send = async (ch: Channel) => {
  if (cooldown[ch] > 0 || sending.value) return
  sending.value = ch
  try {
    const { resendIn } = await auth.sendOtp(ch)
    startCooldown(ch, resendIn)
    toastSuccess(t(ch === 'email' ? 'notify.codeSentEmail' : 'notify.codeSentPhone'))
  } catch (e) {
    const p = parseApiError(e)
    if (p.code === 'otp_cooldown' || p.status === 429) {
      // The server message carries the remaining seconds; restart the local countdown so the button stays honest.
      startCooldown(ch)
      toastWarning(p.message || t('notify.tooMany'))
    } else if (p.code === 'already_verified') {
      await auth.fetchMe(true)
      toastInfo(p.message)
    } else toastApiError(e)
  } finally {
    sending.value = null
  }
}

const verify = (ch: Channel) =>
  forms[ch].submit(async () => {
    await auth.verify(ch, codeOf(ch))
    toastSuccess(t(ch === 'email' ? 'notify.emailVerified' : 'notify.phoneVerified'))
    forms[ch].reset()
  })

const goHome = () => router.push(localePath(auth.homePath))
// Local-dev only: `GET /settings/public` → verification_optional (SCIIS_VERIFICATION_OPTIONAL) lets the user skip the OTPs.
const canSkip = computed(() => auth.verificationOptional && !auth.isVerified)
const sections = computed(() => [
  { ch: 'email' as Channel, title: t('common.email'), value: auth.me?.email, verified: auth.emailVerified, label: t('auth.verify.emailCode'), submit: t('auth.verify.verifyEmail') },
  { ch: 'phone' as Channel, title: t('common.phone'), value: auth.me?.phone, verified: auth.phoneVerified, label: t('auth.verify.phoneCode'), submit: t('auth.verify.verifyPhone') },
].filter((s) => s.ch === 'email' || auth.phoneRequired))
</script>

<template>
  <div class="card card-pad">
    <h1 class="text-2xl font-semibold">{{ $t('auth.verify.title') }}</h1>
    <p class="text-muted mt-1">{{ $t(auth.phoneRequired ? 'auth.verify.sub' : 'auth.verify.subEmailOnly') }}</p>
    <p v-if="mock" class="text-xs text-muted mt-2">{{ $t('auth.verify.demoHint') }}</p>
    <div class="mt-6 space-y-6">
      <section v-for="s in sections" :key="s.ch" class="rounded-xl border border-border p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-medium">{{ s.title }} <span class="text-muted text-sm ltr inline-block" :class="s.ch === 'phone' && 'num'" dir="ltr">{{ s.value }}</span></h2>
          <span v-if="s.verified" class="inline-flex items-center gap-1 rounded-full bg-success/10 text-success text-xs font-medium px-2 py-0.5">
            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
            {{ s.ch === 'phone' ? $t('phoneField.verified') : $t('auth.verify.verified') }}
          </span>
          <span v-else class="rounded-full bg-warning/10 text-warning text-xs font-medium px-2 py-0.5">{{ $t('phoneField.unverified') }}</span>
        </div>
        <!-- one row on sm+: input (flex-1, h-10) and the two h-10 buttons share the same top edge; counter/error stay under the input -->
        <form v-if="!s.verified" class="mt-3 space-y-2" novalidate @submit.prevent="verify(s.ch)">
          <div class="flex flex-col sm:flex-row gap-2 sm:items-start">
            <div class="flex-1 min-w-0">
              <label :for="`${s.ch}_code`" class="label">{{ s.label }}<span class="text-danger ms-0.5" aria-hidden="true">*</span></label>
              <input
                :id="`${s.ch}_code`"
                :value="codeOf(s.ch)"
                :name="codeKey(s.ch)"
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                class="input h-10 ltr text-start tracking-[0.4em] num"
                :class="forms[s.ch].errors.value[codeKey(s.ch)] && 'input-error'"
                :maxlength="RULES.codeLength"
                placeholder="000000"
                autocomplete="one-time-code"
                required
                :aria-invalid="!!forms[s.ch].errors.value[codeKey(s.ch)]"
                :aria-describedby="forms[s.ch].errors.value[codeKey(s.ch)] ? `${s.ch}_code-err` : undefined"
                dir="ltr"
                @input="onCode(s.ch, ($event.target as HTMLInputElement).value)"
                @blur="forms[s.ch].validateField(codeKey(s.ch))"
              >
              <div class="flex items-start justify-between gap-2">
                <p v-if="forms[s.ch].errors.value[codeKey(s.ch)]" :id="`${s.ch}_code-err`" class="error-text" role="alert">{{ forms[s.ch].errors.value[codeKey(s.ch)] }}</p><span v-else />
                <p class="help num shrink-0">{{ codeOf(s.ch).length }}/{{ RULES.codeLength }}</p>
              </div>
            </div>
            <div class="flex gap-2 sm:pt-[1.625rem]">
              <AppButton type="submit" class="h-10 flex-1 sm:flex-none" :loading="forms[s.ch].submitting.value">{{ s.submit }}</AppButton>
              <AppButton type="button" variant="ghost" class="h-10 flex-1 sm:flex-none num" :loading="sending === s.ch" :disabled="cooldown[s.ch] > 0" @click="send(s.ch)">
                {{ cooldown[s.ch] > 0 ? $t('notify.resendIn', { n: cooldown[s.ch] }) : $t('auth.verify.resend') }}
              </AppButton>
            </div>
          </div>
          <FormAlert :message="forms[s.ch].formError.value" />
        </form>
      </section>
      <AppButton block size="lg" :disabled="!auth.isVerified" @click="goHome">{{ $t('auth.verify.continue') }}</AppButton>
      <div v-if="canSkip" class="rounded-xl border border-dashed border-warning/50 bg-warning/5 p-3 text-center">
        <p class="text-xs text-muted mb-2">{{ $t('auth.verify.skipHint') }}</p>
        <AppButton type="button" variant="secondary" size="sm" @click="goHome">{{ $t('auth.verify.skip') }}</AppButton>
      </div>
    </div>
  </div>
</template>
