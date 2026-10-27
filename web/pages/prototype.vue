<script setup lang="ts">
/** Prototype screen map for the product tester — lists every screen with links and one-click role login (mock mode). */
import { toastApiError, toastError, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'default' })
const { t, tm, rt, locale } = useI18n()
useHead({ title: t('prototype.metaTitle') })
const auth = useAuthStore()
const router = useRouter()
const localePath = useLocalePath()
const { mock } = useApiBase()
const { label } = useEnumLabel()
const busy = ref<string | null>(null)
const roles = [
  { key: 'investor', email: 'investor@demo.sciis.sa', home: '/app/investor/dashboard' },
  { key: 'investorInst', email: 'familyoffice@demo.sciis.sa', home: '/app/investor/dashboard', twoFactor: true },
  { key: 'entrepreneur', email: 'entrepreneur@demo.sciis.sa', home: '/app/entrepreneur/dashboard' },
  { key: 'admin', email: 'admin@demo.sciis.sa', home: '/app/admin/dashboard', twoFactor: true },
  { key: 'reviewer', email: 'reviewer@demo.sciis.sa', home: '/app/admin/dashboard', twoFactor: true },
  { key: 'evaluator', email: 'evaluator1@demo.sciis.sa', home: '/app/admin/dashboard', twoFactor: true },
  { key: 'matching', email: 'matching@demo.sciis.sa', home: '/app/admin/dashboard', twoFactor: true },
  { key: 'membership', email: 'membership@demo.sciis.sa', home: '/app/admin/dashboard', twoFactor: true },
]
const loginAs = async (r: (typeof roles)[number]) => {
  busy.value = r.key
  try {
    if (auth.isAuthenticated) await auth.logout()
    const res = await auth.login(r.email, 'password')
    if (res.twoFactor) await auth.challenge2fa('123456')
    toastSuccess(t('notify.welcome', { name: auth.me?.name ? (locale.value === 'ar' ? `، ${auth.me.name}` : `, ${auth.me.name}`) : '' }))
    router.push(localePath(r.home))
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
const logout = async () => {
  if (!(await auth.logout())) return void toastError(t('notify.logoutFailed'))
  toastInfo(t('notify.loggedOut'))
}
const sections: { key: string; screens: { path: string; title: string; note?: string }[] }[] = [
  { key: 'public', screens: [
    { path: '/', title: t('nav.home'), note: '§7.1 / §10.2' }, { path: '/about', title: t('nav.about') }, { path: '/impact-investing', title: t('nav.impactInvesting') }, { path: '/for-investors', title: t('nav.forInvestors') }, { path: '/for-entrepreneurs', title: t('nav.forEntrepreneurs') }, { path: '/faq', title: t('nav.faq') }, { path: '/contact', title: t('nav.contact'), note: 'CMS-05' }, { path: '/legal/privacy', title: t('footer.privacy') }, { path: '/legal/terms', title: t('footer.terms') }, { path: '/legal/disclaimer', title: t('footer.disclaimer') },
  ] },
  { key: 'auth', screens: [
    { path: '/auth/login', title: t('auth.login.title'), note: 'ACC-01, 2FA' }, { path: '/auth/register', title: t('auth.register.title'), note: '§7.5 — 5 خطوات' }, { path: '/auth/register?type=entrepreneur_entity', title: `${t('auth.register.title')} (${label('userType', 'entrepreneur_entity')})` }, { path: '/auth/verify', title: t('auth.verify.title') }, { path: '/auth/forgot', title: t('auth.forgot.title') }, { path: '/auth/reset', title: t('auth.reset.title') },
  ] },
  { key: 'investor', screens: [
    { path: '/app/investor/dashboard', title: t('investor.dashboard.metaTitle'), note: '§7.4' }, { path: '/app/opportunities', title: t('investor.opportunities.title'), note: '§7.2' }, { path: '/app/opportunities/opp-sullam', title: `${t('investor.detail.metaTitle')} — سُلّم`, note: '§7.3' }, { path: '/app/opportunities/opp-rifq', title: `${t('investor.detail.metaTitle')} — رفق (مرتبط لمكتب آل سالم)` }, { path: '/app/investor/interests', title: t('investor.interests.title') }, { path: '/app/investor/nominations', title: t('investor.nominations.title') }, { path: '/app/investor/info-requests', title: t('investor.infoRequests.title') }, { path: '/app/investor/meetings', title: t('investor.meetings.title') }, { path: '/app/investor/saved', title: t('investor.saved.title') }, { path: '/app/investor/profile', title: t('investor.profile.title') },
  ] },
  { key: 'entrepreneur', screens: [
    { path: '/app/entrepreneur/dashboard', title: t('entrepreneur.dashboard.metaTitle'), note: '§7.6' }, { path: '/app/entrepreneur/opportunities/new', title: t('entrepreneur.form.newTitle') }, { path: '/app/entrepreneur/opportunities/opp-baseera/edit', title: `${t('entrepreneur.form.editTitle')} — بصيرة (مسودة)` }, { path: '/app/entrepreneur/opportunities/opp-sullam/edit', title: `${t('entrepreneur.form.editTitle')} — سُلّم (مؤهّل، أقسام مقفلة)` }, { path: '/app/entrepreneur/opportunities/opp-sullam/preview', title: t('entrepreneur.preview.metaTitle'), note: 'ENT-09' }, { path: '/app/entrepreneur/access-requests', title: t('entrepreneur.accessRequests.title') }, { path: '/app/entrepreneur/info-requests', title: t('entrepreneur.infoRequests.title') }, { path: '/app/entrepreneur/meetings', title: t('entrepreneur.meetings.title') },
  ] },
  { key: 'admin', screens: [
    { path: '/app/admin/dashboard', title: t('admin.dashboard.metaTitle'), note: '§7.7' }, { path: '/app/admin/opportunities', title: t('admin.opportunities.title') }, { path: '/app/admin/opportunities/opp-shakkin?tab=evaluation', title: 'ملف الفرصة — شكّن (تقييم مكتمل من مقيّمَين)' }, { path: '/app/admin/opportunities/opp-hasad?tab=completion', title: 'ملف الفرصة — حصاد (يحتاج استكمالًا)' }, { path: '/app/admin/opportunities/opp-dawwir', title: 'ملف الفرصة — دوّر (جديد)' }, { path: '/app/admin/memberships', title: t('admin.memberships.title') }, { path: '/app/admin/memberships/3', title: 'طلب عضوية — صندوق نماء (قيد المراجعة)' }, { path: '/app/admin/interests', title: t('admin.interests.title') }, { path: '/app/admin/info-requests', title: t('admin.infoRequests.title') }, { path: '/app/admin/meetings', title: t('admin.meetings.title') }, { path: '/app/admin/users', title: t('admin.users.title') }, { path: '/app/admin/settings', title: t('admin.settings.title') }, { path: '/app/admin/evaluation-criteria', title: t('admin.criteria.title') }, { path: '/app/admin/reports', title: t('admin.reports.title') }, { path: '/app/admin/audit-logs', title: t('admin.audit.title') }, { path: '/app/admin/content', title: t('admin.content.title') },
  ] },
]
const howTo = computed(() => (tm('prototype.howToSteps') as string[]).map((s) => rt(s)))
const roleHint: Record<string, string> = { investor: 'investor', investorInst: 'investor', entrepreneur: 'entrepreneur', admin: 'admin', reviewer: 'admin', evaluator: 'admin', matching: 'admin', membership: 'admin' }
</script>

<template>
  <div class="container-x py-10">
    <!-- design v3 living styleguide (both palettes), then the screen map -->
    <DesignStyleguide />
    <PageHeader :title="$t('prototype.title')" :sub="$t('prototype.sub')">
      <template #actions><AppBadge kind="generic" :value="mock ? 'active' : 'suspended'" :label="mock ? $t('prototype.mockOn') : $t('prototype.mockOff')" size="md" /></template>
    </PageHeader>
    <div class="grid lg:grid-cols-[1fr_320px] gap-6 items-start">
      <div class="space-y-6">
        <SectionCard :title="$t('prototype.loginAs')" :sub="`${$t('prototype.currentUser')}: ${auth.me?.name ?? $t('prototype.notLoggedIn')}`">
          <div class="flex flex-wrap gap-2">
            <AppButton v-for="r in roles" :key="r.key" variant="secondary" size="sm" :loading="busy === r.key" @click="loginAs(r)">{{ $t(`auth.login.demoRoles.${r.key}`) }}<span v-if="r.twoFactor" class="text-xs text-muted">2FA</span></AppButton>
            <AppButton v-if="auth.isAuthenticated" variant="ghost" size="sm" @click="logout">{{ $t('common.logout') }}</AppButton>
          </div>
        </SectionCard>
        <SectionCard v-for="s in sections" :key="s.key" :title="$t(`prototype.sections.${s.key}`)" flush>
          <ul class="divide-y divide-border">
            <li v-for="sc in s.screens" :key="sc.path" class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
              <div class="min-w-0"><NuxtLink :to="localePath(sc.path)" class="font-medium text-ink hover:text-accent-text">{{ sc.title }}</NuxtLink><span v-if="sc.note" class="text-xs text-muted ms-2">{{ sc.note }}</span><span class="block text-xs text-muted ltr text-start num">{{ sc.path }}</span></div>
              <div class="flex items-center gap-2 shrink-0"><span v-if="s.key !== 'public' && s.key !== 'auth'" class="text-xs rounded-full border border-ink/15 bg-ink/5 px-2 py-0.5 text-muted">{{ $t(`auth.login.demoRoles.${s.key === 'investor' ? 'investor' : s.key === 'entrepreneur' ? 'entrepreneur' : 'admin'}`) }}</span><AppButton size="sm" variant="outline" :to="sc.path">{{ $t('common.view') }}</AppButton></div>
            </li>
          </ul>
        </SectionCard>
      </div>
      <aside class="space-y-4 lg:sticky lg:top-24">
        <div class="card card-pad">
          <h2 class="card-title">{{ $t('prototype.howTo') }}</h2>
          <ol class="mt-3 space-y-2 text-sm list-decimal ps-5"><li v-for="h in howTo" :key="h" class="leading-6">{{ h }}</li></ol>
        </div>
        <div class="card card-pad text-sm">
          <h2 class="card-title">{{ $t('auth.login.demoAccounts') }}</h2>
          <ul class="mt-2 space-y-1 text-xs text-muted"><li v-for="r in roles" :key="r.email" class="flex justify-between gap-2"><span>{{ $t(`auth.login.demoRoles.${r.key}`) }}</span><code class="ltr">{{ r.email }}</code></li></ul>
          <p class="mt-2 text-xs text-muted">{{ $t('auth.login.demoHint') }} · 2FA: 123456</p>
        </div>
        <InlineAlert tone="warning">{{ $t('app.demoBanner') }}</InlineAlert>
      </aside>
    </div>
  </div>
</template>
