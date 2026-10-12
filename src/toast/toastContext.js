import { createContext, useContext } from 'react'

/**
 * @typedef {'loading'|'success'|'error'} ToastKind
 * @typedef {Object} ToastApi
 * @property {(kind: ToastKind, message: string, id?: number) => number} show Show a toast, or update the one with `id`. Returns its id. Success/error toasts dismiss themselves; loading toasts stay until updated or dismissed.
 * @property {(id: number) => void} dismiss Remove a toast.
 */

export const ToastContext = createContext(/** @type {ToastApi|null} */ (null))

/**
 * Access the toast API provided by <ToastProvider>.
 * @returns {ToastApi}
 */
export function useToast() {
  const api = useContext(ToastContext)
  if (!api) throw new Error('useToast must be used inside <ToastProvider>')
  return api
}
