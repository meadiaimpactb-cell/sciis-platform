/**
 * The home hero emblem (design v3) as an image source per theme. It is an <img> rather than inline SVG on purpose:
 * images count as "largest contentful paint" candidates, so the first screen's main visual is measured as soon as it
 * paints (inline SVG is ignored, and a late element such as the cookie notice would be reported instead).
 */
type EmblemTheme = 'dark' | 'light'

const COLORS: Record<EmblemTheme, { frame: string; frameFill: string; glow: string }> = {
  dark: { frame: '#FFFFFF', frameFill: '#203068', glow: '#7FD3C9' },
  light: { frame: '#203068', frameFill: '#FFFFFF', glow: '#58B0A8' },
}

export function heroEmblemSrc(theme: EmblemTheme): string {
  const c = COLORS[theme]
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 136"><defs>`
    + `<linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7FD3C9"/><stop offset="1" stop-color="#58B4A8"/></linearGradient>`
    + `<filter id="f" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="0" stdDeviation="3" flood-color="${c.glow}" flood-opacity=".55"/></filter>`
    + `</defs><rect x="4.5" y="4.5" width="91" height="127" fill="${c.frameFill}" fill-opacity=".55" stroke="${c.frame}" stroke-width="5"/>`
    + `<path d="M76 20A50 50 0 0 0 26 70H42A34 34 0 0 1 76 36Z" fill="url(#g)" filter="url(#f)"/>`
    + `<path d="M24 116A50 50 0 0 0 74 66H58A34 34 0 0 1 24 100Z" fill="${c.frame}"/></svg>`
  return `data:image/svg+xml,${encodeURIComponent(svg)}`
}
