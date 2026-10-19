// Verifies (1) ar.json and en.json have identical key sets, (2) every static `t('…')` / `$t('…')` key used in
// components/pages/layouts/composables exists in ar.json. Run: npm run i18n:check
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const root = new URL('..', import.meta.url).pathname
const ar = JSON.parse(readFileSync(join(root, 'locales/ar.json'), 'utf8'))
const en = JSON.parse(readFileSync(join(root, 'locales/en.json'), 'utf8'))
const flat = (o, p = '') => Object.entries(o).flatMap(([k, v]) => (v && typeof v === 'object' && !Array.isArray(v) ? flat(v, p + k + '.') : [p + k]))
const arKeys = new Set(flat(ar))
const enKeys = new Set(flat(en))
const missingEn = [...arKeys].filter((k) => !enKeys.has(k))
const missingAr = [...enKeys].filter((k) => !arKeys.has(k))

const files = []
const walk = (d) => readdirSync(d).forEach((f) => { const p = join(d, f); statSync(p).isDirectory() ? walk(p) : /\.(vue|ts)$/.test(f) && files.push(p) })
;['components', 'pages', 'layouts', 'composables', 'middleware', 'app.vue', 'error.vue'].forEach((d) => { const p = join(root, d); statSync(p).isDirectory() ? walk(p) : files.push(p) })
const used = new Map()
const re = /\$?\bt[me]?\((['"`])([a-zA-Z0-9_.]+)\1/g
for (const f of files) {
  const src = readFileSync(f, 'utf8')
  let m
  while ((m = re.exec(src))) used.set(m[2], f)
}
const hasKey = (k) => arKeys.has(k) || [...arKeys].some((x) => x.startsWith(k + '.')) || k.split('.').reduce((o, s) => (o && typeof o === 'object' ? o[s] : undefined), ar) !== undefined
const unknown = [...used.keys()].filter((k) => !hasKey(k))

console.log(`ar: ${arKeys.size} keys · en: ${enKeys.size} keys · used static keys: ${used.size}`)
if (missingEn.length) console.log('Missing in en.json:', missingEn)
if (missingAr.length) console.log('Missing in ar.json:', missingAr)
if (unknown.length) console.log('Used but undefined:', unknown.map((k) => `${k} (${used.get(k).replace(root, '')})`))
if (missingEn.length || missingAr.length || unknown.length) process.exit(1)
console.log('i18n OK')
