import { Fragment } from 'react'

const INLINE_MARKDOWN = /(\*\*[^*]+\*\*|__[^_]+__|`[^`]+`|\*[^*\n]+\*|_[^_\n]+_)/g

function renderInline(text, prefix) {
  return text.split(INLINE_MARKDOWN).filter(Boolean).map((part, index) => {
    const key = `${prefix}-${index}`
    if ((part.startsWith('**') && part.endsWith('**')) || (part.startsWith('__') && part.endsWith('__'))) {
      return <strong key={key}>{part.slice(2, -2)}</strong>
    }
    if ((part.startsWith('*') && part.endsWith('*')) || (part.startsWith('_') && part.endsWith('_'))) {
      return <em key={key}>{part.slice(1, -1)}</em>
    }
    if (part.startsWith('`') && part.endsWith('`')) {
      return <code key={key}>{part.slice(1, -1)}</code>
    }
    return <Fragment key={key}>{part}</Fragment>
  })
}

function tableCells(line) {
  return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((cell) => cell.trim())
}

function isTableDivider(line) {
  const cells = tableCells(line)
  return cells.length > 1 && cells.every((cell) => /^:?-{3,}:?$/.test(cell))
}

export default function MarkdownMessage({ content }) {
  const lines = content.split(/\r?\n/)
  const blocks = []
  let index = 0

  while (index < lines.length) {
    const line = lines[index].trim()
    if (!line) {
      index += 1
      continue
    }

    const heading = /^(#{1,6})\s+(.+)$/.exec(line)
    if (heading) {
      const Heading = `h${heading[1].length}`
      blocks.push(<Heading key={`heading-${index}`}>{renderInline(heading[2], `heading-${index}`)}</Heading>)
      index += 1
      continue
    }

    if (index + 1 < lines.length && line.includes('|') && isTableDivider(lines[index + 1])) {
      const header = tableCells(line)
      const rows = []
      index += 2
      while (index < lines.length && lines[index].trim() && lines[index].includes('|')) {
        rows.push(tableCells(lines[index]))
        index += 1
      }
      blocks.push(
        <div className="dr-chat-table-wrap" key={`table-${index}`}>
          <table>
            <thead><tr>{header.map((cell, cellIndex) => <th key={`head-${cellIndex}`}>{renderInline(cell, `head-${index}-${cellIndex}`)}</th>)}</tr></thead>
            <tbody>{rows.map((row, rowIndex) => (
              <tr key={`row-${rowIndex}`}>{header.map((_, cellIndex) => (
                <td key={`cell-${cellIndex}`}>{renderInline(row[cellIndex] || '', `cell-${index}-${rowIndex}-${cellIndex}`)}</td>
              ))}</tr>
            ))}</tbody>
          </table>
        </div>,
      )
      continue
    }

    const listMatch = /^([-*+]|\d+[.)])\s+(.+)$/.exec(line)
    if (listMatch) {
      const ordered = /^\d/.test(listMatch[1])
      const List = ordered ? 'ol' : 'ul'
      const items = []
      while (index < lines.length) {
        const item = /^([-*+]|\d+[.)])\s+(.+)$/.exec(lines[index].trim())
        if (!item || /^\d/.test(item[1]) !== ordered) break
        items.push(<li key={`item-${index}`}>{renderInline(item[2], `item-${index}`)}</li>)
        index += 1
      }
      blocks.push(<List key={`list-${index}`}>{items}</List>)
      continue
    }

    const paragraph = []
    while (index < lines.length && lines[index].trim()
      && !/^(#{1,6})\s+/.test(lines[index].trim())
      && !/^([-*+]|\d+[.)])\s+/.test(lines[index].trim())
      && !(index + 1 < lines.length && lines[index].includes('|') && isTableDivider(lines[index + 1]))) {
      paragraph.push(lines[index].trim())
      index += 1
    }
    blocks.push(<p key={`paragraph-${index}`}>{renderInline(paragraph.join(' '), `paragraph-${index}`)}</p>)
  }

  return <div className="dr-chat-markdown">{blocks}</div>
}
