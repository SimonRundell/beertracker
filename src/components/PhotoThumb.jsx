import { useEffect, useState } from 'react'
import { getPhotoUrl } from '../api/photos.js'

/**
 * Thumbnail for one of the user's photos. Photos need the auth token, so the
 * image is fetched through the API (and cached) rather than via a plain <img src>.
 * @param {Object} props
 * @param {string} props.filename Stored photo filename (callers should key the component by it).
 * @param {(url: string, filename: string) => void} [props.onOpen] Called with the displayable URL when clicked.
 */
export default function PhotoThumb({ filename, onOpen }) {
  const [url, setUrl] = useState(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let cancelled = false
    getPhotoUrl(filename)
      .then((u) => {
        if (!cancelled) setUrl(u)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [filename])

  if (failed) return <span className="chip ghost-chip">Photo unavailable</span>
  if (!url) return <span className="photo-thumb photo-thumb--loading" aria-label="Loading photo" />
  return (
    <img
      src={url}
      alt={filename}
      className="photo-thumb"
      onClick={() => onOpen && onOpen(url, filename)}
    />
  )
}
