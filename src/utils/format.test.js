import { describe, expect, it } from 'vitest'
import { formatDate, sentenceCase, titleCase, todayIso } from './format.js'

describe('sentenceCase', () => {
  it('lowercases then capitalises the first letter', () => {
    expect(sentenceCase('PALE ALE')).toBe('Pale ale')
  })
  it('handles empty input', () => {
    expect(sentenceCase('')).toBe('')
    expect(sentenceCase(null)).toBe('')
  })
})

describe('titleCase', () => {
  it('capitalises every word', () => {
    expect(titleCase('punk ipa')).toBe('Punk Ipa')
  })
})

describe('formatDate', () => {
  it('formats ISO dates as DD/MM/YYYY', () => {
    expect(formatDate('2026-08-13T12:00:00')).toBe('13/08/2026')
  })
  it('handles MySQL datetimes', () => {
    expect(formatDate('2026-08-13 07:26:17')).toBe('13/08/2026')
  })
  it('returns unparseable input unchanged and empty for empty', () => {
    expect(formatDate('not a date')).toBe('not a date')
    expect(formatDate('')).toBe('')
  })
})

describe('todayIso', () => {
  it('returns YYYY-MM-DD', () => {
    expect(todayIso()).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })
})
