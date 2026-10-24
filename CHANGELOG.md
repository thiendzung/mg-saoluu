## 1.3.0-rc4 — Exact database schema rollback (2026-10-25)

- Fix RC3 runtime blocker where checkpoint import restored baseline rows but left database tables created by migration.
- Persist the exact pre-Restore database table/view inventory in the restore journal.
- On explicit or automatic rollback: import checkpoint, remove extra schema objects, then require exact inventory equality before health/success.
- Fail closed when schema inventory is missing, unsafe, cannot be reconciled, or remains mismatched.
- Regression test reproduces the Agent Local evidence: 12 baseline objects → 83 after migration → remove 71 → exact 12 after rollback.
- Production mutation remains hard-blocked in RC4.

## 1.3.0-rc3 — Migration rebased on Stable Backup/Restore (2026-10-25)\n\n- Rebase the signed Local → fresh Production migration design from 1.3.0-rc2 onto the fully runtime-accepted v1.2.1 Backup/Restore tree.\n- Preserve RC7–RC9 hardening: truthful schedule/offsite status, transactional rollback, timezone-safe duration, healthy-baseline gate and restore-engine self-protection.\n- Migration requires canonical host, matching DB prefix, fresh target including Woo HPOS/order checks, HTTPS public target, paired Local Ed25519 signature bound to exact archive SHA/source/target, 10-minute single-use nonce, disabled WP-Cron and explicit external-I/O isolation.\n- RC3 still hard-blocks all writes to real Production; only isolated Staging QA may exercise mutation.\n- Performance: Local Backup tab reads migration candidates from metadata only; DB freshness checks run only on Stage/Apply actions.\n\n## 1.2.1 — Stable Backup & Restore (2026-10-24)

- Promote the runtime-accepted RC9 Backup/Restore tree to Stable; no Data Transfer/Deploy features are re-enabled.
- Production restore is same-host only: source environment/host must match, manifest signature must verify, a paired Local device must sign a 10-minute unlock, and the administrator must re-enter password, host and confirmation phrase.
- Retain transactional rollback, restore-engine self-protection, integrity verification, fail-closed ZIP/prefix/symlink/CLI/disk/operation-lock gates, private redacted diagnostics and honest scheduled/offsite status.
- Interrupted import remains not runtime-injected because no safe deterministic failure mechanism is available; partial import is mitigated by pre-restore checkpoint + rollback but is not claimed crash-atomic.

## 1.2.1-rc9 — Restore engine self-protection (2026-10-24)

- Full backup archives continue to include MG Sao Luu for offline recovery completeness.
- Runtime Restore/Rollback now skips the currently executing MG Sao Luu plugin directory during extraction, copy, new-file bookkeeping and cleanup, preventing an older backup from downgrading the active restore engine mid-transaction.
- UI explicitly documents that the running restore engine is preserved and must be restored separately from a trusted release ZIP if needed.
- Add regression coverage proving an archived RC7-style plugin payload cannot overwrite or delete an RC9 runtime plugin.
- RC8 transactional rollback and timezone fixes are retained; production restore remains blocked for RC builds.

## 1.2.1-rc8 — Transactional rollback remediation (2026-10-24)

- Require a healthy current target before minting the pre-restore rollback checkpoint; a DB that references a missing active theme fails closed before any restore write.
- An explicit rollback is recorded as `rolled_back` only after the checkpoint DB/files, restore health check and original new-file cleanup all succeed.
- If an explicit rollback fails but its guard backup restores successfully, preserve the original completed-restore journal/checkpoint and record `rollback_attempt_status=failed_reverted`; never report a false rollback success.
- Persist epoch timestamps for new restore transactions and compute legacy wall-clock durations in the WordPress timezone, fixing the UTC+7 +25,200-second UI error.
- Add regression coverage for unhealthy rollback baselines, truthful rollback recovery, cleanup evidence and duration math.
- Runtime re-acceptance on an isolated clone is required before merge or production restore.

## 1.2.1-rc7 — Cron observability & honest offsite status (2026-10-24)

