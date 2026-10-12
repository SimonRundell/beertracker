/**
 * Open the browser's native calendar for a date input. showPicker() throws a
 * NotAllowedError when the call isn't caused by a user action (for example when
 * focus returns to the field after switching tabs), so that case is ignored:
 * the field still works normally and the next click opens the calendar.
 * @param {HTMLInputElement|null} input
 */
export function openDatePicker(input) {
  if (!input || typeof input.showPicker !== 'function') return
  try {
    input.showPicker()
  } catch {
    // Not triggered by a user gesture; nothing to do.
  }
}
