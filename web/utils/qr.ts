/**
 * Minimal, dependency-free QR Code encoder (byte mode, versions 1–40, ECC L/M) used to render the
 * 2FA `otpauth://` URL as an inline SVG (ACC-05). Follows ISO/IEC 18004 — structure adapted from
 * Project Nayuki's reference algorithm (MIT): segments → codewords → Reed-Solomon → placement → mask.
 */

export type QrEcc = 'L' | 'M'

const ECC_FORMAT_BITS: Record<QrEcc, number> = { L: 1, M: 0 }

// Index = version (0 unused).
const ECC_CODEWORDS_PER_BLOCK: Record<QrEcc, number[]> = {
  L: [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
  M: [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
}
const NUM_ERROR_CORRECTION_BLOCKS: Record<QrEcc, number[]> = {
  L: [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
  M: [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
}

const numRawDataModules = (ver: number): number => {
  let result = (16 * ver + 128) * ver + 64
  if (ver >= 2) {
    const numAlign = Math.floor(ver / 7) + 2
    result -= (25 * numAlign - 10) * numAlign - 55
    if (ver >= 7) result -= 36
  }
  return result
}
const numDataCodewords = (ver: number, ecl: QrEcc): number => Math.floor(numRawDataModules(ver) / 8) - ECC_CODEWORDS_PER_BLOCK[ecl][ver] * NUM_ERROR_CORRECTION_BLOCKS[ecl][ver]
const charCountBits = (ver: number): number => (ver <= 9 ? 8 : 16)

// ---- GF(2^8) Reed-Solomon (polynomial 0x11D)
const gfMul = (x: number, y: number): number => {
  let z = 0
  for (let i = 7; i >= 0; i--) {
    z = (z << 1) ^ ((z >>> 7) * 0x11d)
    z ^= ((y >>> i) & 1) * x
  }
  return z & 0xff
}
const rsDivisor = (degree: number): number[] => {
  const result = new Array<number>(degree).fill(0)
  result[degree - 1] = 1
  let root = 1
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < result.length; j++) {
      result[j] = gfMul(result[j], root)
      if (j + 1 < result.length) result[j] ^= result[j + 1]
    }
    root = gfMul(root, 0x02)
  }
  return result
}
const rsRemainder = (data: number[], divisor: number[]): number[] => {
  const result = divisor.map(() => 0)
  for (const b of data) {
    const factor = b ^ (result.shift() as number)
    result.push(0)
    divisor.forEach((coef, i) => (result[i] ^= gfMul(coef, factor)))
  }
  return result
}

const getBit = (x: number, i: number): boolean => ((x >>> i) & 1) !== 0

class BitBuffer {
  bits: number[] = []
  append(val: number, len: number) {
    for (let i = len - 1; i >= 0; i--) this.bits.push((val >>> i) & 1)
  }
}

export interface QrMatrix {
  size: number
  /** modules[y][x] → dark */
  modules: boolean[][]
}

const utf8Bytes = (text: string): number[] => Array.from(new TextEncoder().encode(text))

/** Encodes `text` (UTF-8, byte mode) and returns the module matrix. */
export const encodeQr = (text: string, ecl: QrEcc = 'M'): QrMatrix => {
  const bytes = utf8Bytes(text)

  // 1. smallest version that fits
  let version = 1
  for (;;) {
    const capacity = numDataCodewords(version, ecl) * 8
    if (4 + charCountBits(version) + bytes.length * 8 <= capacity) break
    if (version >= 40) throw new Error('QR: data too long')
    version++
  }
  const dataCapacityBits = numDataCodewords(version, ecl) * 8

  // 2. bit stream: mode (0100), count, data, terminator, byte-align, pad bytes
  const bb = new BitBuffer()
  bb.append(0x4, 4)
  bb.append(bytes.length, charCountBits(version))
  for (const b of bytes) bb.append(b, 8)
  bb.append(0, Math.min(4, dataCapacityBits - bb.bits.length))
  bb.append(0, (8 - (bb.bits.length % 8)) % 8)
  for (let pad = 0xec; bb.bits.length < dataCapacityBits; pad ^= 0xec ^ 0x11) bb.append(pad, 8)
  const dataCodewords: number[] = []
  bb.bits.forEach((bit, i) => (dataCodewords[i >>> 3] = (dataCodewords[i >>> 3] ?? 0) | (bit << (7 - (i & 7)))))

  // 3. ECC blocks + interleave
  const numBlocks = NUM_ERROR_CORRECTION_BLOCKS[ecl][version]
  const blockEccLen = ECC_CODEWORDS_PER_BLOCK[ecl][version]
  const rawCodewords = Math.floor(numRawDataModules(version) / 8)
  const numShortBlocks = numBlocks - (rawCodewords % numBlocks)
  const shortBlockLen = Math.floor(rawCodewords / numBlocks)
  const blocks: number[][] = []
  const divisor = rsDivisor(blockEccLen)
  for (let i = 0, k = 0; i < numBlocks; i++) {
    const datLen = shortBlockLen - blockEccLen + (i < numShortBlocks ? 0 : 1)
    const dat = dataCodewords.slice(k, k + datLen)
    k += datLen
    const ecc = rsRemainder(dat, divisor)
    if (i < numShortBlocks) dat.push(0)
    blocks.push(dat.concat(ecc))
  }
  const allCodewords: number[] = []
  for (let i = 0; i < blocks[0].length; i++) {
    blocks.forEach((block, j) => {
      if (i !== shortBlockLen - blockEccLen || j >= numShortBlocks) allCodewords.push(block[i])
    })
  }

  // 4. matrix + function patterns
  const size = version * 4 + 17
  const modules: boolean[][] = Array.from({ length: size }, () => new Array<boolean>(size).fill(false))
  const isFunction: boolean[][] = Array.from({ length: size }, () => new Array<boolean>(size).fill(false))
  const setFn = (x: number, y: number, dark: boolean) => {
    modules[y][x] = dark
    isFunction[y][x] = true
  }

  for (let i = 0; i < size; i++) {
    setFn(6, i, i % 2 === 0)
    setFn(i, 6, i % 2 === 0)
  }
  const drawFinder = (cx: number, cy: number) => {
    for (let dy = -4; dy <= 4; dy++) {
      for (let dx = -4; dx <= 4; dx++) {
        const dist = Math.max(Math.abs(dx), Math.abs(dy))
        const x = cx + dx
        const y = cy + dy
        if (x >= 0 && x < size && y >= 0 && y < size) setFn(x, y, dist !== 2 && dist !== 4)
      }
    }
  }
  drawFinder(3, 3)
  drawFinder(size - 4, 3)
  drawFinder(3, size - 4)

  const alignPositions = (): number[] => {
    if (version === 1) return []
    const numAlign = Math.floor(version / 7) + 2
    const step = version === 32 ? 26 : Math.ceil((version * 4 + 4) / (numAlign * 2 - 2)) * 2
    const result = [6]
    for (let pos = size - 7; result.length < numAlign; pos -= step) result.splice(1, 0, pos)
    return result
  }
  const aligns = alignPositions()
  const numAlign = aligns.length
  for (let i = 0; i < numAlign; i++) {
    for (let j = 0; j < numAlign; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === numAlign - 1) || (i === numAlign - 1 && j === 0)) continue
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) setFn(aligns[i] + dx, aligns[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1)
    }
  }

  const drawFormatBits = (mask: number) => {
    const data = (ECC_FORMAT_BITS[ecl] << 3) | mask
    let rem = data
    for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537)
    const bits = ((data << 10) | rem) ^ 0x5412
    for (let i = 0; i <= 5; i++) setFn(8, i, getBit(bits, i))
    setFn(8, 7, getBit(bits, 6))
    setFn(8, 8, getBit(bits, 7))
    setFn(7, 8, getBit(bits, 8))
    for (let i = 9; i < 15; i++) setFn(14 - i, 8, getBit(bits, i))
    for (let i = 0; i < 8; i++) setFn(size - 1 - i, 8, getBit(bits, i))
    for (let i = 8; i < 15; i++) setFn(8, size - 15 + i, getBit(bits, i))
    setFn(8, size - 8, true)
  }
  drawFormatBits(0)

  if (version >= 7) {
    let rem = version
    for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25)
    const bits = (version << 12) | rem
    for (let i = 0; i < 18; i++) {
      const bit = getBit(bits, i)
      const a = size - 11 + (i % 3)
      const b = Math.floor(i / 3)
      setFn(a, b, bit)
      setFn(b, a, bit)
    }
  }

  // 5. codewords in the zig-zag
  let bitIndex = 0
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5
    for (let vert = 0; vert < size; vert++) {
      for (let j = 0; j < 2; j++) {
        const x = right - j
        const upward = ((right + 1) & 2) === 0
        const y = upward ? size - 1 - vert : vert
        if (!isFunction[y][x] && bitIndex < allCodewords.length * 8) {
          modules[y][x] = getBit(allCodewords[bitIndex >>> 3], 7 - (bitIndex & 7))
          bitIndex++
        }
      }
    }
  }

  // 6. mask selection (lowest penalty)
  const maskBit = (mask: number, x: number, y: number): boolean => {
    switch (mask) {
      case 0: return (x + y) % 2 === 0
      case 1: return y % 2 === 0
      case 2: return x % 3 === 0
      case 3: return (x + y) % 3 === 0
      case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0
      case 5: return ((x * y) % 2) + ((x * y) % 3) === 0
      case 6: return (((x * y) % 2) + ((x * y) % 3)) % 2 === 0
      default: return (((x + y) % 2) + ((x * y) % 3)) % 2 === 0
    }
  }
  const applyMask = (mask: number) => {
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!isFunction[y][x] && maskBit(mask, x, y)) modules[y][x] = !modules[y][x]
  }
  // Finder-like pattern detection (1:1:3:1:1 with a 4-module light margin) on a run-length history.
  const addHistory = (run: number, h: number[]) => {
    if (h[0] === 0) run += size
    h.pop()
    h.unshift(run)
  }
  const countPatterns = (h: number[]): number => {
    const n = h[1]
    const core = n > 0 && h[2] === n && h[3] === n * 3 && h[4] === n && h[5] === n
    return (core && h[0] >= n * 4 && h[6] >= n ? 1 : 0) + (core && h[6] >= n * 4 && h[0] >= n ? 1 : 0)
  }
  const terminateAndCount = (runColor: boolean, runLength: number, h: number[]): number => {
    if (runColor) {
      addHistory(runLength, h)
      runLength = 0
    }
    addHistory(runLength + size, h)
    return countPatterns(h)
  }
  const penalty = (): number => {
    let score = 0
    const line = (get: (i: number) => boolean) => {
      let runColor = false
      let run = 0
      const h = [0, 0, 0, 0, 0, 0, 0]
      for (let i = 0; i < size; i++) {
        const v = get(i)
        if (v === runColor) {
          run++
          if (run === 5) score += 3
          else if (run > 5) score++
        } else {
          addHistory(run, h)
          if (!runColor) score += countPatterns(h) * 40
          runColor = v
          run = 1
        }
      }
      score += terminateAndCount(runColor, run, h) * 40
    }
    for (let y = 0; y < size; y++) line((x) => modules[y][x])
    for (let x = 0; x < size; x++) line((y) => modules[y][x])
    for (let y = 0; y < size - 1; y++) {
      for (let x = 0; x < size - 1; x++) {
        const c = modules[y][x]
        if (c === modules[y][x + 1] && c === modules[y + 1][x] && c === modules[y + 1][x + 1]) score += 3
      }
    }
    let dark = 0
    for (const row of modules) for (const m of row) if (m) dark++
    const total = size * size
    const k = Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1
    return score + Math.max(0, k) * 10
  }
  let best = 0
  let bestScore = Infinity
  for (let mask = 0; mask < 8; mask++) {
    applyMask(mask)
    drawFormatBits(mask)
    const s = penalty()
    if (s < bestScore) {
      bestScore = s
      best = mask
    }
    applyMask(mask)
  }
  applyMask(best)
  drawFormatBits(best)

  return { size, modules }
}

/** Renders the matrix as a standalone SVG string (4-module quiet zone, dark on light). */
export const qrSvg = (text: string, opts: { ecl?: QrEcc; border?: number; dark?: string; light?: string } = {}): string => {
  const { size, modules } = encodeQr(text, opts.ecl ?? 'M')
  const border = opts.border ?? 4
  const dim = size + border * 2
  let path = ''
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (modules[y][x]) path += `M${x + border} ${y + border}h1v1h-1z`
    }
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${dim} ${dim}" shape-rendering="crispEdges" role="img"><rect width="100%" height="100%" fill="${opts.light ?? '#fff'}"/><path d="${path}" fill="${opts.dark ?? '#000'}"/></svg>`
}