- Track the last real SQL/full backup attempt and locally verified success separately from Google Drive upload status, without storing secrets or filenames.
- Flag missing/late cron events and overdue backups in Overview without polling or starting a job when the dashboard loads.
- An uploaded-but-not-downloaded Drive archive is explicitly unverified; a Drive failure is not reported as full offsite success.
- Add deterministic PHP regression tests for the schedule outcomes on source and extracted ZIP; production restore remains hard-blocked.
- Pending CI on exact SHA and Agent Local WordPress/DB acceptance; no stable release implied.

## 1.2.1-rc6 — Backup/restore UI and failure safety (2026-10-24)

- Prevent duplicate operations and show honest pending feedback; expose PHP upload limits and checkpoint/journal status.
- Verify complete encrypted/decrypted writes; atomically save restore journal; refuse new restore with unfinished/unknown journal.
- Check both working and WordPress destination volumes before restore; add bounded synthetic failure regressions.
- Candidate pending exact ZIP CI and clone runtime acceptance; production restore still hard-blocked.

## 1.2.1-rc5 — Correct UI rollback checkpoint association (2026-10-24)

- Fix upload-generated filename mismatch on rollback checkpoint restoration, binding to current journal and trusted/uploaded SHA-256 (PR #15).
- No backup format change. Stale or altered checkpoints remain refused.
- Source and extracted ZIP gates must pass; UI restore/rollback drill on an isolated clone still required for runtime acceptance.

## 1.2.1-rc4 — First downloadable restore-fix prerelease (2026-10-24)

- Fix restore failure on nested Astra_Builder_Header serialized values (PR #12).
- Guard against cyclic/deep PHP serialized data causing runaway recursion; retain unsafe serialized fields byte-for-byte, continue normal URL conversion (PR #13).
- Conservative limitation: plugin-specific serialized values left unchanged may retain source URLs. Verify on isolated target before declaring migration complete.
- Backup and verify on local WordPress are already tested; RC4 exact-SHA clone/runtime acceptance is pending. Production restore remains HARD BLOCKED.

## 1.2.1-rc3 — Clone trial & private diagnostics (2026-10-24)

- Expose encrypted `local_copy` Host→Local backup and .mgbackup/.zip clone-only import form.
- Private bounded debug JSONL with allowlist and admin nonce-protected download.
- Log core backup/restore milestones; reject ZIP duplicate entry and incomplete extraction.
- Production restore remains blocked in RC; independent WP+DBngin/AZDIGI acceptance pending.

## 1.2.1-rc2 — Backup & Restore scope/compatibility (2026-10-24)

- Target WordPress 7.1+, PHP 8.3+; confirm CLI/ZipArchive/OpenSSL before install. RC explicitly blocks production restore.
- Remove inactive Data Transfer/Deploy and unused PHP SQL fallback code from distribution; existing WordPress options kept.
- Use nonblocking private-file `flock` for manual/scheduled backup, restore, catalog verification and deletion, no polling.
- Use WordPress enqueue for admin assets; shorter README; preserve independent Recovery Kit/restore caveats.
- Tested static/contract only; real WordPress + DBngin/cPanel restore acceptance pending.

# Changelog

## 1.2.1-rc1 — 2026-10-24 (Backup & Recovery focus; staging-only)

- Reframed the product to BACKUP → VERIFY → STORE → RESTORE. Five-tab WP Admin UI; legacy Data Transfer/Deploy is no longer loaded/accessible, including old POST action names. Existing settings and legacy files retained for upgrade compatibility.
- Fail-closed native mysqldump/mysql requirements; never run PHP SQL importer after an unsuccessful CLI import on a potentially partially-mutated DB.
- Read back and verify the final archive including encrypted round-trip, content SHA-256 and current-site HMAC. Catalog distinguishes verified vs content-only provenance vs uploaded-unverified vs genuinely restore-tested.
- Separate offsite policy: Cloud upload requires an encrypted, signed/locally verified artifact; scheduled backups always require a Recovery Key. No implicit plaintext upload.
- Atomically replace the private metadata catalog, forbid deleting the final full recovery archive via UI, keep existing retention protections.
- New WordPress-independent CLI decrypt/hash inspector and RECOVERY_RUNBOOK; neither claims live production restore or HMAC provenance without original signing key.
- Setup wizard test backup now explicitly encrypted; system UI never claims PHP importer/exporter fallback is available when native SQL CLI is absent.
- Safety/contract tests & static syntax/ZIP integrity are not a substitute for full restore on an isolated WordPress + DBngin/cPanel environment. Production NO-GO.

## 1.2.0-rc1 — 2026-10-23 (pre-release; local/staging test only)

- Hardened backup storage: fail-closed outside public webroot, no wp-content fallback, symlink/path validation.
- Independent retention SQL/full/local_copy/rollback; protect flagged backups and last full.
- Content export UUID identity now deterministic when absent, without writing source post/term meta.
- URL restore: GUID excluded, prefix-scoped keyset traversal, serialized-aware, explicit error propagation.
- New backup manifest carries db_table_prefix; cross-site prefix mismatch or unknown legacy prefix blocked.
- Deploy probe failure or non-2xx triggers rollback (HTTP 2xx still requires independent visual QA).
- Cleanup newly sideloaded attachment IDs if content import fails before SQL rollback.
- Default encryption enabled for new installs; selected automated encryption cannot silently fall back to plaintext.
- Admin retention fields split by SQL/full/rollback; source/restore/backup invariants tested in local mock harness.
- Not yet a remote SSH one-click release or full disaster recovery guarantee.


## 1.1.9

### Security architecture
- Thêm Security Center: ưu tiên cảnh báo cần xử lý, thu gọn kiểm tra đã đạt.
- Thêm ghép nối Local ↔ Staging/Production bằng Ed25519.
- Private key chỉ được tạo và lưu trên Local; Staging/Production chỉ lưu public key.
- Loại bỏ yêu cầu shared `MG_SAOLUU_DEPLOY_KEY` trong luồng mới.
- Không còn yêu cầu `MG_SAOLUU_ALLOW_PRODUCTION_DEPLOY` hoặc `MG_SAOLUU_ALLOW_PRODUCTION_RESTORE`.
- Deploy package khóa theo domain, hết hạn 30 phút, có Package ID/nonce và dùng một lần.
- Staging và Production đều xác minh chữ ký Local trước khi cài package.
- Restore Production dùng challenge/approval ký từ Local và chỉ mở 10 phút.
- Production Deploy/Restore yêu cầu xác thực lại mật khẩu admin, câu xác nhận, domain và rate limit 5 lần/30 phút.
- Tự khóa quyền sửa Theme/Plugin PHP trong WordPress Admin khi plugin hoạt động.
- Thêm Recovery Key tự quản lý, có thể xuất/khôi phục sau khi nhập lại mật khẩu admin; không tự ghi đè khóa cũ nếu WordPress salts thay đổi.
- Tách mã hóa Google credentials khỏi khóa ký backup mới ghi; vẫn đọc định dạng cũ để tương thích.

### UI / System
- Tab Bảo mật gọn hơn, chỉ hiện lỗi/cảnh báo trước.
- Tab Kiểm tra hệ thống không lặp lại toàn bộ thông tin bảo mật.
- Thêm trạng thái integrity CSS/JS/plugin file rõ ràng.
- Thêm credit kín đáo `Built by Thiên Dũng` ở cuối trang quản trị/README.

### Data / Deploy
- Giữ cơ chế quét Custom Post Type, taxonomy, ACF và media có chọn lọc.
- Cấu trúc ACF tiếp tục khuyến nghị đi bằng `acf-json` cùng code.
- Deploy hàng ngày chỉ dành cho Theme/Plugin; không đẩy SQL Production theo luồng thông thường.

### Compatibility
- Vẫn đọc `MG_SAOLUU_ENCRYPTION_KEY` và `MG_SAOLUU_SIGNING_KEY` nếu cài đặt cũ đang dùng.
- Các constant `MG_SAOLUU_DEPLOY_KEY` và `MG_SAOLUU_ALLOW_PRODUCTION_*` không còn cần cho kiến trúc mới.
- Số phiên bản được đặt lại thành 1.1.9 theo yêu cầu dự án, kế thừa nhánh phát triển trước đó.
