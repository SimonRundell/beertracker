import { useCallback, useEffect, useMemo, useState } from 'react'

/**
 * Full-screen image lightbox overlay with ESC and click-to-close support.
 * @param {Object} props
 * @param {string} props.src Image URL to display.
 * @param {string} [props.alt] Alt text.
 * @param {Array<string|{src:string,alt?:string}>} [props.images] Optional gallery of images.
 * @param {number} [props.startIndex] Starting index when using gallery mode.
 * @param {() => void} [props.onClose] Close callback.
 */
export default function Lightbox({ src, alt, images, startIndex = 0, onClose }) {
  const normalized = useMemo(() => {
    if (!Array.isArray(images)) return []
    return images
      .map((img) =>
        typeof img === 'string'
          ? { src: img, alt: alt || 'Photo' }
          : { src: img?.src, alt: img?.alt || alt || 'Photo' }
      )
      .filter((img) => Boolean(img.src))
  }, [images, alt])

  const hasGallery = normalized.length > 0
  const [index, setIndex] = useState(() => {
    if (!hasGallery) return 0
    if (startIndex < 0) return 0
    if (startIndex >= normalized.length) return normalized.length - 1
    return startIndex
  })

  useEffect(() => {
    if (!hasGallery) return
    if (startIndex < 0) {
      setIndex(0)
      return
    }
    if (startIndex >= normalized.length) {
      setIndex(normalized.length - 1)
      return
    }
    setIndex(startIndex)
  }, [hasGallery, normalized.length, startIndex])

  const goNext = useCallback(() => {
    if (!hasGallery || normalized.length <= 1) return
    setIndex((prev) => (prev + 1) % normalized.length)
  }, [hasGallery, normalized.length])

  const goPrev = useCallback(() => {
    if (!hasGallery || normalized.length <= 1) return
    setIndex((prev) => (prev - 1 + normalized.length) % normalized.length)
  }, [hasGallery, normalized.length])

  const active = hasGallery ? normalized[index] : src ? { src, alt: alt || 'Photo' } : null
  const activeSrc = active?.src
  const activeAlt = active?.alt || 'Photo'

  useEffect(() => {
    if (!activeSrc) return
    const onKey = (e) => {
      if (e.key === 'Escape') {
        onClose?.()
        return
      }
      if (hasGallery && normalized.length > 1) {
        if (e.key === 'ArrowRight') {
          e.preventDefault()
          goNext()
        }
        if (e.key === 'ArrowLeft') {
          e.preventDefault()
          goPrev()
        }
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [activeSrc, hasGallery, normalized.length, goNext, goPrev, onClose])

  if (!activeSrc) return null

  return (
    <div className="lightbox" onClick={onClose} role="dialog" aria-modal="true">
      <div className="lightbox__inner" onClick={(e) => e.stopPropagation()}>
        <button className="ghost lightbox__close" type="button" onClick={onClose} aria-label="Close image">
          ×
        </button>
        {hasGallery && normalized.length > 1 && (
          <div className="lightbox__controls">
            <button type="button" className="ghost" onClick={goPrev} aria-label="Previous photo">
              {'<'}
            </button>
            <span className="lightbox__counter">{`${index + 1} / ${normalized.length}`}</span>
            <button type="button" className="ghost" onClick={goNext} aria-label="Next photo">
              {'>'}
            </button>
          </div>
        )}
        <img src={activeSrc} alt={activeAlt} />
      </div>
    </div>
  )
}
