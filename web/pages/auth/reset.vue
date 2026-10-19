<script setup lang="ts">
import { z } from 'zod'
import { makeSchemas } from '~/utils/validators'
definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t } = useI18n()
useHead({ title: t('auth.reset.metaTitle') })
const api = useApi()
const route = useRoute()
const S = makeSchemas(t)
const schema = z.object({ email: S.email, password: S.password, password_confirmation: z.string() }).refine((v) => v.password === v.password_confirmation, { message: t('validation.passwordMatch'), path: ['password_confirmation'] })
const form = useZodForm(schema, { email: (route.query.email as string) ?? '', password: '', password_confirmation: '' })
const done = ref(false)
const submit = () => form.submit(async (v) => { await api.post('/auth/reset-password', { ...v, token: route.query.token ?? 'mock' }); done.value = true })
</script>

<template>
  <div class="card card-pad">
    <h1 class="text-2xl font-semibold">{{ $t('auth.reset.title') }}</h1>
    <InlineAlert v-if="done" tone="success" class="mt-6">{{ $t('auth.reset.done') }} <NuxtLink :to="$localePath('/auth/login')" class="link">{{ $t('nav.login') }}</NuxtLink></InlineAlert>
    <form v-else class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required ltr />
      <FormField v-model="form.values.password" name="password" type="password" :label="$t('common.password')" :error="form.errors.value.password" required ltr autocomplete="new-password" />
      <FormField v-model="form.values.password_confirmation" name="password_confirmation" type="password" :label="$t('common.passwordConfirm')" :error="form.errors.value.password_confirmation" required ltr autocomplete="new-password" />
      <AppButton type="submit" block size="lg" :loading="form.submitting.value">{{ $t('auth.reset.submit') }}</AppButton>
    </form>
  </div>
</template>
