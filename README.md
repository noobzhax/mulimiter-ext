## MulImiter
[![Forked from tegohsx/mulimiter](https://img.shields.io/badge/forked%20from-tegohsx%2Fmulimiter-blue?logo=github)](https://github.com/tegohsx/mulimiter)
[![Maintained by noobzhax](https://img.shields.io/badge/maintained%20by-noobzhax-success?logo=github)](https://github.com/noobzhax/mulimiter-ext)
OpenWrt bandwidth limiter using iptables hashlimit with a lightweight PHP GUI.

This repository is a fork of the original MulImiter project, with enhancements and maintenance for additional features and usability.

**Overview**
- Uses `iptables` with `-m hashlimit` to throttle per‑IP traffic.
- Simple PHP UI served from `http://<router-ip>/mulimiter` and a LuCI entry under Services → MulImiter.
- Rules persist via a small hook in `/etc/firewall.user`.

**Key Features**
- Per‑IP download and upload limits
- Time range and weekday scheduling
- Toggle enable/disable per rule
- Disabled Rules list with re‑enable/delete
- Toggle enable/disable per IP range
- Checkboxes per row + Select All
- Bulk actions: Disable/Delete (active), Enable/Delete (disabled)

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
- LuCI: Services → MulImiter
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

**Upgrade**
- Backup rules: `cp /root/.mulimiter/save /root/.mulimiter/save.bak`
- Pull latest and rerun installer:
  - `cd /tmp && rm -rf mulimiter-ext && git clone https://github.com/noobzhax/mulimiter-ext.git`
  - `cd mulimiter-ext && sh ./installer`

**Uninstall**
- From UI: About → Uninstall MulImiter
- Manual:
  - Remove hook from `/etc/firewall.user` (line containing `/root/.mulimiter/run`).
  - Flush MulImiter rules (`iptables -S` then delete rules containing `mulimiter`).
  - Remove folders: `/root/.mulimiter` and `/www/mulimiter`.

**Security Notes**
- Change default password immediately.
- Limit access to the UI (e.g., via LuCI auth or firewall rules).
- Inputs are executed into `iptables`; keep values sane (valid IPv4, numeric speeds, correct times). Consider placing the UI behind LuCI for additional protection.

**Troubleshooting**
- Missing modules: ensure `iptables-mod-hashlimit` and `iptables-mod-iprange` are installed.
- Rules not persistent: verify `/etc/firewall.user` contains `/root/.mulimiter/run` and reload firewall (`/etc/init.d/firewall restart`).
- UI not loading: confirm files exist at `/www/mulimiter` and `php-cgi` is available; access from `http://<router-ip>/mulimiter`.
- No effect: verify rules appear in `iptables -S` and are at the top of `FORWARD` chain; check interface/zone path if you use non‑default topology.

**Limitations**
- IPv4 only (no `ip6tables`/`nftables` support yet).
- Hashlimit drops traffic above set rate (packet drop shaping), not fair‑queue shaping. For smoother control, a `tc`‑based approach would be required.

**Source Code**
- Fork: https://github.com/noobzhax/mulimiter-ext
- Upstream: https://github.com/tegohsx/mulimiter

**Credits**
- Original project: MulImiter by Teguh Santoso — https://github.com/tegohsx/mulimiter
- This fork: additional toggles, Disabled Rules, per‑range actions, bulk actions, and UI tweaks — https://github.com/noobzhax/mulimiter-ext
