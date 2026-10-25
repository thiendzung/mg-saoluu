## 1.3.1 — Simple Full Backup & Restore (2026-10-25)

- Collapse the active product surface to three tabs: Backup, Restore and Saved Backups.
- Remove environment selection, Production/Local/Staging restore modes, signer pairing, challenge/approval and temporary unlock from the active flow.
- Disable Pack Backup/Restore, verify-only restore and raw SQL Restore actions in v1.3.1.
- Backup action always creates a Full Site backup (database + files).
- Runtime ZIP uses Deflate level 1 to favor backup speed while retaining standard ZIP compatibility.
- Restore always applies database + files and always creates a pre-restore checkpoint.
- Restore automatically rewrites the backup source home/site URL to the current WordPress home/site URL, including serialized values handled by the existing safe replacer.
- Cross-domain restore is allowed when the DB table prefix matches; source environment/host and source-site HMAC are no longer restore gates.
- Keep SHA-256 payload verification, private storage, CLI database tools, path/symlink guards, exact snapshot prune, plugin self-protection and automatic rollback.

## 1.3.0 — Guarded Production Full Site Restore (2026-10-25)

- Promote the accepted RC13 Backup/Verify/drag-drop behavior to Stable only through an explicit Production Full Site Restore capability gate; version text alone cannot authorize live writes.
- Production Full Site Restore requires a fresh Ed25519 Local approval, 10-minute unlock, administrator re-authentication, exact host + RESTORE phrase, same-host/same-prefix signed backup checks, healthy baseline and mysql/mysqldump availability.
- Production restore forces domain/URL rewrite off server-side.
- Plugin version upgrades revoke any inherited Production Restore unlock window.
- Production Pack Restore remains hard-blocked in UI, action handling and Pack engine.
- Full Site upload may be verified non-destructively before the separately authorized Restore submission.
- Full Site Restore now prunes managed files that are absent from the backup snapshot; explicit exclusions and the currently executing MG Sao Luu plugin remain protected. Any prune failure triggers automatic rollback instead of success.

## 1.3.0-rc13 — Restore upload UX + Production verify-only (2026-10-25)

- Add real drag-and-drop for Restore upload boxes with selected-file feedback.
- Client-side upload validation now checks both effective PHP size limit and allowed file extensions.
- Pack v2 upload remains `.mgbackup` only.
- Full Site upload accepts `.zip` or `.mgbackup`.
- Production now shows Full Site upload as **verify-only**: validate file, hash, manifest/source host/prefix and signature where applicable, then remove the temporary upload.
- Production RC Restore/Import remains hard-blocked; the new verify-only action never calls the Restore engine.

## 1.3.0-rc12 — Logical Settings state hash (2026-10-25)

- Settings Pack state verification no longer depends on physical `wp_options.option_id` values.
- Settings rows are dumped one per statement, normalized to remove only the surrogate ID, sorted by logical row content, then hashed identically during backup and worker post-verify.
- Option name/value/autoload changes still change the hash; only physical IDs and row order are ignored.
- Fresh RC12 Settings Packs are required; older Settings fingerprint versions fail closed.
- RC11 oversized upload client gate is retained unchanged.

## 1.3.0-rc11 — Settings hash + upload size gate (2026-10-25)

- Settings Pack excludes volatile WordPress runtime rows (cron, rewrite rules and updater locks) from the deterministic backup/post-verify scope.
- RC11 refuses older Settings Pack scope during Restore; create a fresh Settings Pack with RC11.
- Oversized local Pack selection immediately disables submit and shows an inline warning; server-side upload validation remains unchanged.
- No migration/deploy/production Restore changes.

## 1.3.0-rc10 — Admin UI + local Pack restore (2026-10-25)

- Keep the runtime-accepted RC9 Pack worker/state-hash/rollback core unchanged.
- Store the actual WordPress admin hook suffix returned by `add_menu_page()`; enqueue CSS/JS only on that page and use asset `filemtime()` for cache busting.
- Add minimal critical inline CSS fallback so the admin page remains readable if an external stylesheet is stale or unavailable.
- Add primary Pack Restore from a local `.mgbackup` file: server-side upload limit, native PHP upload validation, private temp storage `0600`, Pack-v2 magic validation, then the same RC9 restore engine.
- Uploaded Pack files are deleted after job staging and are not silently retained in the backup catalog.
- Production Pack Restore remains hard-blocked for RC10.

