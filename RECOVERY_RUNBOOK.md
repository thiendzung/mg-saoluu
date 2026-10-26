# MG Sao Lưu — Recovery Runbook (stable v1.3.1 / Local P2A rc1 / P2B-R1 rc2 candidate)

## 1. Before a release

- Record exact git commit SHA(s), plugin/theme versions, WordPress/PHP/MySQL versions, DB prefix, storage engine inventory, and a versioned release manifest.
- Maintain a **full DB + files recovery set** off hosting and a separate copy of secret/configuration information in a secure password manager. Backup ZIPs intentionally omit `wp-config.php` and some host configuration.
- Confirm the recovery set was independently restored on an isolated WordPress clone. Record verified archive SHA-256, SQL/payload hashes, schema inventory, sample records, media and elapsed restore time. Hash-verified is **not** restore-tested.
- Use isolated clone DB/user, blocked real email/payment/webhooks and indexing. Never use `motgu.test` as a disposable clone.

## 2. P2A DB checkpoint (Local R12 PASS; Production not deployed)

```sh
wp mg-saoluu backup --components=database --reason=pre-deploy --ref=<FULL_GIT_SHA> --format=json
wp mg-saoluu list --format=json
wp mg-saoluu verify --id=<BACKUP_ID> --format=json
```

- Backups use all tables in the selected WordPress database, including custom-prefix tables. Require only InnoDB for transactional consistency and no concurrent DDL/migrations.
- Backup output must include `ok=true`, opaque `backup_id`, `archive_sha256`, `sql_sha256`, `git_ref`, `created_at`, and `protected=true`. All checked against the archive and metadata before the gate is PASS.
- `verify` is read-only and never proves restore. Preserve protected archive, signing salts/key and site configuration. No automated DB restore is implemented.
- Checkpoint protection prevents normal prune; explicit retention/cleanup belongs to the future P5 controller, not this plugin command.
- A DB-only checkpoint cannot restore uploads, themes, plugins or host configuration. Full backup, offsite copy and restore drill remain independent gates.

## 3. Independent recovery drill

1. Provision a disposable clone with new DB, credentials, domain, and correct table prefix. Block outbound provider calls, live payments, indexing, scheduled sends and webhook effects.
2. Verify source artifact SHA-256, ZIP structure, payload hashes and provenance. Do not assume a foreign signing secret can validate the current site's manifest HMAC.
3. Import the vetted SQL **only into clone DB** using authorized native mysql tool. If the import fails, dispose the clone and rebuild; never import over live data.
4. Confirm table set, representative rows, Woo HPOS/MG-OS objects if present, prefix, URL migration dry-run, media, admin login, and theme/plugin behavior. Only record `RESTORE_DRILL_PASS` with evidence.
5. Dispose the clone when finished; retain the backup and sanitized QA record.

## 4. Production incident

- For a code regression, first roll back exactly the verified plugin/theme release. Avoid restoring DB merely to fix code.
- If any new orders/payments/inquiries have arrived, **never auto-restore an old DB**. Founder must approve reconciliation/export/replay/data-loss decision.
- Full Restore Production is a separate permissioned incident workflow; **a successful P2A command does not unlock it**.
- If admin/plugin unavailable, recover using the independent full backup, recorded infrastructure config and native tools; protect current data before overwrites.

## 5. Stop conditions

Invalid site identity, unknown DB target, non-InnoDB table, schema drift, missing binary, symlink/path traversal, altered ZIP/hash, stale metadata, disk shortfall, lock contention, outbound live provider exposure, or absent clone recovery evidence => **STOP / NO-GO**.

## P2B-R1 Recovery Key retrieval

- On candidate v1.3.2-rc2, use **Sao lưu → Recovery Key** after entering the current admin password over HTTPS. The display is one-request/no-store only; never copy the key to chats, tickets, browser logs or Git.
- Keep the existing Recovery Key and original WordPress salts in independently accessible secure storage; a display-only event is not proof of off-device recovery. The plugin does not mint a new key during this view, activation, version upgrade or default-password encryption. Missing keys fail closed until an explicitly approved provisioning flow.
- Plaintext R10 backup stays protected, unchanged and not uploaded. Encrypted backup/offsite and host-loss restoration are later separately authorized gates.

Historical 1.2.x/1.3.0 Local-to-Host transfer, signing challenge and Pack Restore experiments are **not** instructions for the current simple three-tab product.
