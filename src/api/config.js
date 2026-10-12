let cachedConfig = null
let loadPromise = null

/**
 * @typedef {Object} AppConfig
 * @property {string} apiBase Base URL of the PHP API, e.g. http://localhost/api
 * @property {boolean} [debug] When true, show request ids / server detail in errors and log them to the console.
 * @property {boolean} [allowRegistration] When true, show the "Create account" option (the server enforces its own flag).
 */

/**
 * Fetch and cache public/.config.json (see public/.config.example.json for the template).
 * Must resolve before any component reads getConfig(). Note this file is
 * downloaded by every visitor's browser, so it must never hold secrets.
 * @returns {Promise<AppConfig>}
 */
export function loadConfig() {
  if (cachedConfig) return Promise.resolve(cachedConfig)
  if (!loadPromise) {
    loadPromise = fetch('/.config.json')
      .then((res) => {
        if (!res.ok) throw new Error(`Failed to load .config.json (HTTP ${res.status})`)
        return res.json()
      })
      .then((data) => {
        cachedConfig = data
        return cachedConfig
      })
  }
  return loadPromise
}

/**
 * Synchronously read the already-loaded config. Throws if loadConfig() has not resolved yet.
 * @returns {AppConfig}
 */
export function getConfig() {
  if (!cachedConfig) {
    throw new Error('Config not loaded yet. Call loadConfig() before rendering the app.')
  }
  return cachedConfig
}
