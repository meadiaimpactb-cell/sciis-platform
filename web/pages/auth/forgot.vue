<script setup lang="ts">
/**
 * Forgot password (ACC-03). The answer never reveals whether the account exists: success, 404 and a 422 on the
 * e-mail all end with the same `notify.resetSent` toast. Only a client-side format error, a rate limit (429 →
 * FormAlert) or a network failure are shown as errors.
 */
import { z } from 'zod'
import { emailField, parseApiError } from '~/utils/validation'
import { toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t } = useI18n()
useHead({ title: t('auth.forgot.metaTitle') })
const api = useApi()
const form = useZodForm(z.object({ email: emailField }), { email: '' })
const sent = ref(false)
const submit = () => form.submit(async (v) => {
  try {
    await api.post('/auth/forgot-password', { email: v.email.trim().toLowerCase() })
  } catch (e) {
    const status = parseApiError(e).status
    if (status !== 404 && status !== 422) throw e
  }
  sent.value = true
  toastSuccess(t('notify.resetSent'))
})
</script>

<template>
  <div class="card card-pad">
    <h1 class="text-2xl font-semibold">{{ $t('auth.forgot.title') }}</h1>
    <p class="text-muted mt-1">{{ sent ? $t('auth.forgot.sent') : $t('auth.forgot.sub') }}</p>
    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required ltr autocomplete="email" />
      <FormAlert />
      <AppButton type="submit" block size="lg" :loading="form.submitting.value">{{ sent ? $t('auth.verify.resend') : $t('auth.forgot.submit') }}</AppButton>
    </form>
    <p class="mt-5 text-sm text-center"><NuxtLink :to="$localePath('/auth/login')" class="link">{{ $t('nav.login') }}</NuxtLink></p>
  </div>
</template>
