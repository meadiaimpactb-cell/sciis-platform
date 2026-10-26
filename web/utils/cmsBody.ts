/**
 * CMS body rendering (CMS-02/03). Bodies arrive either as light Markdown (what the seeders and the
 * admin textarea produce) or as HTML. HTML is sanitized with a strict allowlist; Markdown is escaped first,
 * so the output is always safe for `v-html`.
 */
const ALLOWED_TAGS = new Set(['p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'u', 'a', 'blockquote', 'img', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure', 'figcaption', 'span', 'div'])
const SAFE_URL = /^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i

const escapeHtml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

export const isHtmlBody = (body: string) => /<\/?(p|h[1-6]|ul|ol|li|a|strong|em|br|blockquote|div|span|img|table)\b[^>]*>/i.test(body)

/** Allowlist sanitizer: drops dangerous blocks, unknown tags (keeping their text) and every attribute except safe href/src/alt/title. */
export const sanitizeHtml = (html: string): string => {
  let out = html.replace(/<!--[\s\S]*?-->/g, '').replace(/<(script|style|iframe|object|embed|form|input|textarea|select|button|svg|math|link|meta)\b[\s\S]*?(<\/\1\s*>|$)/gi, '')
  out = out.replace(/<(\/?)([a-z][a-z0-9]*)\b([^>]*)>/gi, (_m, slash: string, rawTag: string, rawAttrs: string) => {
    const tag = rawTag.toLowerCase() === 'h1' ? 'h2' : rawTag.toLowerCase()
    if (!ALLOWED_TAGS.has(tag)) return ''
    if (slash) return `</${tag}>`
    const attrs: string[] = []
    const re = /([a-z-]+)\s*=\s*("([^"]*)"|'([^']*)'|([^\s"'>]+))/gi
    let m: RegExpExecArray | null
    while ((m = re.exec(rawAttrs))) {
      const name = m[1].toLowerCase()
      const value = (m[3] ?? m[4] ?? m[5] ?? '').trim()
      if ((name === 'href' && tag === 'a') || (name === 'src' && tag === 'img')) {
        if (SAFE_URL.test(value)) attrs.push(`${name}="${escapeHtml(value)}"`)
      } else if (['alt', 'title', 'width', 'height'].includes(name)) {
        attrs.push(`${name}="${escapeHtml(value)}"`)
      }
    }
    if (tag === 'a' && attrs.some((a) => a.startsWith('href="http'))) attrs.push('target="_blank"', 'rel="noopener noreferrer"')
    if (tag === 'img') attrs.push('loading="lazy"', 'decoding="async"')
    return `<${tag}${attrs.length ? ' ' + attrs.join(' ') : ''}>`
  })
  return out
}

const inline = (s: string) =>
  escapeHtml(s)
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,;:!?]|$)/g, '$1<em>$2</em>')
    .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_m, alt: string, url: string) => (SAFE_URL.test(url) ? `<img src="${url}" alt="${alt}" loading="lazy" decoding="async">` : alt))
    .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_m, text: string, url: string) => (SAFE_URL.test(url) ? `<a href="${url}"${/^https?:/i.test(url) ? ' target="_blank" rel="noopener noreferrer"' : ''}>${text}</a>` : text))

/** Light Markdown → HTML (headings, lists, quotes, paragraphs, bold/italic/links/images). `#` maps to h2: the page already has its h1. */
export const markdownLite = (text: string): string => {
  const blocks = text.replace(/\r\n?/g, '\n').split(/\n{2,}/)
  return blocks
    .map((block) => {
      const lines = block.split('\n').filter((l) => l.trim() !== '')
      if (!lines.length) return ''
      if (lines.every((l) => /^\s*[-*•]\s+/.test(l))) return `<ul>${lines.map((l) => `<li>${inline(l.replace(/^\s*[-*•]\s+/, ''))}</li>`).join('')}</ul>`
      if (lines.every((l) => /^\s*\d+[.)]\s+/.test(l))) return `<ol>${lines.map((l) => `<li>${inline(l.replace(/^\s*\d+[.)]\s+/, ''))}</li>`).join('')}</ol>`
      if (lines.every((l) => /^\s*>\s?/.test(l))) return `<blockquote>${inline(lines.map((l) => l.replace(/^\s*>\s?/, '')).join(' '))}</blockquote>`
      return lines
        .map((l) => {
          const h = /^\s*(#{1,4})\s+(.+)$/.exec(l)
          if (h) return `<h${Math.min(4, h[1].length + 1)}>${inline(h[2])}</h${Math.min(4, h[1].length + 1)}>`
          if (/^\s*(---|\*\*\*)\s*$/.test(l)) return '<hr>'
          return `<p>${inline(l)}</p>`
        })
        .join('')
    })
    .join('')
}

/** Safe HTML for `v-html`. */
export const renderCmsBody = (body: string | null | undefined): string => {
  if (!body) return ''
  return isHtmlBody(body) ? sanitizeHtml(body) : markdownLite(body)
}

/** Plain-text excerpt (meta description / cards). */
export const cmsExcerpt = (body: string | null | undefined, max = 160): string => {
  if (!body) return ''
  const text = body
    .replace(/<[^>]+>/g, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/^\s*#{1,6}\s+/gm, '')
    .replace(/^\s*>\s?/gm, '')
    .replace(/[*_`]/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
  if (text.length <= max) return text
  const cut = text.slice(0, max)
  return `${cut.slice(0, Math.max(cut.lastIndexOf(' '), max - 20))}…`
}

/** First image URL inside a body (HTML or Markdown), if any. */
export const firstBodyImage = (body: string | null | undefined): string | null => {
  if (!body) return null
  const html = /<img[^>]+src\s*=\s*["']([^"']+)["']/i.exec(body)
  if (html && SAFE_URL.test(html[1])) return html[1]
  const md = /!\[[^\]]*\]\(([^)\s]+)\)/.exec(body)
  return md && SAFE_URL.test(md[1]) ? md[1] : null
}

/** Does the body carry the "pending legal approval" marker used by the seeders? */
export const isPendingLegal = (body: string | null | undefined) => !!body && /بانتظار الاعتماد القانوني|pending legal approval/i.test(body)
