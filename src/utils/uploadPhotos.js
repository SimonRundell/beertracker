import { uploadUserBeerPhoto } from '../api/endpoints.js'

/** Maximum photos per beer (the server enforces the same limit). */
export const MAX_PHOTOS = 2

/**
 * Upload chosen files one at a time, up to the free photo slots for this beer,
 * showing a progress toast so the UI never looks frozen.
 * @param {Object} args
 * @param {import('../toast/toastContext.js').ToastApi} args.toast Toast API from useToast().
 * @param {string} args.beer Beer name.
 * @param {string} args.brewery Brewery name.
 * @param {FileList|File[]} args.files Files chosen by the user.
 * @param {string[]} args.currentPhotos Filenames already attached to this beer.
 * @param {(photos: string[]) => void} args.onPhotos Called with the full photo list after each successful upload.
 * @returns {Promise<{photos: string[], uploaded: number}>} Final photo list and how many files were uploaded.
 */
export async function uploadPhotos({ toast, beer, brewery, files, currentPhotos, onPhotos }) {
  const chosen = Array.from(files || [])
  const room = Math.max(0, MAX_PHOTOS - currentPhotos.length)
  const queue = chosen.slice(0, room)

  if (queue.length === 0) {
    if (chosen.length > 0) toast.show('error', `This beer already has ${MAX_PHOTOS} photos. Remove one to add another.`)
    return { photos: currentPhotos, uploaded: 0 }
  }

  const toastId = toast.show('loading', 'Uploading photo...')
  let latest = currentPhotos
  let uploaded = 0
  try {
    for (const [index, file] of queue.entries()) {
      const label = queue.length > 1 ? `Uploading photo ${index + 1} of ${queue.length}` : 'Uploading photo'
      toast.show('loading', `${label}...`, toastId)
      const data = await uploadUserBeerPhoto({
        beer,
        brewery,
        file,
        onProgress: (pct) => toast.show('loading', pct < 100 ? `${label}... ${pct}%` : `${label}... processing`, toastId),
      })
      latest = Array.isArray(data.photos) ? data.photos : latest
      uploaded += 1
      onPhotos(latest)
    }
    const skipped = chosen.length - queue.length
    toast.show(
      'success',
      skipped > 0
        ? `Uploaded ${uploaded}. ${skipped} skipped (max ${MAX_PHOTOS} photos per beer).`
        : uploaded === 1 ? 'Photo uploaded' : 'Photos uploaded',
      toastId
    )
  } catch (err) {
    toast.show('error', uploaded > 0 ? `Uploaded ${uploaded}, then failed: ${err.message}` : err.message, toastId)
  }
  return { photos: latest, uploaded }
}
