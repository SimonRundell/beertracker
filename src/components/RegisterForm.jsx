import { useState } from 'react'

/**
 * Registration form for new users.
 * @param {Object} props
 * @param {boolean} props.busy Disable submit while auth in-flight.
 * @param {string} props.error Error message to display.
 * @param {(creds: {name:string, email:string, password:string}) => void} props.onSubmit Submit handler.
 * @param {() => void} props.onSwitch Switch to login view.
 */
export default function RegisterForm({ busy, error, onSubmit, onSwitch }) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  const handleSubmit = (e) => {
    e.preventDefault()
    onSubmit({ name, email, password })
  }

  return (
    <div className="panel">
      <div className="panel__header">
        <div className="pill">Register</div>
        <button className="ghost" type="button" onClick={onSwitch}>
          Have an account? Login
        </button>
      </div>
      <form className="form" onSubmit={handleSubmit}>
        <label className="field">
          <span>Name</span>
          <input
            name="name"
            autoComplete="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
        </label>
        <label className="field">
          <span>Email</span>
          <input
            name="email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </label>
        <label className="field">
          <span>Password</span>
          <input
            name="password"
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        {error && <div className="error">{error}</div>}
        <button className="primary" type="submit" disabled={busy}>
          {busy ? 'Working...' : 'Create account'}
        </button>
      </form>
      <p className="hint">Accounts are stored in the beertracker DB. Admin/user status handled server-side.</p>
    </div>
  )
}
