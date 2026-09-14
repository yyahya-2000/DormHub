import fontUrl from '@/assets/roboto-cyrillic.ttf?url'

/**
 * The sheet of paper an issued account is handed over on: name, login,
 * password, dormitory, role.
 *
 * FR-42 returns the generated password once and never again, so the office has
 * one chance to put it on paper. The file is drawn here rather than on the
 * server: the password would otherwise travel a second time, and a render
 * route would be a route that answers with a password.
 *
 * The standard fonts of jsPDF are WinAnsi and have no Cyrillic glyphs — a
 * Russian name printed with them comes out blank — so a subset of Roboto is
 * embedded with the document. It is fetched at the moment the button is
 * pressed, along with jsPDF itself, and neither is in the bundle the sign-in
 * screen loads.
 */

/** The five facts on the sheet. Nothing is stored; the password lives in the caller's state. */
export type AccountSheet = {
  fullName: string
  login: string
  password: string
  building: string
  role: string
}

/** The same five, in the reader's language, plus the heading and the file name. */
export type AccountSheetLabels = {
  title: string
  fullName: string
  login: string
  password: string
  building: string
  role: string
  fileName: string
}

const FONT_FILE = 'roboto-cyrillic.ttf'
const FONT_NAME = 'Roboto'

let fontBase64: string | null = null

/**
 * The font as jsPDF's virtual file system wants it: base64.
 *
 * Read in chunks rather than spread into `String.fromCharCode` — 56 kB of
 * arguments at once overflows the call stack in every browser.
 */
async function loadFont(): Promise<string> {
  if (fontBase64 !== null) {
    return fontBase64
  }
  const response = await fetch(fontUrl)
  const bytes = new Uint8Array(await response.arrayBuffer())
  let binary = ''
  for (let offset = 0; offset < bytes.length; offset += 8192) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 8192))
  }
  fontBase64 = btoa(binary)
  return fontBase64
}

/** Builds the sheet and hands it to the browser to save. */
export async function downloadAccountSheet(
  sheet: AccountSheet,
  labels: AccountSheetLabels,
): Promise<void> {
  const [{ jsPDF }, font] = await Promise.all([import('jspdf'), loadFont()])

  const doc = new jsPDF({ unit: 'mm', format: 'a4' })
  doc.addFileToVFS(FONT_FILE, font)
  doc.addFont(FONT_FILE, FONT_NAME, 'normal')
  doc.setFont(FONT_NAME, 'normal')

  const left = 20
  doc.setFontSize(18)
  doc.text(labels.title, left, 28)

  const rows: [string, string][] = [
    [labels.fullName, sheet.fullName],
    [labels.login, sheet.login],
    [labels.password, sheet.password],
    [labels.building, sheet.building],
    [labels.role, sheet.role],
  ]

  let y = 46
  for (const [label, value] of rows) {
    doc.setFontSize(10)
    doc.setTextColor(110)
    doc.text(label, left, y)

    doc.setFontSize(15)
    doc.setTextColor(20)
    // Long names wrap instead of running off the right edge of the page.
    const lines = doc.splitTextToSize(value, 170) as string[]
    doc.text(lines, left, y + 7)

    const height = 14 + lines.length * 7
    doc.setDrawColor(210)
    doc.line(left, y + height - 5, left + 170, y + height - 5)
    y += height
  }

  doc.save(labels.fileName)
}
