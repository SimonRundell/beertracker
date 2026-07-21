import { useEffect, useMemo, useRef, useState } from 'react'
import DOMPurify from 'dompurify'
import Lightbox from './Lightbox'
import { getUserBeerEntry, saveUserBeer, uploadUserBeerPhoto, searchBeerCandidates } from '../api/endpoints.js'

const TASTING_NOTES_SANITIZE_CONFIG = {
  ALLOWED_TAGS: ['h3', 'p', 'ul', 'li', 'strong', 'em', 'br'],
  ALLOWED_ATTR: [],
}

/**
 * Beer search panel plus personal log editor for the selected beer.
 * Handles loading/saving user-specific notes, photos, and tasting metadata.
 * @param {Object} props
 * @param {(query: string) => void} props.onSearch Triggered with user prompt.
 * @param {any} props.result Current beer search result object.
 * @param {boolean} props.busy Search in-flight flag.
 * @param {string} props.error Search error message (if any).
 * @param {{id:number,name?:string,email?:string}} props.user Authenticated user.
 * @param {() => void} [props.onSaved] Optional callback after log save/upload.
 */
export default function BeerSearch({ onSearch, result, busy, error, user, onSaved }) {
  const [beer, setBeer] = useState('')
  const [brewery, setBrewery] = useState('')
  const [drank, setDrank] = useState(false)
  const [userNotes, setUserNotes] = useState('')
  const [tastingLocation, setTastingLocation] = useState('')
  const [dateTasted, setDateTasted] = useState('')
  const [saveError, setSaveError] = useState('')
  const [saveStatus, setSaveStatus] = useState('')
  const [logLoading, setLogLoading] = useState(false)
  const [photos, setPhotos] = useState([])
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState('')
  const [uploadStatus, setUploadStatus] = useState('')
  const [lightbox, setLightbox] = useState(null)
  const [candidates, setCandidates] = useState(null)
  const [candidateBusy, setCandidateBusy] = useState(false)
  const [candidateError, setCandidateError] = useState('')
  const dateInputRef = useRef(null)
  const canClear = beer || brewery
  const uploadsBase = useMemo(() => `${window.location.origin}/uploads`, [])
  const todayIso = useMemo(() => {
    const now = new Date()
    const month = String(now.getMonth() + 1).padStart(2, '0')
    const day = String(now.getDate()).padStart(2, '0')
    return `${now.getFullYear()}-${month}-${day}`
  }, [])
  const openDatePicker = () => {
    if (dateInputRef.current) {
      // showPicker pops the browser-native calendar when supported.
      if (typeof dateInputRef.current.showPicker === 'function') {
        dateInputRef.current.showPicker()
      } else {
        dateInputRef.current.focus()
        dateInputRef.current.click()
      }
    }
  }


  const sentenceCase = (str) => {
    if (!str) return ''
    const s = String(str).toLowerCase()
    return s.charAt(0).toUpperCase() + s.slice(1)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setCandidates(null)
    setCandidateError('')

    // Brewery given explicitly: no ambiguity to resolve, search directly.
    if (brewery) {
      onSearch(`${beer} - ${brewery}`)
      return
    }

    setCandidateBusy(true)
    try {
      const data = await searchBeerCandidates(beer)
      const matches = Array.isArray(data.candidates) ? data.candidates : []
      if (matches.length === 0) {
        onSearch(beer)
      } else if (matches.length === 1) {
        onSearch(`${matches[0].beer} - ${matches[0].brewery}`)
      } else {
        setCandidates(matches)
      }
    } catch (err) {
      setCandidateError(err.message)
    } finally {
      setCandidateBusy(false)
    }
  }

  const chooseCandidate = (candidate) => {
    setCandidates(null)
    onSearch(`${candidate.beer} - ${candidate.brewery}`)
  }

  const searchByNameOnly = () => {
    setCandidates(null)
    onSearch(beer)
  }

  useEffect(() => {
    // Clear personal fields when the result changes
    setDrank(false)
    setUserNotes('')
    setTastingLocation('')
    setDateTasted('')
    setSaveError('')
    setSaveStatus('')
    setPhotos([])
    setUploadError('')
    setUploadStatus('')
    setUploading(false)
    setLightbox(null)
    setCandidates(null)
    setCandidateError('')

    if (!result || !user) return

    const fetchLog = async () => {
      setLogLoading(true)
      try {
        const data = await getUserBeerEntry(result.beer, result.brewery)
        if (data.exists) {
          setDrank(Boolean(data.drank))
          setUserNotes(data.user_notes || '')
          setTastingLocation(data.tasting_location || '')
          setDateTasted(data.date_tasted || '')
          setPhotos(Array.isArray(data.photos) ? data.photos : [])
        }
      } catch (err) {
        setSaveError(err.message)
      } finally {
        setLogLoading(false)
      }
    }

    fetchLog()
  }, [result, user])

  const handleSave = async () => {
    if (!result || !user) return
    setSaveError('')
    setSaveStatus('')
    setLogLoading(true)
    try {
      await saveUserBeer({
        beer: result.beer,
        brewery: result.brewery,
        drank,
        user_notes: userNotes,
        tasting_location: tastingLocation,
        date_tasted: dateTasted || null,
      })
      setSaveStatus('Saved')
      if (onSaved) onSaved()
    } catch (err) {
      setSaveError(err.message)
    } finally {
      setLogLoading(false)
    }
  }

  const handleUpload = async (fileList) => {
    if (!result || !user) return
    const files = Array.from(fileList || []).slice(0, 2)
    if (files.length === 0) return
    setUploadError('')
    setUploadStatus('')
    setUploading(true)
    try {
      let latestPhotos = photos
      for (const file of files) {
        const data = await uploadUserBeerPhoto({ beer: result.beer, brewery: result.brewery, file })
        latestPhotos = Array.isArray(data.photos) ? data.photos : latestPhotos
        setPhotos(latestPhotos)
      }
      setUploadStatus('Photo uploaded')
      if (onSaved) onSaved()
    } catch (err) {
      setUploadError(err.message)
    } finally {
      setUploading(false)
    }
  }

  return (
    <div className="panel">
      <div className="panel__header">
        <div className="pill">Beer search</div>
        <button
          className="icon-bubble"
          type="button"
          onClick={() => {
            setBeer('')
            setBrewery('')
            setCandidates(null)
            setCandidateError('')
          }}
          aria-label="Clear search"
          disabled={!canClear || busy}
        >
          ×
        </button>
      </div>
      <form className="form inline" onSubmit={handleSubmit}>
        <label className="field grow">
          <span>Beer</span>
          <input value={beer} onChange={(e) => setBeer(e.target.value)} required />
        </label>
        <div className="field actions">
          <span>&nbsp;</span>
        </div>
        <label className="field grow">
          <span>Brewery (optional)</span>
          <input value={brewery} onChange={(e) => setBrewery(e.target.value)} />
        </label>
        <button className="primary" type="submit" disabled={busy || candidateBusy}>
          {busy || candidateBusy ? 'Searching...' : 'Search'}
        </button>
      </form>
      {candidateError && <div className="error">{candidateError}</div>}
      {candidates && candidates.length > 0 && (
        <div className="candidates">
          <div className="label">Did you mean...?</div>
          <div className="candidates__list">
            {candidates.map((c, idx) => (
              <button
                key={`${c.beer}-${c.brewery}-${idx}`}
                type="button"
                className="candidate-row"
                onClick={() => chooseCandidate(c)}
              >
                <span className="value">{c.beer}</span>
                <span className="label">{c.brewery}{c.country ? ` · ${c.country}` : ''}</span>
              </button>
            ))}
          </div>
          <button type="button" className="ghost" onClick={searchByNameOnly}>
            None of these — search by name only
          </button>
        </div>
      )}
      {error && <div className="error">{error}</div>}
      {result && (
        <div className="result">
          <div className="result__row">
            <div>
              <div className="label">Brewery</div>
              <div className="value">{result.brewery}</div>
            </div>
            <div>
              <div className="label">Beer</div>
              <div className="value">{sentenceCase(result.beer)}</div>
            </div>
            <div>
              <div className="label">ABV</div>
              <div className="value">{result.abv}%</div>
            </div>
          </div>
          <div className="result__details">
            <div className="label">Brewery details</div>
            <div className="value">{result['brewery details']}</div>
          </div>
          <div className="result__details">
            <div className="label">Tasting notes</div>
            <div
              className="tasting"
              dangerouslySetInnerHTML={{
                __html: DOMPurify.sanitize(result.tastingnotes, TASTING_NOTES_SANITIZE_CONFIG),
              }}
            />
          </div>
          {user && (
            <div className="personal">
              <div className="label">Your log</div>
              <div className="personal__fields">
                <label className="field switch toggle">
                  <input
                    className="toggle__input"
                    type="checkbox"
                    checked={drank}
                    onChange={(e) => setDrank(e.target.checked)}
                  />
                  <span className="toggle__track" aria-hidden="true">
                    <span className="toggle__thumb" />
                  </span>
                  <span className="toggle__label">I have drunk this beer</span>
                </label>
                <label className="field">
                  <span>Where did you taste it?</span>
                  <input
                    value={tastingLocation}
                    onChange={(e) => setTastingLocation(e.target.value)}
                    placeholder="Pub, city, festival..."
                  />
                </label>
                <label className="field">
                  <span>When did you taste it?</span>
                  <div className="date-row">
                    <input
                      ref={dateInputRef}
                      type="date"
                      value={dateTasted}
                      max={todayIso}
                      onChange={(e) => setDateTasted(e.target.value)}
                      onFocus={openDatePicker}
                      onClick={openDatePicker}
                    />
                  </div>
                </label>
                <label className="field">
                  <span>Photos (max 2 per upload)</span>
                  <div className="photo-upload">
                    <input
                      type="file"
                      accept="image/png,image/jpeg,image/gif"
                      multiple
                      onChange={(e) => {
                        handleUpload(e.target.files)
                        e.target.value = ''
                      }}
                      disabled={uploading}
                    />
                    {uploadStatus && <span className="success">{uploadStatus}</span>}
                    {uploadError && <span className="error inline">{uploadError}</span>}
                  </div>
                  {photos.length > 0 && (
                    <div className="photo-list">
                      {photos.map((name) => {
                        const src = uploadsBase && user ? `${uploadsBase}/${user.id}/${name}` : null
                        return src ? (
                          <img
                            key={name}
                            src={src}
                            alt={name}
                            className="photo-thumb"
                            onClick={() => setLightbox({ src, alt: name })}
                          />
                        ) : (
                          <span key={name} className="chip ghost-chip">
                            {name}
                          </span>
                        )
                      })}
                    </div>
                  )}
                </label>
                <label className="field">
                  <span>Personal tasting notes</span>
                  <textarea
                    value={userNotes}
                    onChange={(e) => setUserNotes(e.target.value)}
                    placeholder="What did you notice?"
                    rows={4}
                  />
                </label>
                <div className="actions-row">
                  <button className="primary" type="button" onClick={handleSave} disabled={logLoading}>
                    {logLoading ? 'Saving...' : 'Save log'}
                  </button>
                  {saveStatus && <span className="success">{saveStatus}</span>}
                  {saveError && <span className="error inline">{saveError}</span>}
                </div>
              </div>
            </div>
          )}
        </div>
      )}
      {lightbox?.src && (
        <Lightbox src={lightbox.src} alt={lightbox.alt} onClose={() => setLightbox(null)} />
      )}
    </div>
  )
}
