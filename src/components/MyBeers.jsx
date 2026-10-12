import { useEffect, useMemo, useState } from 'react'
import Lightbox from './Lightbox'
import PhotoThumb from './PhotoThumb'
import {
  listUserBeers,
  getUserBeerEntry,
  saveUserBeer,
  deleteUserBeerPhoto,
  saveManualBeer,
} from '../api/endpoints.js'
import { getPhotoUrl } from '../api/photos.js'
import { useToast } from '../toast/toastContext.js'
import { openDatePicker } from '../utils/datePicker.js'
import { formatDate, titleCase, todayIso as getTodayIso } from '../utils/format.js'
import { MAX_PHOTOS, uploadPhotos } from '../utils/uploadPhotos.js'

const SORT_OPTIONS = [
  { value: 'beer_asc', label: 'Beer name (A-Z)' },
  { value: 'brewery_asc', label: 'Brewery (A-Z)' },
  { value: 'date_tasted_desc', label: 'Date tasted (newest first)' },
  { value: 'updated_desc', label: 'Date edited (newest first)' },
]

/**
 * Displays the user's saved beers with quick metadata, and an editable modal per entry.
 * Manages photo upload/delete, tasting details, and lightbox display.
 * @param {Object} props
 * @param {{id:number,name?:string,email?:string}} props.user Authenticated user.
 * @param {number} props.refreshKey Changes trigger list reload (incremented after saves elsewhere).
 */
