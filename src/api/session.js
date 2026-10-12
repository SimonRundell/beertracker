const STORAGE_KEY = 'beertracker.session'

/**
 * Read the saved login session from localStorage.
 * @returns {{token:string,user:{id:number,name:string,email:string,status?:string}}|null}
 */
export function loadSession() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    const parsed = raw ? JSON.parse(raw) : null
    return parsed?.token && parsed?.user ? parsed : null
  } catch {
    return null
  }
}

/**
 * Save the login session so a page refresh does not log the user out.
 * The avatar is deliberately not stored (it can be large and is re-fetched).
 * @param {string} token
 * @param {{id:number,name:string,email:string,status?:string}} user
 */
export function saveSession(token, user) {
  try {
    const { id, name, email, status } = user
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ token, user: { id, name, email, status } }))
  } catch {
    // Storage may be blocked (private mode); the app still works for this tab.
  }
}

/** Remove any saved login session. */
export function clearSession() {
  try {
    window.localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Nothing to clear if storage is unavailable.
  }
}
