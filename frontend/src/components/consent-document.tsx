import { Fragment, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * The wording of a consent, put on the screen whole.
 *
 * Art. 9 part 1 of Federal Law No. 152-FZ wants consent informed, and the
 * contract takes that literally: `GET /consents/pending` returns the body and
 * not a link to it. This component is the other half of the same decision — the
 * text is laid out to be read, at the body size NFR-10 sets, and there is no
 * collapsing, no «show more» and no scroll box that could be scrolled past.
 *
 * **Why the Markdown is parsed here rather than by a library.** The bodies come
 * from files under version control in the backend and use five constructs: a
 * heading, a paragraph, a horizontal rule, a numbered list and `**bold**`. A
 * Markdown package would bring a parser, an HTML serialiser and then a
 * sanitiser to undo the serialiser, and the whole chain exists to render text
 * this application must never inject as markup in the first place. The parser
 * below produces React elements directly: there is no HTML string anywhere in
 * it, so `dangerouslySetInnerHTML` never appears and the question of sanitising
 * the operator's own legal text does not arise.
 *
 * An unrecognised construct degrades to a paragraph. That is the safe
 * direction: a table nobody has written yet would be shown as its own source
 * rather than swallowed, and a clause the reader cannot see is the one failure
 * this screen may not have.
 */

type Block =
  | { kind: 'heading'; text: string }
  | { kind: 'rule' }
  | { kind: 'list'; ordered: boolean; items: string[] }
  | { kind: 'paragraph'; text: string }

const RULE = /^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/
const ORDERED_ITEM = /^\s*\d+[.)]\s+/
const BULLET_ITEM = /^\s*[-*+]\s+/

function parseChunk(lines: string[]): Block[] {
  if (lines.every((line) => RULE.test(line))) {
    return [{ kind: 'rule' }]
  }

  const first = lines[0] ?? ''

  if (first.startsWith('#')) {
    const heading = first.replace(/^#+\s*/, '')
    const rest = lines.slice(1)
    return rest.length === 0
      ? [{ kind: 'heading', text: heading }]
      : [{ kind: 'heading', text: heading }, { kind: 'paragraph', text: rest.join(' ') }]
  }

  const ordered = ORDERED_ITEM.test(first)
  if (ordered || BULLET_ITEM.test(first)) {
    const marker = ordered ? ORDERED_ITEM : BULLET_ITEM
    const items: string[] = []
    for (const line of lines) {
      if (marker.test(line)) {
        items.push(line.replace(marker, ''))
        continue
      }
      // A wrapped line of the item above. The backend files wrap at eighty
      // columns, so almost every item has one.
      const last = items.length - 1
      if (last < 0) {
        items.push(line.trim())
      } else {
        items[last] = `${items[last]} ${line.trim()}`
      }
    }
    return [{ kind: 'list', ordered, items: items.filter((item) => item !== '') }]
  }

  return [{ kind: 'paragraph', text: lines.join(' ') }]
}

function parseConsentBody(markdown: string): Block[] {
  const chunks: string[][] = []
  let current: string[] = []

  for (const rawLine of markdown.replaceAll('\r\n', '\n').split('\n')) {
    if (rawLine.trim() === '') {
      if (current.length > 0) {
        chunks.push(current)
        current = []
      }
      continue
    }
    current.push(rawLine)
  }
  if (current.length > 0) {
    chunks.push(current)
  }

  return chunks.flatMap(parseChunk)
}

/** `**bold**` and nothing else; the odd segments of the split are the emphasis. */
function inline(text: string): ReactNode {
  const parts = text.split('**')
  if (parts.length === 1) {
    return text
  }
  return parts.map((part, index) =>
    index % 2 === 1 ? (
      <strong key={index} className="font-semibold text-ink">
        {part}
      </strong>
    ) : (
      <Fragment key={index}>{part}</Fragment>
    ),
  )
}

export function ConsentBody({ markdown }: { markdown: string }) {
  const blocks = parseConsentBody(markdown)

  return (
    <div className="grid gap-4 text-ink">
      {blocks.map((block, index) => {
        if (block.kind === 'rule') {
          return <hr key={index} className="m-0 border-0 border-t border-rule" />
        }
        if (block.kind === 'heading') {
          return (
            <h3 key={index} className="m-0 text-lg font-semibold text-ink">
              {inline(block.text)}
            </h3>
          )
        }
        if (block.kind === 'list') {
          const className = 'm-0 grid gap-2 pl-6'
          return block.ordered ? (
            <ol key={index} className={className}>
              {block.items.map((item, position) => (
                <li key={position}>{inline(item)}</li>
              ))}
            </ol>
          ) : (
            <ul key={index} className={className}>
              {block.items.map((item, position) => (
                <li key={position}>{inline(item)}</li>
              ))}
            </ul>
          )
        }
        return (
          <p key={index} className="m-0">
            {inline(block.text)}
          </p>
        )
      })}
    </div>
  )
}

/**
 * Said in the reader's language, above the text and not inside it.
 *
 * The bodies themselves already open with «A placeholder text» — but they say
 * it in English, in the operator's own wording, and a reader who has the
 * interface in Russian would meet that sentence in a language they may not
 * read. The notice is therefore an interface string like any other, translated
 * like any other, and it stands outside the quoted document so that nothing in
 * the quotation has been edited.
 *
 * It matters that this is stated before the button and not after: a draft
 * presented as an approved text is a misrepresentation of what the person is
 * agreeing to, which is the one defect this screen cannot be allowed to have.
 */
export function ConsentDraftNotice({ revision }: { revision: string }) {
  const { t } = useTranslation()

  return (
    <section className="border-l-4 border-brass bg-brass-wash px-4 py-4" role="note">
      <h2 className="m-0 text-lg font-semibold text-ink">{t('consent.draftTitle')}</h2>
      <p className="mt-2 mb-0 text-ink">{t('consent.draftBody')}</p>
      <p className="mt-2 mb-0 text-steel">{t('consent.draftRevision', { revision })}</p>
    </section>
  )
}
