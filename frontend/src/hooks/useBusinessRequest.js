import { useRef } from 'react'
import api from '../services/api'
import { useAuth } from './useAuth'

// Keep the same UUID while the result is unknown; never rotate it on timeout/retry.
export function useBusinessRequest() {
  const { user } = useAuth()
  const state = useRef({ pending: null, busy: false, scope: null })
  const submit = async (endpoint, payload) => {
    if (state.current.busy) return null
    const storageKey = `business-request:${user?.id}:${endpoint}`
    if (state.current.scope !== storageKey) {
      state.current.pending = null
      state.current.scope = storageKey
    }
    const stored = sessionStorage.getItem(storageKey)
    if (stored && !state.current.pending) state.current.pending = JSON.parse(stored)
    const firstAttempt = !state.current.pending
    const signature = JSON.stringify({ endpoint, payload })
    if (state.current.pending && state.current.pending.signature !== signature) {
      const error = new Error('Request sebelumnya belum dipastikan. Ulangi dengan data semula sebelum membuat operasi baru.')
      error.response = { status: 409, data: { message: error.message } }
      throw error
    }
    if (!state.current.pending) {
      if (!globalThis.crypto?.getRandomValues) throw new Error('Browser tidak mendukung UUID yang aman.')
      const bytes = crypto.getRandomValues(new Uint8Array(16))
      bytes[6] = (bytes[6] & 15) | 64
      bytes[8] = (bytes[8] & 63) | 128
      const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('')
      const id = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
      state.current.pending = { id, signature }
      sessionStorage.setItem(storageKey, JSON.stringify(state.current.pending))
    }
    state.current.busy = true
    try {
      const result = await api.post(endpoint, { ...payload, request_id: state.current.pending.id })
      state.current.pending = null // Acknowledged success: the next operation gets a new UUID.
      sessionStorage.removeItem(storageKey)
      return result
    } catch (error) {
      // Only a first-attempt validation failure proves this operation did not
      // commit. A retry's 401/403 must not discard an earlier uncertain success.
      if (firstAttempt && error.response?.status === 422) {
        state.current.pending = null
        sessionStorage.removeItem(storageKey)
      }
      throw error
    } finally {
      state.current.busy = false
    }
  }
  return { submit, isBusy: () => state.current.busy }
}
