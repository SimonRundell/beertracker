import { useEffect, useState } from 'react'

/**
 * Renders MOTD HTML from public/MOTD.txt for unauthenticated users.
 */
export default function MOTD() {
  const [html, setHtml] = useState('')

  useEffect(() => {
    let active = true
    const load = async () => {
      try {
        const res = await fetch('/MOTD.txt')
        const text = await res.text()
        if (!active) return
        if (!res.ok) throw new Error('Failed to load')
        setHtml(text)
      } catch {
        // No MOTD is fine; render nothing.
      }
    }
    load()
    return () => {
      active = false
    }
  }, [])

  if (!html) return null

  return (
    <div className="panel ghost-panel">
      <div dangerouslySetInnerHTML={{ __html: html }} />
    </div>
  )
}
