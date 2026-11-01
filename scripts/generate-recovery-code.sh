#!/usr/bin/env bash
# Membuat (atau mengganti) kode pemulihan toko untuk fitur "Lupa password" di halaman login.
#
# Kode asli hanya ditampilkan SEKALI di layar ini. Yang disimpan di server hanya
# hash-nya (bcrypt), jadi kalau kode hilang tinggal jalankan skrip ini lagi.
#
# Pakai: ./scripts/generate-recovery-code.sh
set -euo pipefail

cd "$(dirname "$0")/.."

if ! docker compose ps --status running --services 2>/dev/null | grep -qx app; then
  echo "Container 'app' belum berjalan. Jalankan dulu: docker compose up -d app" >&2
  exit 1
fi

# 1. Kode acak berformat SC-XXXX-XXXX (mudah dibaca, sulit ditebak).
RAW=$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n' | tr 'a-f' 'A-F')
CODE="SC-${RAW:0:4}-${RAW:4:4}"
NORMALIZED=$(printf '%s' "$CODE" | tr -cd 'A-Z0-9')

# 2. Hitung hash bcrypt memakai PHP yang sama dengan aplikasi.
HASH=$(docker compose exec -T app php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$NORMALIZED")

# 3. Tulis hash ke .env (ganti baris lama bila sudah ada).
python3 - "$HASH" <<'PY'
import re, sys, pathlib
hash_value = sys.argv[1]
path = pathlib.Path('.env')
text = path.read_text() if path.exists() else ''
line = f"RECOVERY_CODE_HASH='{hash_value}'"
if re.search(r'^RECOVERY_CODE_HASH=', text, flags=re.M):
    text = re.sub(r'^RECOVERY_CODE_HASH=.*$', line, text, flags=re.M)
else:
    if text and not text.endswith('\n'):
        text += '\n'
    text += line + '\n'
path.write_text(text)
PY

# 4. Terapkan ke container.
docker compose up -d app >/dev/null

echo
echo "==============================================="
echo "  KODE PEMULIHAN TOKO (catat, hanya tampil ini):"
echo
echo "      $CODE"
echo
echo "==============================================="
echo "Simpan di tempat aman. Kode lama sudah tidak berlaku."
echo "Ganti kapan saja dengan menjalankan skrip ini lagi."
