import { useState } from 'react'

/**
 * Password input with a show/hide toggle button (eye icon).
 * @param {Object} props
 * @param {string} [props.name]
 * @param {string} props.value
 * @param {(e: React.ChangeEvent<HTMLInputElement>) => void} props.onChange
 * @param {string} [props.autoComplete]
 * @param {string} [props.placeholder]
 * @param {boolean} [props.required]
 */
export default function PasswordField({ name, value, onChange, autoComplete, placeholder, required }) {
  const [visible, setVisible] = useState(false)

  return (
    <div className="input-clearable">
      <input
        name={name}
        type={visible ? 'text' : 'password'}
        autoComplete={autoComplete}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        required={required}
      />
      <button
        type="button"
        className="input-clear"
        onClick={() => setVisible((v) => !v)}
        aria-label={visible ? 'Hide password' : 'Show password'}
        aria-pressed={visible}
      >
        {visible ? (
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.8 21.8 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.8 21.8 0 0 1-3.22 4.53M14.12 14.12a3 3 0 1 1-4.24-4.24" />
            <line x1="1" y1="1" x2="23" y2="23" />
          </svg>
        ) : (
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
            <circle cx="12" cy="12" r="3" />
          </svg>
        )}
      </button>
    </div>
  )
}
