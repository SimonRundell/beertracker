import { useEffect, useMemo, useState } from 'react'
import DOMPurify from 'dompurify'
import Lightbox from './Lightbox'
import PhotoThumb from './PhotoThumb'
import { getUserBeerEntry, saveManualBeer, saveUserBeer, searchBeerCandidates } from '../api/endpoints.js'
import { useToast } from '../toast/toastContext.js'
import { openDatePicker } from '../utils/datePicker.js'
import { sentenceCase, titleCase, todayIso as getTodayIso } from '../utils/format.js'
import { MAX_PHOTOS, uploadPhotos } from '../utils/uploadPhotos.js'

const TASTING_NOTES_SANITIZE_CONFIG = {
  ALLOWED_TAGS: ['h3', 'p', 'ul', 'li', 'strong', 'em', 'br'],
  ALLOWED_ATTR: [],
}

/**
 * Beer search panel plus personal log editor for the selected beer.
 * Handles loading/saving user-specific notes, photos, and tasting metadata.
 * @param {Object} props
 * @param {(query: {beer: string, brewery?: string}) => void} props.onSearch Triggered with the beer (and optional brewery) to look up.
 * @param {() => void} props.onClear Clears the current search result and error.
 * @param {(result: object) => void} props.onManualAdd Receives the saved hand-entered beer (search-result shaped) when search can't find one.
 * @param {any} props.result Current beer search result object.
 * @param {boolean} props.busy Search in-flight flag.
 * @param {string} props.error Search error message (if any).
 * @param {{id:number,name?:string,email?:string}} props.user Authenticated user.
 * @param {() => void} [props.onSaved] Optional callback after log save/upload.
 */
