import { apiClient } from './client.js'

/** @type {Map<string, Promise<string>>} filename -> promise of an object URL */
const cache = new Map()

/**
 * Get a displayable URL for one of the current user's photos. Photos are not
 * publicly reachable (they need the bearer token), so each is downloaded once
 * through the API as a blob, and the resulting object URL is cached for reuse
 * by thumbnails and the lightbox.
 * @param {string} filename Stored photo filename.
 * @returns {Promise<string>}
 */
export function getPhotoUrl(filename) {
  if (!cache.has(filename)) {
    const request = apiClient
      .get('/photo.php', { params: { file: filename }, responseType: 'blob' })
      .then((res) => URL.createObjectURL(res.data))
      .catch((err) => {
        cache.delete(filename)
        throw err
      })
    cache.set(filename, request)
  }
  return cache.get(filename)
}

/** Release all cached photo object URLs (call on logout). */
export function clearPhotoCache() {
  for (const request of cache.values()) {
    request.then((url) => URL.revokeObjectURL(url)).catch(() => {})
  }
  cache.clear()
}
