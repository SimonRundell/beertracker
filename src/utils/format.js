/**
 * Lowercase a string and capitalise its first letter ("pale ALE" -> "Pale ale").
 * @param {string|null|undefined} str
 * @returns {string}
 */
export function sentenceCase(str) {
  if (!str) return ''
  const s = String(str).toLowerCase()
  return s.charAt(0).toUpperCase() + s.slice(1)
}

/**
 * Lowercase a string and capitalise every word ("punk IPA" -> "Punk Ipa").
 * @param {string|null|undefined} str
 * @returns {string}
 */
export function titleCase(str) {
  if (!str) return ''
  return String(str).toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase())
}

/**
 * Format a date (ISO string, SQL datetime or Date) as DD/MM/YYYY.
 * Returns the input untouched if it cannot be parsed, and '' if empty.
 * @param {string|Date|null|undefined} value
 * @returns {string}
 */
export function formatDate(value) {
  if (!value) return ''
  const d = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return String(value)
  const day = String(d.getDate()).padStart(2, '0')
  const month = String(d.getMonth() + 1).padStart(2, '0')
  return `${day}/${month}/${d.getFullYear()}`
}

/**
 * Today's date in the browser's local time as YYYY-MM-DD (for date input `max`).
 * @returns {string}
 */
export function todayIso() {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}
