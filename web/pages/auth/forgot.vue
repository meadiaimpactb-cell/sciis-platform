<script setup lang="ts">
import { z } from 'zod'
import { makeSchemas } from '~/utils/validators'
definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t } = useI18n()
useHead({ title: t('auth.forgot.metaTitle') })
const api = useApi()
const S = makeSchemas(t)
const form = useZodForm(z.object({ email: S.email }), { email: '' })
const sent = ref(false)
const submit = () => form.submit(async (v) => { await api.post('/auth/forgot-password', v); sent.value = true })
</script>

<template>
  <div class="card card-pad">
    <h1 class="text-2xl font-semibold">{{ $t('auth.forgot.title') }}</h1>
    <p class="text-muted mt-1">{{ $t('auth.forgot.sub') }}</p>
    <InlineAlert v-if="sent" tone="success" class="mt-6">{{ $t('auth.forgot.sent') }}</InlineAlert>
    <form v-else class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required ltr autocomplete="email" />
      <AppButton type="submit" block size="lg" :loading="form.submitting.value">{{ $t('auth.forgot.submit') }}</AppButton>
    </form>
    <p class="mt-5 text-sm text-center"><NuxtLink :to="$localePath('/auth/login')" class="link">{{ $t('nav.login') }}</NuxtLink></p>
  </div>
</template>
