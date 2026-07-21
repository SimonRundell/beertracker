import { useEffect, useMemo, useState } from 'react'
import Lightbox from './Lightbox'

/**
 * Displays the user's saved beers with quick metadata, and an editable modal per entry.
 * Manages photo upload/delete, tasting details, and lightbox display.
 * @param {Object} props
 * @param {{id:number,name?:string,email?:string}} props.user Authenticated user.
 * @param {string} props.apiBase Base URL for API (e.g., http://localhost/api).
 * @param {number} props.refreshKey Changes trigger list reload (incremented after saves elsewhere).
 */
export default function MyBeers({ user, apiBase, refreshKey }) {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [lightbox, setLightbox] = useState(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalError, setModalError] = useState('')
  const [modalLoading, setModalLoading] = useState(false)
  const [selected, setSelected] = useState(null)
  const [modalDrank, setModalDrank] = useState(false)
  const [modalNotes, setModalNotes] = useState('')
  const [modalLocation, setModalLocation] = useState('')
  const [modalDate, setModalDate] = useState('')
  const [modalPhotos, setModalPhotos] = useState([])
  const [modalUploading, setModalUploading] = useState(false)
  const [modalUploadError, setModalUploadError] = useState('')
  const uploadsBase = useMemo(() => `${window.location.origin}/uploads`, [])
  const todayIso = useMemo(() => {
    const now = new Date()
    const m = String(now.getMonth() + 1).padStart(2, '0')
    const d = String(now.getDate()).padStart(2, '0')
    return `${now.getFullYear()}-${m}-${d}`
  }, [])

  const sentenceCase = (str) => {
    if (!str) return ''
    const s = String(str).toLowerCase()
    return s.charAt(0).toUpperCase() + s.slice(1)
  }

  const titleCase = (str) => {
    if (!str) return ''
    return String(str).toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase())
  }

  const formatDate = (value) => {
    if (!value) return ''
    const d = new Date(value)
    if (Number.isNaN(d)) return String(value)
    const day = String(d.getDate()).padStart(2, '0')
    const month = String(d.getMonth() + 1).padStart(2, '0')
    const year = d.getFullYear()
    return `${day}/${month}/${year}`
  }

  const openListLightbox = (item, evt) => {
    evt?.stopPropagation()
    if (!user || !uploadsBase) return
    if (!Array.isArray(item?.photos) || item.photos.length === 0) return
    const images = item.photos
      .filter(Boolean)
      .map((name) => ({ src: `${uploadsBase}/${user.id}/${name}`, alt: name }))
    if (images.length === 0) return
    setLightbox({ images, startIndex: 0 })
  }

  useEffect(() => {
    if (!user) return
    const fetchList = async () => {
      setLoading(true)
      setError('')
      try {
        const params = new URLSearchParams({ user_id: user.id })
        const res = await fetch(`${apiBase}/user_beers.php?${params.toString()}`)
        const data = await res.json()
        if (!res.ok) throw new Error(data?.error || 'Failed to load beers')
        setItems(data.items || [])
      } catch (err) {
        setError(err.message)
      } finally {
        setLoading(false)
      }
    }
    fetchList()
  }, [user, apiBase, refreshKey])

  useEffect(() => {
    if (!modalOpen || !selected || !user) return
    const fetchDetail = async () => {
      setModalLoading(true)
      setModalError('')
      try {
        const params = new URLSearchParams({
          user_id: user.id,
          beer: selected.beer,
          brewery: selected.brewery,
        })
        const res = await fetch(`${apiBase}/user_beers.php?${params.toString()}`)
        const data = await res.json()
        if (!res.ok) throw new Error(data?.error || 'Failed to load entry')
        if (data.exists) {
          setModalDrank(Boolean(data.drank))
          setModalNotes(data.user_notes || '')
          setModalLocation(data.tasting_location || '')
          setModalDate(data.date_tasted || '')
          setModalPhotos(Array.isArray(data.photos) ? data.photos : [])
        }
      } catch (err) {
        setModalError(err.message)
      } finally {
        setModalLoading(false)
      }
    }
    fetchDetail()
  }, [modalOpen, selected, user, apiBase])

  const closeModal = () => {
    setModalOpen(false)
    setSelected(null)
    setModalError('')
    setModalLoading(false)
    setModalNotes('')
    setModalLocation('')
    setModalDate('')
    setModalPhotos([])
    setModalDrank(false)
    setModalUploadError('')
    setModalUploading(false)
  }

  const handleSaveModal = async () => {
    if (!selected || !user) return
    setModalError('')
    setModalLoading(true)
    try {
      const res = await fetch(`${apiBase}/user_beers.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          user_id: user.id,
          beer: selected.beer,
          brewery: selected.brewery,
          drank: modalDrank,
          user_notes: modalNotes,
          tasting_location: modalLocation,
          date_tasted: modalDate || null,
        }),
      })
      const data = await res.json()
      if (!res.ok) throw new Error(data?.error || 'Failed to save')
      // Update list snapshot
      setItems((prev) =>
        prev.map((it) =>
          it.beer === selected.beer && it.brewery === selected.brewery
            ? {
                ...it,
                drank: modalDrank,
                tasting_location: modalLocation,
                date_tasted: modalDate || it.date_tasted,
              }
            : it
        )
      )
      closeModal()
    } catch (err) {
      setModalError(err.message)
      setModalLoading(false)
    }
  }

  const handleUploadModal = async (fileList) => {
    if (!selected || !user) return
    const files = Array.from(fileList || []).slice(0, 2)
    if (files.length === 0) return
    setModalUploadError('')
    setModalUploading(true)
    try {
      let latest = modalPhotos
      for (const file of files) {
        const form = new FormData()
        form.append('user_id', user.id)
        form.append('beer', selected.beer)
        form.append('brewery', selected.brewery)
        form.append('photo', file)

        const res = await fetch(`${apiBase}/upload_user_beer_photo.php`, {
          method: 'POST',
          body: form,
        })
        const data = await res.json()
        if (!res.ok) throw new Error(data?.error || 'Failed to upload photo')
        latest = Array.isArray(data.photos) ? data.photos : latest
        setModalPhotos(latest)
      }
      // Update list snapshot with new photo count
      setItems((prev) =>
        prev.map((it) =>
          it.beer === selected.beer && it.brewery === selected.brewery ? { ...it, photos: latest } : it
        )
      )
    } catch (err) {
      setModalUploadError(err.message)
    } finally {
      setModalUploading(false)
    }
  }

  const handleRemovePhoto = async (name) => {
    if (!selected || !user) return
    setModalUploadError('')
    setModalUploading(true)
    try {
      const form = new FormData()
      form.append('user_id', user.id)
      form.append('beer', selected.beer)
      form.append('brewery', selected.brewery)
      form.append('filename', name)

      const res = await fetch(`${apiBase}/delete_user_beer_photo.php`, {
        method: 'POST',
        body: form,
      })
      const data = await res.json()
      if (!res.ok) throw new Error(data?.error || 'Failed to delete photo')
      const updated = Array.isArray(data.photos) ? data.photos : []
      setModalPhotos(updated)
      setItems((prev) =>
        prev.map((it) =>
          it.beer === selected.beer && it.brewery === selected.brewery ? { ...it, photos: updated } : it
        )
      )
    } catch (err) {
      setModalUploadError(err.message)
    } finally {
      setModalUploading(false)
    }
  }

  return (
    <div className="panel my-beers">
      <div className="panel__header">
        <div className="pill">My Beers</div>
      </div>
      {loading && <div className="hint">Loading your beers...</div>}
      {error && <div className="error">{error}</div>}
      {!loading && !error && items.length === 0 && <div className="hint">No beers saved yet.</div>}
      {!loading && !error && items.length > 0 && (
        <div className="beer-list">
          {items.map((item, idx) => (
            <div
              className="beer-row"
              key={`${item.beer}-${item.brewery}-${idx}`}
              onClick={() => {
                setSelected({ beer: item.beer, brewery: item.brewery })
                setModalOpen(true)
              }}
            >
              <div className="beer-row__title">
                <span className="value">{titleCase(item.beer)}</span>
                <span className="label">{item.brewery}</span>
              </div>
              <div className="beer-row__meta">
                <span className="chip">{item.drank ? 'Drank' : 'Not yet'}</span>
                {item.tasting_location && <span className="chip ghost-chip">{item.tasting_location}</span>}
                {Array.isArray(item.photos) && item.photos.length > 0 && (
                  <button
                    type="button"
                    className="chip ghost-chip"
                    onClick={(e) => openListLightbox(item, e)}
                    aria-label={`View ${item.photos.length} photo${item.photos.length === 1 ? '' : 's'}`}
                  >
                    {`${item.photos.length} photo${item.photos.length === 1 ? '' : 's'}`}
                  </button>
                )}
                {(item.date_tasted || item.updated_at) && (
                  <span className="chip ghost-chip">
                    {item.date_tasted ? 'Tasted ' : 'Updated '}
                    {formatDate(item.date_tasted || item.updated_at)}
                  </span>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
      {modalOpen && selected && (
        <div className="modal" role="dialog" aria-modal="true">
          <div className="modal__content">
            <div className="modal__header">
              <div>
                <div className="label">{selected.brewery}</div>
                <div className="value">{titleCase(selected.beer)}</div>
              </div>
              <button className="ghost" type="button" onClick={closeModal} aria-label="Close">
                ×
              </button>
            </div>
            <div className="modal__body">
              {modalError && <div className="error">{modalError}</div>}
              <label className="field switch toggle">
                <input
                  className="toggle__input"
                  type="checkbox"
                  checked={modalDrank}
                  onChange={(e) => setModalDrank(e.target.checked)}
                />
                <span className="toggle__track" aria-hidden="true">
                  <span className="toggle__thumb" />
                </span>
                <span className="toggle__label">I have drunk this beer</span>
              </label>
              <label className="field">
                <span>Where did you taste it?</span>
                <input
                  value={modalLocation}
                  onChange={(e) => setModalLocation(e.target.value)}
                  placeholder="Pub, city, festival..."
                />
              </label>
              <label className="field">
                <span>When did you taste it?</span>
                <input
                  type="date"
                  value={modalDate}
                  max={todayIso}
                  onFocus={(e) => {
                    if (typeof e.target.showPicker === 'function') e.target.showPicker()
                  }}
                  onClick={(e) => {
                    if (typeof e.target.showPicker === 'function') e.target.showPicker()
                  }}
                  onChange={(e) => setModalDate(e.target.value)}
                />
              </label>
              <label className="field">
                <span>Personal tasting notes</span>
                <textarea
                  value={modalNotes}
                  onChange={(e) => setModalNotes(e.target.value)}
                  rows={4}
                />
              </label>
              <label className="field">
                <span>Photos</span>
                <div className="photo-upload">
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/gif"
                    multiple
                    disabled={modalUploading}
                    onChange={(e) => {
                      handleUploadModal(e.target.files)
                      e.target.value = ''
                    }}
                  />
                  {modalUploadError && <span className="error inline">{modalUploadError}</span>}
                </div>
                {Array.isArray(modalPhotos) && modalPhotos.length > 0 && (
                  <div className="photo-list thumbs">
                    {modalPhotos.map((name) => {
                      const src = uploadsBase && user ? `${uploadsBase}/${user.id}/${name}` : null
                      return src ? (
                        <div key={name} className="photo-thumb-wrap">
                          <img
                            src={src}
                            alt={name}
                            className="photo-thumb"
                            onClick={() => setLightbox({ src, alt: name })}
                          />
                          <button
                            type="button"
                            className="photo-remove"
                            aria-label="Remove photo"
                            onClick={(e) => {
                              e.stopPropagation()
                              handleRemovePhoto(name)
                            }}
                          >
                            ×
                          </button>
                        </div>
                      ) : (
                        <span key={name} className="chip ghost-chip">{name}</span>
                      )
                    })}
                  </div>
                )}
              </label>
            </div>
            <div className="modal__footer">
              <button className="ghost" type="button" onClick={closeModal}>
                Cancel
              </button>
              <button className="primary" type="button" onClick={handleSaveModal} disabled={modalLoading}>
                {modalLoading ? 'Saving...' : 'Save changes'}
              </button>
            </div>
          </div>
        </div>
      )}
      {(lightbox?.src || (lightbox?.images && lightbox.images.length > 0)) && (
        <Lightbox
          src={lightbox?.src}
          alt={lightbox?.alt}
          images={lightbox?.images}
          startIndex={lightbox?.startIndex || 0}
          onClose={() => setLightbox(null)}
        />
      )}
    </div>
  )
}
