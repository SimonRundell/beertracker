import axios from 'axios'
import { getConfig } from './config.js'

let authToken = null
let unauthorizedHandler = null

/**
 * Set (or clear with null) the bearer token attached to every subsequent request.
 * Call this after login/register succeeds, and again with null on logout.
 * @param {string|null} token
 */
export function setAuthToken(token) {
  authToken = token
}

/**
 * Register a callback fired when an authenticated request is rejected with 401
 * (expired or revoked token), so the app can drop back to the login screen.
 * @param {(() => void)|null} handler
 */
export function setUnauthorizedHandler(handler) {
  unauthorizedHandler = handler
}

/**
 * Shared axios instance for all API calls. baseURL is read lazily from
 * public/.config.json (loaded once at startup, see api/config.js) so this
 * module can be imported before loadConfig() resolves.
 */
export const apiClient = axios.create()

apiClient.interceptors.request.use((requestConfig) => {
  requestConfig.baseURL = getConfig().apiBase
  if (authToken) {
    requestConfig.headers.Authorization = `Bearer ${authToken}`
  }
  return requestConfig
})

/**
 * Turn an axios error into a readable Error. Server failures carry a
 * request_id to quote when reporting a problem; with "debug": true in
 * public/.config.json the server's detail is shown and everything is logged.
 */
apiClient.interceptors.response.use(
  (res) => res,
  (err) => {
    const data = err.response?.data
    let message = data?.error || data?.message || err.message || 'Request failed'
    if (!err.response && err.message === 'Network Error') {
      message = 'Cannot reach the server. Check your connection and try again.'
    }
    if (data?.request_id) message += ` (ref ${data.request_id})`

    const debug = Boolean(getConfig().debug)
    if (debug) {
      if (data?.detail) message += `: ${data.detail}`
      console.error('[beertracker api]', err.config?.method?.toUpperCase(), err.config?.url, err.response?.status, data ?? err)
    }

    if (err.response?.status === 401 && authToken && unauthorizedHandler) {
      unauthorizedHandler()
    }
    return Promise.reject(new Error(message))
  }
)
