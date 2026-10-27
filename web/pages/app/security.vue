<script setup lang="ts">
/**
 * Security — TOTP two-factor authentication (ACC-05) for EVERY user (mandatory for staff).
 *  POST /auth/2fa/enable  → { data: { secret, otpauth_url, qr } }   (server QR = SVG data URI; local QR fallback)
 *  POST /auth/2fa/confirm {code} → { data: { two_factor_enabled, recovery_codes[8] } }  (shown once: copy / download)
 *  POST /auth/2fa/disable {password, code} → { message }   (code = TOTP or recovery code; 403 for staff when enforced)
 */
import { z } from 'zod'
import { codeField, currentPasswordField, RULES, toLatinDigits, twoFactorCodeField } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastInfo, toastSuccess } from '~/utils/notify'
import { qrSvg } from '~/utils/qr'
definePageMeta({ layout: 'portal', middleware: ['auth'] })
const { t } = useI18n()
useHead({ title: t('security.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const { mock } = useApiBase()

const enrol = ref<{ secret: string; otpauth_url: string; qr?: string | null } | null>(null)
/** Server-rendered QR (data URI) when the API sends one, else rendered locally from the otpauth URL. */
const localQr = computed(() => (enrol.value && !enrol.value.qr ? qrSvg(enrol.value.otpauth_url, { ecl: 'M' }) : ''))
const starting = ref(false)
const recoveryCodes = ref<string[] | null>(null)
const copied = ref(false)

// ---- enable (step 1: secret + QR, step 2: confirm code) ----
const confirmForm = useZodForm(z.object({ totp_code: codeField }), { totp_code: '' }, { aliases: { code: 'totp_code' } })
const start = async () => {
  starting.value = true
  try {
    enrol.value = (await api.post<{ data: { secret: string; otpauth_url: string; qr?: string | null } }>('/auth/2fa/enable')).data
    confirmForm.reset()
  } catch (e) {
    toastApiError(e)
  } finally {
    starting.value = false
  }
}
const confirm = () =>
  confirmForm.submit(async (v) => {
    const r = await api.post<{ data: { two_factor_enabled?: boolean; recovery_codes?: string[] } }>('/auth/2fa/confirm', { code: v.totp_code })
    recoveryCodes.value = r.data.recovery_codes ?? []
    enrol.value = null
    await auth.fetchMe(true)
    if (auth.me) auth.me.two_factor_passed = true
    toastSuccess(t('notify.twoFactorEnabled'))
  })
const cancelEnrol = () => { enrol.value = null; confirmForm.reset() }

// ---- disable: confirmation first, then current password + code ----
const disableOpen = ref(false)
const disableForm = useZodForm(z.object({ current_password: currentPasswordField, disable_code: twoFactorCodeField }), { current_password: '', disable_code: '' }, { aliases: { password: 'current_password', code: 'disable_code' } })
const askDisable = async () => {
  if (!(await confirmDialog(t('notify.twoFactorDisableTitle'), t('notify.twoFactorDisableText'), t('notify.twoFactorDisableConfirm')))) return
  disableForm.reset()
  disableOpen.value = true
}
const disable = () =>
  disableForm.submit(async (v) => {
    await api.post('/auth/2fa/disable', { password: v.current_password, code: v.disable_code.toUpperCase() })
    disableOpen.value = false
    recoveryCodes.value = null
    await auth.fetchMe(true)
    toastSuccess(t('notify.twoFactorDisabled'))
  })

// ---- recovery codes: copy / download ----
const copy = async (text: string, msg = t('notify.copied')) => {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = true
    toastSuccess(msg)
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    toastError(t('security.copyFailed'))
  }
}
const downloadCodes = () => {
  if (!recoveryCodes.value?.length) return
  const body = `${t('security.recoveryTitle')} — ${auth.me?.email ?? ''}\n${new Date().toISOString().slice(0, 10)}\n\n${recoveryCodes.value.join('\n')}\n`
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([body], { type: 'text/plain;charset=utf-8' }))
  a.download = 'sciis-recovery-codes.txt'
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  toastSuccess(t('notify.downloaded'))
}
const doneWithCodes = () => { recoveryCodes.value = null; toastInfo(t('security.codesHidden')) }

const mandatory = computed(() => auth.isStaff)
// Reached via the API's 403 `two_factor_enrolment_required` (staff without TOTP) → start right away.
onMounted(() => { if (!auth.me?.two_factor_enabled && (route.query.enrol === '1' || mandatory.value)) start() })
</script>

<template>
  <div>
    <PageHeader :title="$t('security.title')" :sub="$t('security.sub')" />
    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
      <div class="space-y-5">
        <InlineAlert v-if="mandatory && !auth.me?.two_factor_enabled" tone="warning" :title="$t('security.mandatoryTitle')">{{ $t('security.mandatoryText') }}</InlineAlert>

        <!-- status -->
        <SectionCard :title="$t('security.twoFactor')">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-sm text-muted">{{ $t('security.status') }}</p>
              <AppBadge kind="generic" :value="auth.me?.two_factor_enabled ? 'active' : 'suspended'" :label="auth.me?.two_factor_enabled ? $t('security.on') : $t('security.off')" size="md" class="mt-1" />
            </div>
            <AppButton v-if="!auth.me?.two_factor_enabled && !enrol" :loading="starting" @click="start">{{ $t('security.enable') }}</AppButton>
          </div>
        </SectionCard>

        <!-- step 1+2: QR + confirm -->
        <SectionCard v-if="enrol" :title="$t('security.setupTitle')">
          <ol class="list-decimal ps-5 text-sm leading-7 text-muted">
            <li>{{ $t('security.step1') }}</li>
            <li>{{ $t('security.step2') }}</li>
            <li>{{ $t('security.step3') }}</li>
          </ol>
          <div class="mt-4 grid sm:grid-cols-[200px_1fr] gap-5 items-start">
            <img v-if="enrol.qr" :src="enrol.qr" width="200" height="200" :alt="$t('security.qrAlt')" data-theme="light" class="rounded-xl border border-border bg-surface p-2 w-[200px] h-[200px] mx-auto sm:mx-0">
            <!-- eslint-disable-next-line vue/no-v-html -- SVG generated locally from the otpauth URL -->
            <div v-else data-theme="light" class="rounded-xl border border-border bg-surface p-2 w-[200px] h-[200px] mx-auto sm:mx-0 [&>svg]:w-full [&>svg]:h-full" role="img" :aria-label="$t('security.qrAlt')" v-html="localQr" />
            <div class="min-w-0 space-y-3">
              <div>
                <p class="text-xs text-muted">{{ $t('security.secret') }}</p>
                <div class="mt-1 flex items-center gap-2">
                  <code class="ltr text-start text-sm font-mono tracking-widest break-all rounded-xl border border-border bg-bg px-3 py-2 text-ink flex-1" dir="ltr">{{ enrol.secret }}</code>
                  <AppButton size="sm" variant="ghost" @click="copy(enrol.secret)">{{ copied ? $t('common.copied') : $t('common.copy') }}</AppButton>
                </div>
              </div>
              <form class="space-y-3" novalidate @submit.prevent="confirm">
                <div>
                  <label for="totp_code" class="label">{{ $t('security.codeLabel') }}<span class="text-danger ms-0.5" aria-hidden="true">*</span></label>
                  <input
                    id="totp_code"
                    :value="confirmForm.values.totp_code"
                    name="totp_code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="input ltr text-start tracking-[0.4em] num"
                    :class="confirmForm.errors.value.totp_code && 'input-error'"
                    :maxlength="RULES.codeLength"
                    placeholder="000000"
                    required
                    :aria-invalid="!!confirmForm.errors.value.totp_code"
                    :aria-describedby="confirmForm.errors.value.totp_code ? 'totp_code-err' : undefined"
                    dir="ltr"
                    @input="confirmForm.values.totp_code = toLatinDigits(($event.target as HTMLInputElement).value).slice(0, RULES.codeLength)"
                    @blur="confirmForm.validateField('totp_code')"
                  >
                  <p v-if="confirmForm.errors.value.totp_code" id="totp_code-err" class="error-text" role="alert">{{ confirmForm.errors.value.totp_code }}</p>
                </div>
                <p v-if="mock" class="text-xs text-muted">{{ $t('auth.verify.demoHint') }}</p>
                <FormAlert :message="confirmForm.formError.value" />
                <div class="flex gap-2"><AppButton type="submit" :loading="confirmForm.submitting.value">{{ $t('security.confirm') }}</AppButton><AppButton type="button" variant="ghost" @click="cancelEnrol">{{ $t('common.cancel') }}</AppButton></div>
              </form>
            </div>
          </div>
        </SectionCard>

        <!-- recovery codes: shown once -->
        <SectionCard v-if="recoveryCodes" :title="$t('security.recoveryTitle')">
          <InlineAlert tone="warning">{{ $t('security.recoveryText') }}</InlineAlert>
          <ul class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2" dir="ltr">
            <li v-for="c in recoveryCodes" :key="c" class="ltr text-center font-mono text-sm text-ink rounded-xl bg-bg border border-border px-2 py-1.5 select-all">{{ c }}</li>
          </ul>
          <div class="mt-3 flex flex-wrap gap-2">
            <AppButton size="sm" variant="secondary" @click="copy(recoveryCodes.join('\n'))">{{ $t('security.copyCodes') }}</AppButton>
            <AppButton size="sm" variant="secondary" @click="downloadCodes">{{ $t('security.downloadCodes') }}</AppButton>
            <AppButton size="sm" variant="ghost" @click="doneWithCodes">{{ $t('security.savedCodes') }}</AppButton>
          </div>
        </SectionCard>

        <!-- disable -->
        <SectionCard v-if="auth.me?.two_factor_enabled" :title="$t('security.disableTitle')" :sub="mandatory ? $t('security.mandatoryText') : $t('security.disableText')">
          <template v-if="!mandatory">
            <AppButton v-if="!disableOpen" variant="danger" @click="askDisable">{{ $t('security.disable') }}</AppButton>
            <form v-else class="grid gap-3 sm:grid-cols-2 sm:items-start" novalidate @submit.prevent="disable">
              <FormField v-model="disableForm.values.current_password" name="current_password" type="password" :label="$t('account.password.current')" :error="disableForm.errors.value.current_password" required autocomplete="current-password" ltr />
              <FormField :model-value="disableForm.values.disable_code" name="disable_code" :label="$t('auth.login.twoFactorCode')" :help="$t('auth.login.twoFactorRecoveryHint')" :error="disableForm.errors.value.disable_code" required ltr :maxlength="RULES.recoveryCodeLength" placeholder="000000" autocomplete="one-time-code" @update:model-value="disableForm.values.disable_code = toLatinDigits(String($event ?? ''))" />
              <FormAlert :message="disableForm.formError.value" class="sm:col-span-2" />
              <div class="sm:col-span-2 flex flex-wrap gap-2">
                <AppButton type="submit" variant="danger" :loading="disableForm.submitting.value">{{ $t('security.disable') }}</AppButton>
                <AppButton type="button" variant="ghost" @click="disableOpen = false">{{ $t('common.cancel') }}</AppButton>
              </div>
            </form>
          </template>
        </SectionCard>
      </div>

      <aside class="card card-pad text-sm leading-7 text-muted">
        <h3 class="card-title">{{ $t('security.whyTitle') }}</h3>
        <p class="mt-1">{{ $t('security.whyText') }}</p>
        <p class="mt-3 text-xs">{{ $t('security.apps') }}</p>
      </aside>
    </div>
  </div>
</template>
