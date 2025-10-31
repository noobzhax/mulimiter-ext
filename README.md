## MulImiter
[![Forked from tegohsx/mulimiter](https://img.shields.io/badge/forked%20from-tegohsx%2Fmulimiter-blue?logo=github)](https://github.com/tegohsx/mulimiter)
[![Maintained by noobzhax](https://img.shields.io/badge/maintained%20by-noobzhax-success?logo=github)](https://github.com/noobzhax/mulimiter-ext)

OpenWrt bandwidth limiter using iptables hashlimit with a lightweight PHP GUI.

This repository is a fork of the original MulImiter project, with enhancements and maintenance for additional features and usability.

**Overview**
- Uses `iptables` with `-m hashlimit` to throttle per‑IP traffic.
- Simple PHP UI served from `http://<router-ip>/mulimiter` and a LuCI entry under Services -> MulImiter.
- Rules persist via a small hook in `/etc/firewall.user`.

**Key Features**
- Per‑IP download and upload limits
- Time range and weekday scheduling
- Toggle enable/disable per rule
- Disabled Rules list with re‑enable/delete
- Toggle enable/disable per IP range
- Checkboxes per row + Select All
- Bulk actions: Disable/Delete (active), Enable/Delete (disabled)
- Backup and Restore settings (JSON)

**Requirements**
- `iptables-mod-hashlimit`
- `iptables-mod-iprange`
- `git` (for install/update)

**Install**
- Install dependencies:
  - `opkg update && opkg install iptables-mod-hashlimit iptables-mod-iprange git git-http`
- Clone and run installer:
  - `git clone https://github.com/noobzhax/mulimiter-ext.git && cd mulimiter-ext && sh ./installer`
- If `git` is unavailable, download ZIP from GitHub, extract, then run `sh ./installer` inside the folder.

**Access**
- Web: `http://your_openwrt_ip_address/mulimiter`
- LuCI: Services -> MulImiter
- Default password: `1234` (change it under Setting)

**How It Works**
- Adds two `FORWARD` rules per limiter (download via `--dst-range`, upload via `--src-range`).
- Enforces thresholds with `-m hashlimit --hashlimit-above <rate> --hashlimit-mode {dstip|srcip}` and `-j DROP` when exceeded.
- Optional `-m time --timestart/--timestop --weekdays` for scheduling.
- Persists current rules to `/root/.mulimiter/save` and reapplies via `/root/.mulimiter/run` in `/etc/firewall.user`.

**Quick Start**
- Add a rule: enter IP or range, set D/U speed (kB/s), optional time and weekdays, click Add.
- Toggle: use Disable/Enable buttons per rule or per range.
- Bulk: check rows, then use the bulk action buttons under the table.
- Manage password in Setting.
- Backup/Restore: open Setting -> Backup & Restore to download a JSON backup or restore from a previously saved JSON.

## Full Guide (English)

**UI Overview**
- Home: create and manage active limit rules; includes bulk actions and per‑range controls.
- Disabled Rules: listed at the bottom of Home; enable or delete from here.
- Setting: change password, Backup & Restore settings to/from JSON.
- Docs: in‑app documentation (English and Bahasa Indonesia).

**Create a Rule**
- IP/Range: either a single IPv4 address (e.g. `192.168.1.10`) or a range `start-end` (e.g. `192.168.1.10-192.168.1.50`).
- D Speed (kB/s): max download rate threshold per destination IP.
- U Speed (kB/s): max upload rate threshold per source IP.
- Time (optional): 24h format start/end (e.g. `08:00 - 22:00`). Leave empty for all time.
- Days (optional): select weekdays. Leave empty or select all for everyday.

When a rule is created, the app adds two iptables rules in `FORWARD` chain:
- Download: `--dst-range <range> -m hashlimit --hashlimit-above <D>kb/s --hashlimit-mode dstip -j DROP`
- Upload: `--src-range <range> -m hashlimit --hashlimit-above <U>kb/s --hashlimit-mode srcip -j DROP`

These are persisted to `/root/.mulimiter/save` for auto‑apply at firewall reload/boot.

**Manage Rules**
- Edit: updates parameters; the app adds a new pair then removes the previous pair.
- Delete: removes both rules and their entries from storage.
- Disable/Enable per rule: moves rules between `save` (active) and `disabled` stores and applies/removes from iptables.
- Disable/Enable per range: disables/enables all rules that match an IP range in one click.
- Bulk actions: check multiple rows and use buttons below the tables (Active: Disable/Delete; Disabled: Enable/Delete).

Notes:
- The app tolerates partial pairs (e.g., only download or only upload rule present) and will operate on whichever side exists.
- Units are kB/s in the UI; iptables uses `kb/s` under the hood.

**Backup & Restore**
- Backup: Setting -> “Download Backup (JSON)”. Contains arrays `save` (active) and `disabled` (disabled) of raw iptables `-A` rules.
- Restore: select a previously saved JSON file; the app clears existing MulImiter rules, writes `save`/`disabled`, and reapplies `save` rules.

Example JSON payload:

```
{
  "version": "1.0",
  "generated_at": "2025-01-01T12:00:00Z",
  "save": ["-A FORWARD -m iprange --dst-range 192.168.1.10-192.168.1.50 -m hashlimit --hashlimit-above 500kb/s --hashlimit-mode dstip --hashlimit-name mulimiter_d... -j DROP", "-A FORWARD ... mulimiter_u... -j DROP"],
  "disabled": []
}
```

**Security & Best Practices**
- Change the default password on first login.
- Prefer accessing the UI via LuCI auth; or protect the endpoint with firewall rules.
- Keep speeds realistic; extremely low thresholds can degrade UX.

**Troubleshooting**
- “Invalid bulk payload”: ensure you selected at least one row; single‑row bulk is supported.
- Disabled rule not listed: refresh; app lists partial pairs and reads storage using robust I/O.
- Rules not effective: confirm rules appear in `iptables -S` at the top of `FORWARD`.

**Limitations**
- IPv4 only; no ip6tables/nftables yet.
- Drop‑based limiting with hashlimit; for shaping, integrate with `tc`.

## Dokumentasi Lengkap (Bahasa Indonesia)

**Ringkasan UI**
- Home: tambah dan kelola aturan aktif; termasuk aksi bulk dan kontrol per‑rentang.
- Disabled Rules: daftar aturan nonaktif di bagian bawah Home; enable atau hapus di sini.
- Setting: ganti password, Backup & Restore pengaturan ke/dari JSON.
- Docs: dokumentasi dalam aplikasi (English dan Bahasa Indonesia).

**Membuat Aturan**
- IP/Range: alamat IPv4 tunggal (mis. `192.168.1.10`) atau rentang `awal-akhir` (mis. `192.168.1.10-192.168.1.50`).
- D Speed (kB/s): batas kecepatan unduh per IP tujuan.
- U Speed (kB/s): batas kecepatan unggah per IP sumber.
- Time (opsional): format 24 jam, mis. `08:00 - 22:00`. Kosongkan untuk sepanjang waktu.
- Days (opsional): pilih hari; kosongkan atau pilih semua untuk setiap hari.

Ketika aturan dibuat, aplikasi menambahkan dua rule iptables di chain `FORWARD`:
- Download: `--dst-range <range> -m hashlimit --hashlimit-above <D>kb/s --hashlimit-mode dstip -j DROP`
- Upload: `--src-range <range> -m hashlimit --hashlimit-above <U>kb/s --hashlimit-mode srcip -j DROP`

Rule disimpan ke `/root/.mulimiter/save` agar otomatis diterapkan saat boot/reload firewall.

**Kelola Aturan**
- Edit: memperbarui parameter; aplikasi menambah pasangan baru lalu menghapus pasangan lama.
- Delete: menghapus kedua rule dan entri penyimpanannya.
- Disable/Enable per aturan: memindahkan rule antara `save` (aktif) dan `disabled` serta menerapkan/menghapus di iptables.
- Disable/Enable per rentang: nonaktifkan/aktifkan semua rule yang cocok dengan rentang IP.
- Aksi bulk: centang beberapa baris lalu gunakan tombol di bawah tabel (Aktif: Disable/Delete; Disabled: Enable/Delete).

Catatan:
- Aplikasi toleran terhadap pasangan parsial (hanya download atau upload) dan tetap memproses sisi yang tersedia.
- Satuan di UI kB/s; iptables menggunakan `kb/s`.

**Backup & Restore**
- Backup: Setting -> “Download Backup (JSON)”. Berisi array `save` (aktif) dan `disabled` (nonaktif) berupa rule iptables `-A` mentah.
- Restore: pilih file JSON yang disimpan; aplikasi membersihkan rule MulImiter eksisting, menulis `save`/`disabled`, lalu menerapkan ulang rule `save`.

Contoh JSON:

```
{
  "version": "1.0",
  "generated_at": "2025-01-01T12:00:00Z",
  "save": ["-A FORWARD -m iprange --dst-range 192.168.1.10-192.168.1.50 -m hashlimit --hashlimit-above 500kb/s --hashlimit-mode dstip --hashlimit-name mulimiter_d... -j DROP", "-A FORWARD ... mulimiter_u... -j DROP"],
  "disabled": []
}
```

**Keamanan & Saran**
- Segera ganti password default.
- Akses UI melalui autentikasi LuCI; atau batasi aksesnya di firewall.
- Gunakan nilai kecepatan yang realistis; ambang terlalu rendah membuat koneksi tidak nyaman.

**Pemecahan Masalah**
- “Invalid bulk payload”: pastikan setidaknya ada satu baris yang dicentang; satu baris juga didukung.
- Rule yang dinonaktifkan tidak muncul: lakukan refresh; app membaca penyimpanan lewat I/O yang andal dan menampilkan pasangan parsial.
- Rule tidak berpengaruh: pastikan rule muncul di `iptables -S` dan berada di atas chain `FORWARD`.

**Upgrade**
- Backup rules: `cp /root/.mulimiter/save /root/.mulimiter/save.bak`
- Pull latest and rerun installer:
  - `cd /tmp && rm -rf mulimiter-ext && git clone https://github.com/noobzhax/mulimiter-ext.git`
  - `cd mulimiter-ext && sh ./installer`

**Uninstall**
- From UI: About -> Uninstall MulImiter
- Manual:
  - Remove hook from `/etc/firewall.user` (line containing `/root/.mulimiter/run`).
  - Flush MulImiter rules (`iptables -S` then delete rules containing `mulimiter`).
  - Remove folders: `/root/.mulimiter` and `/www/mulimiter`.

**Source Code**
- Fork: https://github.com/noobzhax/mulimiter-ext
- Upstream: https://github.com/tegohsx/mulimiter

**Credits**
- Original project: MulImiter by Teguh Santoso — https://github.com/tegohsx/mulimiter
- This fork: additional toggles, Disabled Rules, per‑range actions, bulk actions, and UI tweaks — https://github.com/noobzhax/mulimiter-ext

