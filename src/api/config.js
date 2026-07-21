let cachedConfig = null
let loadPromise = null

/**
 * Fetch and cache public/config.json (see public/config.example.json for the template).
 * Must resolve before any component reads getConfig().
 * @returns {Promise<{apiBase: string}>}
 */
export function loadConfig() {
  if (cachedConfig) return Promise.resolve(cachedConfig)
  if (!loadPromise) {
    loadPromise = fetch('/config.json')
      .then((res) => {
        if (!res.ok) throw new Error(`Failed to load config.json (HTTP ${res.status})`)
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
 * @returns {{apiBase: string}}
 */
export function getConfig() {
  if (!cachedConfig) {
    throw new Error('Config not loaded yet — call loadConfig() before rendering the app.')
  }
  return cachedConfig
}
