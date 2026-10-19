import { useEffect, useRef, useState } from 'react'
import { api } from '../../Front-End/src/api'
import MarkdownMessage from './MarkdownMessage.jsx'
import './ChatWidget.css'

const WELCOME_MESSAGE = 'Hi! I’m the DriveRent assistant. Ask me about available cars, rental prices, or rental requirements.'

export default function ChatWidget() {
  const [open, setOpen] = useState(false)
  const [minimized, setMinimized] = useState(false)
  const [message, setMessage] = useState('')
  const [messages, setMessages] = useState([{ role: 'assistant', content: WELCOME_MESSAGE }])
  const [conversationId, setConversationId] = useState(null)
  const [loading, setLoading] = useState(false)
  const conversationRef = useRef(null)
  const inputRef = useRef(null)

  useEffect(() => {
    if (open && !minimized) {
      conversationRef.current?.scrollTo({ top: conversationRef.current.scrollHeight, behavior: 'smooth' })
      inputRef.current?.focus()
    }
  }, [messages, open, minimized, loading])

  const sendMessage = async (event) => {
    event.preventDefault()
    const text = message.trim()
    if (!text || loading) return

    setMessages((current) => [...current, { role: 'user', content: text }])
    setMessage('')
    setLoading(true)
    try {
      const response = await api.post('/api/chat', {
        message: text,
        conversation_id: conversationId,
      })
      const result = response.data
      if (typeof result?.message !== 'string' || !result.message.trim()) {
        throw new Error('The chat service returned an empty response.')
      }
      setConversationId(result.conversation_id || null)
      setMessages((current) => [...current, { role: 'assistant', content: result.message }])
    } catch (error) {
      setMessages((current) => [
        ...current,
        { role: 'assistant', content: error.message || 'I’m having trouble connecting right now. Please try again in a moment.' },
      ])
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="dr-chat">
      {open && (
        <section className={`dr-chat-window${minimized ? ' dr-chat-window-minimized' : ''}`} aria-label="DriveRent customer service chat">
          <header className="dr-chat-header">
            <span className="dr-chat-avatar" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 13.5 5.6 8a2 2 0 0 1 1.9-1.4h9A2 2 0 0 1 18.4 8l1.6 5.5v5h-2v-2H6v2H4v-5.5Z" /><path d="M5 13.5h14M7 11h.01M17 11h.01" /></svg>
            </span>
            <span className="dr-chat-title"><strong>DriveRent support</strong><small>Car rental assistance</small></span>
            <div className="dr-chat-header-actions">
              <button type="button" onClick={() => setMinimized((current) => !current)} aria-label={minimized ? 'Expand chat' : 'Minimize chat'}>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d={minimized ? 'M5 12h14M12 5l7 7-7 7' : 'M5 12h14'} /></svg>
              </button>
              <button type="button" onClick={() => setOpen(false)} aria-label="Close chat">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
              </button>
            </div>
          </header>
          {!minimized && (
            <>
              <div className="dr-chat-messages" ref={conversationRef} role="log" aria-live="polite" aria-label="Conversation">
                {messages.map((item, index) => (
                  <div className={`dr-chat-row dr-chat-row-${item.role}`} key={`${index}-${item.role}`}>
                    <div className="dr-chat-bubble">
                      {item.role === 'assistant' ? <MarkdownMessage content={item.content} /> : item.content}
                    </div>
                  </div>
                ))}
                {loading && <div className="dr-chat-row dr-chat-row-assistant"><div className="dr-chat-bubble dr-chat-typing" role="status" aria-label="Assistant is responding"><i /><i /><i /></div></div>}
              </div>
              <form className="dr-chat-form" onSubmit={sendMessage}>
                <label className="dr-chat-input-label" htmlFor="dr-chat-input">Message</label>
                <textarea
                  id="dr-chat-input"
                  ref={inputRef}
                  value={message}
                  onChange={(event) => setMessage(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                      event.preventDefault()
                      event.currentTarget.form.requestSubmit()
                    }
                  }}
                  placeholder="Ask about renting a car..."
                  rows={1}
                  maxLength={4000}
                  disabled={loading}
                />
                <button type="submit" aria-label="Send message" disabled={loading || !message.trim()}>
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 16 8-16 8 3-8-3-8Zm3 8h13" /></svg>
                </button>
              </form>
              <p className="dr-chat-note">Rental questions only · Bookings aren’t created in chat</p>
            </>
          )}
        </section>
      )}
      <button
        className="dr-chat-launcher"
        type="button"
        onClick={() => { setOpen((current) => !current); setMinimized(false) }}
        aria-label={open ? 'Close customer service chat' : 'Open customer service chat'}
        aria-expanded={open}
      >
        {open
          ? <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
          : <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H5l1.4-3.1A7.5 7.5 0 1 1 20 11.5Z" /><path d="M8 11h.01M12.5 11h.01M17 11h.01" /></svg>}
      </button>
    </div>
  )
}