export default function MyBeers({ user, refreshKey }) {
  const toast = useToast()
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [filterText, setFilterText] = useState('')
  const [sortBy, setSortBy] = useState('updated_desc')
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
  const [modalManual, setModalManual] = useState(false)
  const [modalAbv, setModalAbv] = useState('')
  const [modalDescription, setModalDescription] = useState('')
  const [modalUploading, setModalUploading] = useState(false)
  const todayIso = useMemo(() => getTodayIso(), [])

  const visibleItems = useMemo(() => {
    const needle = filterText.trim().toLowerCase()
    const filtered = needle
      ? items.filter(
          (item) =>
            item.beer?.toLowerCase().includes(needle) || item.brewery?.toLowerCase().includes(needle)
        )
      : items

    const sorted = [...filtered]
    switch (sortBy) {
      case 'beer_asc':
        sorted.sort((a, b) => a.beer.localeCompare(b.beer))
        break
      case 'brewery_asc':
        sorted.sort((a, b) => a.brewery.localeCompare(b.brewery))
        break
      case 'date_tasted_desc':
        sorted.sort((a, b) => {
          if (!a.date_tasted && !b.date_tasted) return 0
          if (!a.date_tasted) return 1
          if (!b.date_tasted) return -1
          return new Date(b.date_tasted) - new Date(a.date_tasted)
        })
        break
      case 'updated_desc':
      default:
        sorted.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at))
        break
    }
    return sorted
  }, [items, filterText, sortBy])

  const openListLightbox = async (item, evt) => {
    evt?.stopPropagation()
    if (!user || !Array.isArray(item?.photos) || item.photos.length === 0) return
    try {
      const urls = await Promise.all(item.photos.filter(Boolean).map(getPhotoUrl))
      const images = urls.map((src, i) => ({ src, alt: item.photos[i] }))
      if (images.length > 0) setLightbox({ images, startIndex: 0 })
    } catch (err) {
      toast.show('error', err.message)
    }
  }

  useEffect(() => {
    if (!user) return
    const fetchList = async () => {
      setLoading(true)
      setError('')
      try {
        const data = await listUserBeers()
        setItems(data.items || [])
      } catch (err) {
        setError(err.message)
      } finally {
        setLoading(false)
      }
    }
    fetchList()
    // Keyed on the user id so profile edits don't trigger a pointless refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id, refreshKey])

  useEffect(() => {
    if (!modalOpen || !selected || !user) return
    const fetchDetail = async () => {
      setModalLoading(true)
      setModalError('')
      try {
        const data = await getUserBeerEntry(selected.beer, selected.brewery)
        if (data.exists) {
          setModalDrank(Boolean(data.drank))
          setModalManual(Boolean(data.manual))
          setModalAbv(data.abv ?? '')
          setModalDescription(data.description || '')
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [modalOpen, selected, user?.id])

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
    setModalManual(false)
    setModalAbv('')
    setModalDescription('')
    setModalUploading(false)
  }

  const handleSaveModal = async () => {
    if (!selected || !user) return
    setModalError('')
    setModalLoading(true)
    try {
      if (modalManual) {
        await saveManualBeer({
          beer: selected.beer,
          brewery: selected.brewery,
          abv: modalAbv === '' ? null : modalAbv,
          description: modalDescription,
        })
      }
      const saved = await saveUserBeer({
        beer: selected.beer,
        brewery: selected.brewery,
        drank: modalDrank,
        user_notes: modalNotes,
        tasting_location: modalLocation,
        date_tasted: modalDate || null,
      })
      // Update list snapshot
      setItems((prev) =>
        prev.map((it) =>
          it.beer === selected.beer && it.brewery === selected.brewery
            ? {
                ...it,
                drank: modalDrank,
                tasting_location: modalLocation,
                date_tasted: saved.date_tasted ?? null,
                updated_at: saved.updated_at ?? it.updated_at,
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

  /** Copy a beer's new photo list into the list snapshot so the row's photo chip stays current. */
  const syncListPhotos = (beer, brewery, photos) =>
    setItems((prev) =>
      prev.map((it) => (it.beer === beer && it.brewery === brewery ? { ...it, photos } : it))
    )

  const handleUploadModal = async (fileList) => {
    if (!selected || !user || !fileList?.length) return
    setModalUploading(true)
    await uploadPhotos({
      toast,
      beer: selected.beer,
      brewery: selected.brewery,
      files: fileList,
      currentPhotos: modalPhotos,
      onPhotos: (photos) => {
        setModalPhotos(photos)
        syncListPhotos(selected.beer, selected.brewery, photos)
      },
    })
    setModalUploading(false)
  }

  const handleRemovePhoto = async (name) => {
    if (!selected || !user) return
    setModalUploading(true)
    const toastId = toast.show('loading', 'Removing photo...')
    try {
      const data = await deleteUserBeerPhoto({ beer: selected.beer, brewery: selected.brewery, filename: name })
      const updated = Array.isArray(data.photos) ? data.photos : []
      setModalPhotos(updated)
      syncListPhotos(selected.beer, selected.brewery, updated)
      toast.show('success', 'Photo removed', toastId)
    } catch (err) {
      toast.show('error', err.message, toastId)
    } finally {
      setModalUploading(false)
    }
  }

  return (
    <div className="panel my-beers">
      <div className="panel__header">
        <div className="pill">My Beers</div>
      </div>
      {items.length > 0 && (
        <div className="my-beers__controls">
          <label className="field grow">
            <span>Filter by name or brewery</span>
            <div className="input-clearable">
              <input
                value={filterText}
                onChange={(e) => setFilterText(e.target.value)}
                placeholder="Search..."
              />
              {filterText && (
                <button
                  type="button"
                  className="input-clear"
                  onClick={() => setFilterText('')}
                  aria-label="Clear filter"
                >
                  ×
                </button>
              )}
            </div>
          </label>
          <label className="field">
            <span>Sort by</span>
            <select value={sortBy} onChange={(e) => setSortBy(e.target.value)}>
              {SORT_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>
        </div>
      )}
      {loading && <div className="hint">Loading your beers...</div>}
      {error && <div className="error">{error}</div>}
      {!loading && !error && items.length === 0 && <div className="hint">No beers saved yet.</div>}
      {!loading && !error && items.length > 0 && visibleItems.length === 0 && (
        <div className="hint">No beers match "{filterText}".</div>
      )}
      {!loading && !error && visibleItems.length > 0 && (
        <div className="beer-list">
          {visibleItems.map((item, idx) => (
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
              <div className="modal__title">
                <div className="label">{selected.brewery}</div>
                <div className="value">{titleCase(selected.beer)}</div>
              </div>
              <button className="ghost modal__close" type="button" onClick={closeModal} aria-label="Close">
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
              {modalManual && (
                <>
                  <label className="field">
                    <span>ABV % (added manually)</span>
                    <input
                      type="number"
                      min="0"
                      max="100"
                      step="0.1"
                      value={modalAbv}
                      onChange={(e) => setModalAbv(e.target.value)}
                    />
                  </label>
                  <label className="field">
                    <span>Description (added manually)</span>
                    <textarea
                      value={modalDescription}
                      onChange={(e) => setModalDescription(e.target.value)}
                      maxLength={1000}
                      rows={3}
                    />
                  </label>
                </>
              )}
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
                  onFocus={(e) => openDatePicker(e.target)}
                  onClick={(e) => openDatePicker(e.target)}
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
                <span>{`Photos (max ${MAX_PHOTOS}, saved as soon as you add or remove them)`}</span>
                <div className="photo-upload">
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/gif"
                    multiple
                    disabled={modalUploading || modalPhotos.length >= MAX_PHOTOS}
                    onChange={(e) => {
                      handleUploadModal(e.target.files)
                      e.target.value = ''
                    }}
                  />
                </div>
              </label>
              {Array.isArray(modalPhotos) && modalPhotos.length > 0 && (
                <div className="photo-list thumbs">
                  {modalPhotos.map((name) => (
                    <div key={name} className="photo-thumb-wrap">
                      <PhotoThumb filename={name} onOpen={(src) => setLightbox({ src, alt: name })} />
                      <button
                        type="button"
                        className="photo-remove"
                        aria-label="Remove photo"
                        disabled={modalUploading}
                        onClick={(e) => {
                          e.stopPropagation()
                          handleRemovePhoto(name)
                        }}
                      >
                        ×
                      </button>
                    </div>
                  ))}
                </div>
              )}
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
