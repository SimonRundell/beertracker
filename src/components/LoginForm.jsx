import { useState } from 'react'
import PasswordField from './PasswordField'

/**
 * Login form for existing users.
 * @param {Object} props
 * @param {boolean} props.busy Disable submit while auth in-flight.
 * @param {string} props.error Error message to display.
 * @param {(creds: {email:string, password:string}) => void} props.onSubmit Submit handler.
 * @param {() => void} props.onSwitch Switch to register view.
 */
export default function LoginForm({ busy, error, onSubmit, onSwitch }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  const handleSubmit = (e) => {
    e.preventDefault()
    onSubmit({ email, password })
  }

  return (
    <div className="panel">
      <div className="panel__header">
        <div className="pill">Login</div>
        <button className="ghost" type="button" onClick={onSwitch}>
          Create account
        </button>
      </div>
      <form className="form" onSubmit={handleSubmit}>
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
          <PasswordField
            name="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        {error && <div className="error">{error}</div>}
        <button className="primary topgap" type="submit" disabled={busy}>
          {busy ? 'Working...' : 'Sign in'}
        </button>
      </form>
    </div>
  )
}
