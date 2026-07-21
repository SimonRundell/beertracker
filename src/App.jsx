import { useState } from 'react'
import './App.css'

import LoginForm from './components/LoginForm'
import RegisterForm from './components/RegisterForm'
import BeerSearch from './components/BeerSearch'
import MyBeers from './components/MyBeers'
import UserProfile from './components/UserProfile'
import MOTD from './components/MOTD'
import Avatar from './components/Avatar'
import { login, register, searchBeer } from './api/endpoints.js'
import { setAuthToken } from './api/client.js'

/**
 * Root application component that orchestrates auth, beer search, and user beer log layout.
 * Renders auth forms when logged out, otherwise shows profile + search (left) and beer list (right).
 */
function App() {
  const [mode, setMode] = useState('login')
  const [authBusy, setAuthBusy] = useState(false)
  const [user, setUser] = useState(null)
  const [authError, setAuthError] = useState('')
  const [beerError, setBeerError] = useState('')
  const [beerResult, setBeerResult] = useState(null)
  const [searchBusy, setSearchBusy] = useState(false)
  const [beersVersion, setBeersVersion] = useState(0)

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

  const handleSearch = async (prompt) => {
    setBeerError('')
    setBeerResult(null)
    setSearchBusy(true)
    try {
      const data = await searchBeer(prompt)
      setBeerResult(data)
    } catch (err) {
      setBeerError(err.message)
    } finally {
      setSearchBusy(false)
    }
  }

  const handleLogout = () => {
    setAuthToken(null)
    setUser(null)
    setBeerResult(null)
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
        {!user ? (
          mode === 'login' ? (
            <LoginForm
              busy={authBusy}
              error={authError}
              onSubmit={handleLogin}
              onSwitch={() => {
                setMode('register')
                setAuthError('')
              }}
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
        {!user && <MOTD />}
      </main>
    </div>
  )
}

export default App
