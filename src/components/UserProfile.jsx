import { useEffect, useState, useRef } from 'react'
import Avatar from './Avatar'
import PasswordField from './PasswordField'
import { getUserProfile, updateUserProfile } from '../api/endpoints.js'

const MAX_AVATAR_BYTES = 10 * 1024 * 1024
const ALLOWED_TYPES = ['image/png', 'image/jpeg', 'image/gif']

/**
 * User profile panel (collapsible) for updating name, avatar, and password.
 * @param {Object} props
 * @param {{id:number,name?:string,email?:string,avatar_base64?:string}} props.user Authenticated user.
 * @param {(u: any) => void} [props.onUserUpdated] Callback with fresh user data after save/fetch.
 */
export default function UserProfile({ user, onUserUpdated }) {
  const [name, setName] = useState(user?.name || '')
  const [email, setEmail] = useState(user?.email || '')
  const [avatar, setAvatar] = useState(user?.avatar_base64 || '')
  const [avatarDirty, setAvatarDirty] = useState(false)
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [collapsed, setCollapsed] = useState(true)
  const avatarDirtyRef = useRef(false)

  useEffect(() => {
    avatarDirtyRef.current = avatarDirty
  }, [avatarDirty])

  useEffect(() => {
    if (!user) return
    const fetchProfile = async () => {
      setError('')
      try {
        const data = await getUserProfile()
        if (avatarDirtyRef.current) {
          return
        }
        setName(data.name || '')
        setEmail(data.email || '')
        setAvatar(data.avatar_base64 || '')
        setAvatarDirty(false)
        if (onUserUpdated) onUserUpdated(data)
      } catch (err) {
        setError(err.message)
      }
    }
    fetchProfile()
    // We intentionally skip onUserUpdated to avoid refetch loops.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user])

  const handleFile = (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    setError('')
    avatarDirtyRef.current = true
    setAvatarDirty(true)
    if (!ALLOWED_TYPES.includes(file.type)) {
      setError('Avatar must be PNG, JPG, or GIF')
      setAvatarDirty(false)
      avatarDirtyRef.current = false
      return
    }
    if (file.size > MAX_AVATAR_BYTES) {
      setError('Avatar exceeds 10MB limit')
      setAvatarDirty(false)
      avatarDirtyRef.current = false
      return
    }
    const reader = new FileReader()
    reader.onload = () => {
      setAvatar(reader.result || '')
      // keep avatarDirty true until saved or cleared
    }
    reader.readAsDataURL(file)
  }

  const handleClearAvatar = () => {
    setError('')
    setAvatar('')
    setAvatarDirty(true)
    avatarDirtyRef.current = true
  }

  const handleSave = async () => {
    if (!user) return
    setError('')
    setStatus('')

    const payload = {}
    let changes = 0

    if (name && name !== user.name) {
      payload.name = name
      changes += 1
    }

    if (avatarDirty) {
      payload.avatar_base64 = avatar || null
      changes += 1
    }

    if (newPassword) {
      if (!currentPassword) {
        setError('Enter your current password to set a new one')
        return
      }
      payload.current_password = currentPassword
      payload.new_password = newPassword
      changes += 1
    }

    if (!changes) {
      setError('Nothing to update')
      return
    }

    setLoading(true)
    try {
      const data = await updateUserProfile(payload)
      setStatus('Saved')
      setAvatar(data.avatar_base64 || '')
      setAvatarDirty(false)
      setCurrentPassword('')
      setNewPassword('')
      if (onUserUpdated) onUserUpdated(data)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="panel profile-panel">
      <button
        className="panel__header profile__toggle"
        type="button"
        onClick={() => setCollapsed((v) => !v)}
        aria-expanded={!collapsed}
      >
        <div className="pill">Your Profile</div>
        <div className="profile__header-right">
          {status && <div className="success">{status}</div>}
          <span className={`chevron ${collapsed ? '' : 'chevron--open'}`} aria-hidden="true">
            ▼
          </span>
        </div>
      </button>
      {!collapsed && (
        <div className="personal__fields">
          <label className="field">
            <span>Name</span>
            <input value={name} onChange={(e) => setName(e.target.value)} />
          </label>
          <label className="field">
            <span>Email</span>
            <input value={email} readOnly />
          </label>
          <div className="field">
            <span>Avatar (max 10MB, PNG/JPG/GIF)</span>
            <div className="profile__avatar-row">
              <Avatar src={avatar} name={name} email={email} shape="square" emptyLabel="No avatar" />
              <div className="profile__avatar-actions">
                <input type="file" accept="image/png,image/jpeg,image/gif" onChange={handleFile} />
                <button className="ghost" type="button" onClick={handleClearAvatar}>
                  Clear avatar
                </button>
              </div>
            </div>
          </div>
          <div className="field">
            <span>Change password</span>
            <PasswordField
              placeholder="Current password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              autoComplete="current-password"
            />
            <PasswordField
              placeholder="New password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              autoComplete="new-password"
            />
          </div>
          {error && <div className="error">{error}</div>}
          <div className="actions-row">
            <button className="primary" type="button" onClick={handleSave} disabled={loading}>
              {loading ? 'Saving...' : 'Save changes'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