## 1.3.0-rc9 — Synchronous shutdown worker (2026-10-25)

- Remove unreliable detached/background worker launching under PHP-FPM.
- Pack Restore now registers the CLI worker from WordPress shutdown and runs it synchronously as a later PHP shutdown callback.
- PHP-FPM may flush the queued response with `fastcgi_finish_request()`, while the same request process continues the isolated CLI restore worker.
- Parent PHP process retains the existing Backup/Restore flock for the whole worker run; the worker no longer owns a second lock.
- Worker launch failure is detected immediately by exit status, marks the private journal failed, and removes staged mysql.cnf/job/target SQL secrets.
- Queued jobs older than five minutes are surfaced as stale launch failures instead of looking healthy forever.
- RC9 keeps RC8 state-hash post-verify and automatic checkpoint rollback. Production Restore remains hard-blocked.

## 1.3.0-rc9 — Minimal final DB writer (2026-10-25)

- Fix RC7 false-success risk caused by third-party WordPress/plugin writes around Restore.
- WordPress request now only verifies Pack, validates host/prefix/schema and stages private job material.
- Standalone CLI worker never loads WordPress/plugins/themes/mu-plugins; it waits on the existing operation flock until the PHP request fully exits, then becomes the final DB writer.
- Worker creates an exact private checkpoint of the selected scope, imports, re-dumps the same scope using deterministic mysqldump ordering, compares state hash, and automatically rolls back on any mismatch.
- No option-name blacklist: ACF/RankMath/Woo options remain ordinary restored data.
- DB cleanliness acceptance is scoped to MG Sao Luu-owned writes; unrelated plugins may legitimately create their own transients when wp-admin is opened.
- RC9 keeps Production Restore hard-blocked.

## 1.3.0-rc7 — Simple Pack Backup/Restore (2026-10-25)

- Stop the migration/rollback patch train and rebuild the next release from v1.2.1 Stable.
- Separate Backup/Restore from Migration. Pack Restore is same-host/same-prefix only and never rewrites domain.
- Add three explicit DB Packs: Settings, WordPress Content and Full DB.
- Settings Pack excludes transient rows; Content Pack keeps the complete core content-table dependency group in one native mysqldump snapshot.
- Add Pack v2 streaming encryption with libsodium XChaCha20-Poly1305 secretstream and Argon2id key derivation.
- Prefer Zstd level 1 around a store-only ZIP container; fall back to ordinary ZIP Deflate when zstd is unavailable.
- Restore creates only a same-Pack checkpoint; failure re-applies that checkpoint rather than rolling back the whole website.
- Move routine UI/integrity caches out of DB-backed Transients; new audit events live in private 0600 JSON outside webroot.
- Production Pack Restore remains hard-blocked in RC7.

## 1.2.1 — Stable Backup & Restore (2026-10-24)

- Promote the runtime-accepted RC9 Backup/Restore tree to Stable; no Data Transfer/Deploy features are re-enabled.
- Production restore is same-host only: source environment/host must match, manifest signature must verify, a paired Local device must sign a 10-minute unlock, and the administrator must re-enter password, host and confirmation phrase.
- Retain transactional rollback, restore-engine self-protection, integrity verification, fail-closed ZIP/prefix/symlink/CLI/disk/operation-lock gates, private redacted diagnostics and honest scheduled/offsite status.
- Interrupted import remains not runtime-injected because no safe deterministic failure mechanism is available; partial import is mitigated by pre-restore checkpoint + rollback but is not claimed crash-atomic.

## 1.2.1-rc9 — Restore engine self-protection (2026-10-24)

- Full backup archives continue to include MG Sao Luu for offline recovery completeness.
- Runtime Restore/Rollback now skips the currently executing MG Sao Luu plugin directory during extraction, copy, new-file bookkeeping and cleanup, preventing an older backup from downgrading the active restore engine mid-transaction.
- UI explicitly documents that the running restore engine is preserved and must be restored separately from a trusted release ZIP if needed.
- Add regression coverage proving an archived RC7-style plugin payload cannot overwrite or delete an RC9 runtime plugin.
- RC9 transactional rollback and timezone fixes are retained; production restore remains blocked for RC builds.

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
