import { useCallback, useMemo, useRef, useState } from 'react'
import { ToastContext } from './toastContext.js'

const AUTO_DISMISS_MS = 4000

/**
 * Provides toast notifications to the tree and renders them bottom-right.
 * Used for progress that would otherwise look like the app has frozen
 * (e.g. "Uploading photo...").
 * @param {Object} props
 * @param {import('react').ReactNode} props.children
 */
export default function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const nextId = useRef(1)
  const timers = useRef(new Map())

  const dismiss = useCallback((id) => {
    clearTimeout(timers.current.get(id))
    timers.current.delete(id)
    setToasts((prev) => prev.filter((t) => t.id !== id))
  }, [])

  const show = useCallback(
    (kind, message, id) => {
      const toastId = id ?? nextId.current++
      clearTimeout(timers.current.get(toastId))
      setToasts((prev) => {
        const exists = prev.some((t) => t.id === toastId)
        const next = { id: toastId, kind, message }
        return exists ? prev.map((t) => (t.id === toastId ? next : t)) : [...prev, next]
      })
      if (kind !== 'loading') {
        timers.current.set(toastId, setTimeout(() => dismiss(toastId), AUTO_DISMISS_MS))
      }
      return toastId
    },
    [dismiss]
  )

  const api = useMemo(() => ({ show, dismiss }), [show, dismiss])

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="toast-region" role="status" aria-live="polite">
        {toasts.map((t) => (
          <div key={t.id} className={`toast toast--${t.kind}`}>
            {t.kind === 'loading' && <span className="toast__spinner" aria-hidden="true" />}
            <span className="toast__message">{t.message}</span>
            {t.kind !== 'loading' && (
              <button type="button" className="toast__close" onClick={() => dismiss(t.id)} aria-label="Dismiss">
                ×
              </button>
            )}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}
