import { apiClient } from './client.js'

/**
 * Log in with email/password.
 * @returns {Promise<{token:string,id:number,name:string,email:string,status:string,avatar_base64:string|null}>}
 */
export function login(email, password) {
  return apiClient.post('/login.php', { email, password }).then((res) => res.data)
}

/**
 * Register a new account.
 * @returns {Promise<{token:string,id:number,name:string,email:string,status:string,avatar_base64:string|null}>}
 */
export function register(name, email, password) {
  return apiClient.post('/register.php', { name, email, password }).then((res) => res.data)
}

/**
 * Run an AI-assisted beer lookup.
 * @param {string} prompt Beer name, or "Beer - Brewery".
 */
export function searchBeer(prompt) {
  return apiClient.post('/beer.php', { prompt }).then((res) => res.data)
}

/**
 * List candidate beer+brewery matches for a bare beer name (no brewery),
 * prioritized toward UK breweries when ambiguous — used to drive a
 * "Did you mean...?" picker before committing to a full lookup.
 * @param {string} beerName
 * @returns {Promise<{candidates: Array<{beer:string,brewery:string,country:string}>}>}
 */
export function searchBeerCandidates(beerName) {
  return apiClient.post('/beer.php', { prompt: beerName, mode: 'candidates' }).then((res) => res.data)
}

/** List every beer the current user has logged. */
export function listUserBeers() {
  return apiClient.get('/user_beers.php').then((res) => res.data)
}

/** Fetch the current user's personal log entry for one beer, if any. */
export function getUserBeerEntry(beer, brewery) {
  return apiClient.get('/user_beers.php', { params: { beer, brewery } }).then((res) => res.data)
}

/** Create or update the current user's personal log entry for one beer. */
export function saveUserBeer(entry) {
  return apiClient.post('/user_beers.php', entry).then((res) => res.data)
}

/** Upload a tasting photo for one beer (max ~10MB, PNG/JPG/GIF). */
export function uploadUserBeerPhoto({ beer, brewery, file }) {
  const form = new FormData()
  form.append('beer', beer)
  form.append('brewery', brewery)
  form.append('photo', file)
  return apiClient.post('/upload_user_beer_photo.php', form).then((res) => res.data)
}

/** Delete a previously uploaded tasting photo. */
export function deleteUserBeerPhoto({ beer, brewery, filename }) {
  const form = new FormData()
  form.append('beer', beer)
  form.append('brewery', brewery)
  form.append('filename', filename)
  return apiClient.post('/delete_user_beer_photo.php', form).then((res) => res.data)
}

/** Fetch the current user's profile (name, email, avatar). */
export function getUserProfile() {
  return apiClient.get('/user_profile.php').then((res) => res.data)
}

/** Update the current user's profile (name, avatar, and/or password). */
export function updateUserProfile(payload) {
  return apiClient.post('/user_profile.php', payload).then((res) => res.data)
}
