// Locale id-ID memisahkan jam dengan titik (15.56.17), sedangkan yang
// ditampilkan ke pengguna memakai titik dua (15:56:17).
export function formatDateTime(value) {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '-'

  return date
    .toLocaleString('id-ID', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    })
    .replace(/\./g, ':')
    .replace(/,\s*/, ' ')
}
