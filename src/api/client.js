import axios from 'axios'
import { getConfig } from './config.js'

let authToken = null

/**
 * Set (or clear with null) the bearer token attached to every subsequent request.
 * Call this after login/register succeeds, and again with null on logout.
 * @param {string|null} token
 */
export function setAuthToken(token) {
  authToken = token
}

/**
 * Shared axios instance for all API calls. baseURL is read lazily from
 * public/config.json (loaded once at startup, see api/config.js) so this
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

apiClient.interceptors.response.use(
  (res) => res,
  (err) => {
    const message =
      err.response?.data?.error || err.response?.data?.message || err.message || 'Request failed'
    return Promise.reject(new Error(message))
  }
)
