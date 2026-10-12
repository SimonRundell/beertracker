import { useEffect, useState } from 'react'
import './App.css'

import LoginForm from './components/LoginForm'
import RegisterForm from './components/RegisterForm'
import BeerSearch from './components/BeerSearch'
import MyBeers from './components/MyBeers'
import UserProfile from './components/UserProfile'
import MOTD from './components/MOTD'
import Avatar from './components/Avatar'
import { login, logout, register, searchBeer, getUserProfile } from './api/endpoints.js'
import { setAuthToken, setUnauthorizedHandler } from './api/client.js'
import { getConfig } from './api/config.js'
import { clearSession, loadSession, saveSession } from './api/session.js'
import { clearPhotoCache } from './api/photos.js'

/** True when the AI reported the beer as unknown (placeholder names or a negative ABV). */
const isUnknownResult = (d) =>
  /^unknown$/i.test(d?.beer ?? '') || /^unknown$/i.test(d?.brewery ?? '') || Number(d?.abv) < 0

/**
 * Root application component that orchestrates auth, beer search, and user beer log layout.
 * Renders auth forms when logged out, otherwise shows profile + search (left) and beer list (right).
 */
function App() {
  const [mode, setMode] = useState('login')
  const [authBusy, setAuthBusy] = useState(false)
  const [user, setUser] = useState(null)
  // True while a saved session is being checked against the server on page load.
  const [booting, setBooting] = useState(() => Boolean(loadSession()))
  const [authError, setAuthError] = useState('')
  const [beerError, setBeerError] = useState('')
  const [beerResult, setBeerResult] = useState(null)
  const [searchBusy, setSearchBusy] = useState(false)
  const [beersVersion, setBeersVersion] = useState(0)

  const allowRegistration = Boolean(getConfig().allowRegistration)

  /** Drop all local session state (used by logout and by expired-session handling). */
  const endSession = () => {
    setAuthToken(null)
    clearSession()
    clearPhotoCache()
    setUser(null)
    setBeerResult(null)
    setBeerError('')
  }

  // Restore a saved login after a page refresh, verifying the token is still valid.
  useEffect(() => {
    const session = loadSession()
    if (!session) return
    setAuthToken(session.token)
    getUserProfile()
      .then((profile) => setUser({ ...session.user, ...profile, token: session.token }))
      .catch(() => {
        setAuthToken(null)
        clearSession()
      })
      .finally(() => setBooting(false))
  }, [])

  // If the server rejects our token mid-session (expired, logged out elsewhere), return to login.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      endSession()
      setAuthError('Your session has expired. Please log in again.')
    })
    return () => setUnauthorizedHandler(null)
  }, [])

  // Keep the saved session's name/email in step with profile edits.
  useEffect(() => {
    if (user) saveSession(user.token, user)
  }, [user])

  const handleLogin = async ({ email, password }) => {
    setAuthBusy(true)
    setAuthError('')
    try {
      const data = await login(email, password)
      setAuthToken(data.token)
      setUser({
        id: data.id,
        name: data.name || email,
        email: data.email || email,
        token: data.token,
        avatar_base64: data.avatar_base64 || null,
      })
    } catch (err) {
      setAuthError(err.message)
    } finally {
      setAuthBusy(false)
    }
  }

  const handleRegister = async ({ name, email, password }) => {
    setAuthBusy(true)
    setAuthError('')
    try {
      const data = await register(name, email, password)
      setAuthToken(data.token)
      setUser({
        id: data.id,
        name: data.name || name,
        email: data.email || email,
        token: data.token,
        avatar_base64: data.avatar_base64 || null,
      })
    } catch (err) {
      setAuthError(err.message)
    } finally {
      setAuthBusy(false)
    }
  }

  const handleSearch = async (query) => {
    setBeerError('')
    setBeerResult(null)
    setSearchBusy(true)
    try {
      const data = await searchBeer(query)
      if (isUnknownResult(data)) {
        // The AI answers "Unknown" for beers it can't identify; treat that as not found.
        setBeerError("Couldn't find that beer.")
      } else {
        setBeerResult(data)
      }
    } catch (err) {
      setBeerError(err.message)
    } finally {
      setSearchBusy(false)
    }
  }

  /** Show a hand-entered beer (already saved by the server) in place of the failed search. */
  const handleManualAdd = (manualBeer) => {
    setBeerError('')
    setBeerResult(manualBeer)
  }

  const handleClearSearch = () => {
    setBeerResult(null)
    setBeerError('')
  }

  const handleLogout = async () => {
    try {
      await logout() // invalidate the token server-side; local logout proceeds regardless
    } catch {
      // Already logged out or offline: nothing more to do.
    }
    endSession()
  }
  const handleUserUpdated = (updated) => {
    setUser((prev) => {
      if (!prev) return prev
      const next = { ...prev, ...updated }
      const unchanged =
        next.name === prev.name &&
        next.email === prev.email &&
        next.avatar_base64 === prev.avatar_base64 &&
        next.status === prev.status

      return unchanged ? prev : next
    })
  }


  const handleLogSaved = () => setBeersVersion((v) => v + 1)

  return (
    <div className="page">
      <header className="shell">
        <div className="brand">
          <img src="/images/beertracker512.png" alt="BeerTracker Logo" className="logo" />
          <div>
            <div className="eyebrow">BeerTracker</div>
            <h1>AI Assisted Beer Search</h1>
            <p className="lede">The power of the Internet and beer drinking collide!</p>
          </div>
        </div>
        {user && (
          <div className="user">
            <div className="user-chip">
              <Avatar src={user.avatar_base64} name={user.name} email={user.email} size="small" />
              <div className="user-meta">
                <div className="user-name">{user.name || user.email}</div>
                <div className="user-email">{user.email}</div>
              </div>
            </div>
            <button className="ghost" type="button" onClick={handleLogout}>
              Logout
            </button>
          </div>
        )}
      </header>

      <main className={user ? 'two-col' : 'grid'}>
        {booting ? (
          <div className="panel"><div className="hint">Restoring your session...</div></div>
        ) : !user ? (
          mode === 'login' || !allowRegistration ? (
            <LoginForm
              busy={authBusy}
              error={authError}
              onSubmit={handleLogin}
              onSwitch={
                allowRegistration
                  ? () => {
                      setMode('register')
                      setAuthError('')
                    }
                  : undefined
              }
            />
          ) : (
            <RegisterForm
              busy={authBusy}
              error={authError}
              onSubmit={handleRegister}
              onSwitch={() => {
                setMode('login')
                setAuthError('')
              }}
            />
          )
        ) : (
          <>
            <div className="column column--left">
              <UserProfile user={user} onUserUpdated={handleUserUpdated} />
              <BeerSearch
                onSearch={handleSearch}
                onClear={handleClearSearch}
                onManualAdd={handleManualAdd}
                result={beerResult}
                busy={searchBusy}
                error={beerError}
                user={user}
                onSaved={handleLogSaved}
              />
            </div>
            <div className="column column--right">
              <MyBeers user={user} refreshKey={beersVersion} />
            </div>
          </>
        )}
        {!user && !booting && <MOTD />}
      </main>
    </div>
  )
}

export default App
