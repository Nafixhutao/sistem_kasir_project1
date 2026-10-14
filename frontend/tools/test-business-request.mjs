// No browser automation or extra packages: exercise the hook with isolated adapters.
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { webcrypto } from 'node:crypto'

const source = readFileSync(new URL('../src/hooks/useBusinessRequest.js', import.meta.url), 'utf8')
  .replace(/^import .*$/gm, '').replace('export function', 'function')

function fixture(post, storage = new Map(), userId = 123) {
  let ref
  const sessionStorage = {
    getItem: key => storage.get(key) ?? null,
    setItem: (key, value) => storage.set(key, value),
    removeItem: key => storage.delete(key),
  }
  const context = vm.createContext({
    useRef: initial => ref ??= { current: initial }, useAuth: () => ({ user: { id: userId } }),
    api: { post }, crypto: webcrypto, sessionStorage,
  })
  vm.runInContext(`${source}\nthis.hook = useBusinessRequest();`, context)
  return context.hook
}

test('double submit shares one operation; successful next submit uses a new UUID', async () => {
  const calls = []; let resolve
  const hook = fixture((url, payload) => { calls.push(payload); return new Promise(r => { resolve = r }) })
  const first = hook.submit('/penjualan', { items: [1] })
  assert.equal(await hook.submit('/penjualan', { items: [1] }), null)
  assert.equal(calls.length, 1)
  assert.match(calls[0].request_id, /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/)
  resolve({ data: {} }); await first
  const next = hook.submit('/penjualan', { items: [1] })
  assert.notEqual(calls[1].request_id, calls[0].request_id)
  resolve({ data: {} }); await next
})

test('timeout retry and remount keep the UUID; different pending payload is refused', async () => {
  const storage = new Map(); const calls = []
  const fail = (url, payload) => { calls.push(payload); return Promise.reject(new Error('simulated timeout')) }
  const hook = fixture(fail, storage)
  await assert.rejects(hook.submit('/penjualan', { items: [1] }))
  await assert.rejects(hook.submit('/penjualan', { items: [1] }))
  assert.equal(calls[0].request_id, calls[1].request_id)
  await assert.rejects(hook.submit('/penjualan', { items: [2] }), /belum dipastikan/)
  assert.equal(calls.length, 2)
  const resumed = fixture((url, payload) => { calls.push(payload); return Promise.resolve({ data: {} }) }, storage)
  await resumed.submit('/penjualan', { items: [1] })
  assert.equal(calls[2].request_id, calls[0].request_id)
  assert.equal(storage.size, 0)
})

test('422 permits correcting an uncommitted payload; account scopes remain separate', async () => {
  const storage = new Map(); const calls = []
  const hook = fixture((url, payload) => { calls.push(payload); return calls.length === 1
    ? Promise.reject({ response: { status: 422 } }) : Promise.resolve({ data: {} }) }, storage)
  await assert.rejects(hook.submit('/retur-supplier', { quantity: 0 }))
  assert.equal(storage.size, 0)
  await hook.submit('/retur-supplier', { quantity: 1 })
  assert.notEqual(calls[0].request_id, calls[1].request_id)
  const failed = fixture((url, payload) => { calls.push(payload); return Promise.reject(new Error('timeout')) }, storage, 456)
  await assert.rejects(failed.submit('/penjualan', { items: [1] }))
  const other = fixture((url, payload) => { calls.push(payload); return Promise.resolve({ data: {} }) }, storage, 789)
  await other.submit('/penjualan', { items: [1] })
  assert.notEqual(calls[2].request_id, calls[3].request_id)
})