export default function BeerSearch({ onSearch, onClear, onManualAdd, result, busy, error, user, onSaved }) {
  const toast = useToast()
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
  const [lightbox, setLightbox] = useState(null)
  const [candidates, setCandidates] = useState(null)
  const [candidateBusy, setCandidateBusy] = useState(false)
  const [candidateError, setCandidateError] = useState('')
  const [manualOpen, setManualOpen] = useState(false)
  const [manualBeer, setManualBeer] = useState('')
  const [manualBrewery, setManualBrewery] = useState('')
  const [manualAbv, setManualAbv] = useState('')
  const [manualDescription, setManualDescription] = useState('')
  const [manualBusy, setManualBusy] = useState(false)
  const [manualError, setManualError] = useState('')
  // Offer manual entry whenever a search has ended without a usable result.
  const notFound = !busy && !candidateBusy && !result && Boolean(error || candidateError)
  const canClear = Boolean(beer || brewery || result || error)
  const todayIso = useMemo(() => getTodayIso(), [])
  const openManual = () => {
    setManualBeer(beer)
    setManualBrewery(brewery)
    setManualAbv('')
    setManualDescription('')
    setManualError('')
    setManualOpen(true)
  }

  const submitManual = async (e) => {
    e.preventDefault()
    setManualBusy(true)
    setManualError('')
    try {
      const saved = await saveManualBeer({
        beer: manualBeer.trim(),
        brewery: manualBrewery.trim(),
        abv: manualAbv === '' ? null : manualAbv,
        description: manualDescription,
      })
      setManualOpen(false)
      setCandidates(null)
      setCandidateError('')
      onManualAdd(saved)
      if (onSaved) onSaved()
    } catch (err) {
      setManualError(err.message)
    } finally {
      setManualBusy(false)
    }
  }

  /** Reset the form and the current result so a new beer can be searched. */
  const clearSearch = () => {
    setBeer('')
    setBrewery('')
    setCandidates(null)
    setCandidateError('')
    setManualOpen(false)
    setManualError('')
    onClear()
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setCandidates(null)
    setCandidateError('')

    // Brewery given explicitly: no ambiguity to resolve, search directly.
    if (brewery) {
      onSearch({ beer, brewery })
      return
    }

    setCandidateBusy(true)
    try {
      const data = await searchBeerCandidates(beer)
      const matches = Array.isArray(data.candidates) ? data.candidates : []
      if (matches.length === 0) {
        onSearch({ beer })
      } else if (matches.length === 1) {
        onSearch({ beer: matches[0].beer, brewery: matches[0].brewery })
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
    onSearch({ beer: candidate.beer, brewery: candidate.brewery })
  }

  const searchByNameOnly = () => {
    setCandidates(null)
    onSearch({ beer })
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
    // Keyed on the user id: profile edits replace the user object but must not wipe the form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [result, user?.id])

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
    if (!result || !user || !fileList?.length) return
    setUploading(true)
    const { uploaded } = await uploadPhotos({
      toast,
      beer: result.beer,
      brewery: result.brewery,
      files: fileList,
      currentPhotos: photos,
      onPhotos: setPhotos,
    })
    setUploading(false)
    if (uploaded > 0 && onSaved) onSaved()
  }

  return (
    <div className="panel">
      <div className="panel__header">
        <div className="pill">Beer search</div>
        <button
          className="icon-bubble"
          type="button"
          onClick={clearSearch}
          aria-label="Clear search and result"
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
        <button className="primary topgap" type="submit" disabled={busy || candidateBusy}>
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
      {notFound && !manualOpen && (
        <div className="manual-prompt">
          <span className="hint">Very new beers may not be in the AI's knowledge yet.</span>
          <button type="button" className="ghost" onClick={openManual}>
            Add this beer manually
          </button>
        </div>
      )}
      {notFound && manualOpen && (
        <form className="form manual-form" onSubmit={submitManual}>
          <label className="field">
            <span>Beer</span>
            <input value={manualBeer} onChange={(e) => setManualBeer(e.target.value)} maxLength={255} required />
          </label>
          <label className="field">
            <span>Brewery</span>
            <input value={manualBrewery} onChange={(e) => setManualBrewery(e.target.value)} maxLength={255} required />
          </label>
          <label className="field">
            <span>ABV % (optional)</span>
            <input
              type="number"
              min="0"
              max="100"
              step="0.1"
              value={manualAbv}
              onChange={(e) => setManualAbv(e.target.value)}
            />
          </label>
          <label className="field">
            <span>Description (optional)</span>
            <textarea
              value={manualDescription}
              onChange={(e) => setManualDescription(e.target.value)}
              maxLength={1000}
              rows={3}
              placeholder="Style, what the brewery says about it..."
            />
          </label>
          {manualError && <div className="error">{manualError}</div>}
          <div className="actions-row">
            <button className="primary" type="submit" disabled={manualBusy}>
              {manualBusy ? 'Adding...' : 'Add beer'}
            </button>
            <button className="ghost" type="button" onClick={() => setManualOpen(false)} disabled={manualBusy}>
              Cancel
            </button>
          </div>
        </form>
      )}
      {result && (
        <div className="result">
          <div className="result__row">
            <div>
              <div className="label">Brewery</div>
              <div className="value">{result.manual ? titleCase(result.brewery) : result.brewery}</div>
            </div>
            <div>
              <div className="label">Beer</div>
              <div className="value">{sentenceCase(result.beer)}</div>
            </div>
            {(!result.manual || result.abv != null) && (
              <div>
                <div className="label">ABV</div>
                <div className="value">{result.abv}%</div>
              </div>
            )}
          </div>
          {result.manual ? (
            <div className="result__details">
              <div className="label">Added manually</div>
              {result.description && <div className="value manual-description">{result.description}</div>}
              <div className="hint">
                The AI has no details for this beer. What you entered is saved, and you can add your own notes below.
              </div>
            </div>
          ) : (
            <>
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
            </>
          )}
          {user && (
            <div className="personal">
              <div className="label">Your Beer Tracker</div>
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
                      type="date"
                      value={dateTasted}
                      max={todayIso}
                      onChange={(e) => setDateTasted(e.target.value)}
                      onFocus={(e) => openDatePicker(e.target)}
                      onClick={(e) => openDatePicker(e.target)}
                    />
                  </div>
                </label>
                <label className="field">
                  <span>{`Photos (max ${MAX_PHOTOS} per beer)`}</span>
                  <div className="photo-upload">
                    <input
                      type="file"
                      accept="image/png,image/jpeg,image/gif"
                      multiple
                      onChange={(e) => {
                        handleUpload(e.target.files)
                        e.target.value = ''
                      }}
                      disabled={uploading || photos.length >= MAX_PHOTOS}
                    />
                  </div>
                </label>
                {photos.length > 0 && (
                  <div className="photo-list">
                    {photos.map((name) => (
                      <PhotoThumb
                        key={name}
                        filename={name}
                        onOpen={(src) => setLightbox({ src, alt: name })}
                      />
                    ))}
                  </div>
                )}
                <label className="field">
                  <span>Personal tasting notes</span>
                  <textarea
                    value={userNotes}
                    onChange={(e) => setUserNotes(e.target.value)}
                    placeholder="Tasting Notes"
                    rows={4}
                  />
                </label>
                <div className="actions-row">
                  <button className="primary" type="button" onClick={handleSave} disabled={logLoading}>
                    {logLoading ? 'Saving...' : 'Save log'}
                  </button>
                  {saveStatus && <span className="success">{saveStatus}</span>}
                  {saveError && <span className="error inline">{saveError}</span>}
                  <button className="ghost" type="button" onClick={clearSearch}>
                    New search
                  </button>
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
