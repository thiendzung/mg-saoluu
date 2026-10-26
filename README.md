# MG Sao Lưu — v1.3.1 stable / v1.3.2-rc1 P2A checkpoint candidate

MG Sao Lưu is a WordPress backup and restore plugin. The stable v1.3.1 active admin surface offers **Full Site Backup** and **Full Site Restore**. Backups use native mysqldump, ZIP payload SHA-256, a signed manifest, and storage outside webroot. Recovery must be proven on an independent clone; archive verification is **not** restore proof.

## P2A pre-deploy DB checkpoint (not released; issue #66)

This branch adds three WP-CLI-only commands:

```sh
wp mg-saoluu backup --components=database --reason=pre-deploy --ref=<FULL_GIT_SHA> --format=json
wp mg-saoluu list --format=json
wp mg-saoluu verify --id=<BACKUP_ID> --format=json
```

- Database-only checkpoint reuses the native SQL ZIP/manifest/signature/metadata engine. No WP-CLI restore, HTTP endpoint, or deployment capability is added.
- The ref is an exact Git SHA *label*, not multi-repo release proof. Release manifests in P3 must bind repository, SHA and artifact digest per component.
- The CLI rejects non-InnoDB base tables, unreadable or changing schema inventory, bad refs, invalid scope, insufficient storage and concurrent backup/restore locks.
- Dump includes tables regardless of WordPress prefix and requests triggers, routines and events. MySQL DDL concurrent with dump is **not fully prevented by inventory comparison**; freeze migrations/DDL during checkpoint.
- Checkpoints carry signed Git ref and table inventory fingerprint, SHA-256 of SQL and archive, UTC timestamp, and protected retention metadata. Normal automated retention does not remove them; P5 must define explicit cleanup and monitor storage quota.
- `verify` reads a checkpoint and checks its manifest, ZIP payload hashes, signature and saved archive checksum; no database import. `list` reports stored status rather than claiming it re-verified archives.
- A checkpoint alone is **not** an adequate Production recovery backup: DB + files, independently stored copy and clone restore evidence are separately mandatory under MOTGU_07.

## Clone QA before any install

1. Verify exact PR SHA and use a **disposable clone** with its own DB and user, with no access to `motgu.test` or Production DB.
2. Ensure all real email, payment and webhook destinations are disabled; block indexing.
3. Run PHP lint, source/package integrity and standalone contracts; ensure old Full Backup/Restore regressions PASS.
4. Test the three commands and JSON-only stdout/exit status. Verify custom-prefix tables and Woo/HPOS/MG-OS fixtures when available.
5. Tamper ZIP/hash, simulate missing mysqldump, unsafe storage, lock conflict, disk shortage and unsupported engines. Each must fail closed.
6. Restore a P2A ZIP into a second disposable isolated clone using approved manual recovery tooling; verify schema and representative records; dispose both clones.
7. Compare application DB state before/after backup; no Woo/WordPress/MG-OS rows may be intentionally updated by the checkpoint.
8. Document exact SHA, plugin folder name, active plugin, backup hashes, test evidence, disposal, and production untouched. Founder must approve any local runtime installation separately.

**Important Local observation (2026-10-26):** On `motgu.test`, `mg-saoluu-2` v1.3.1 is active and `mg-saoluu` v1.3.0-rc13 is inactive. Do not enable both or overwrite either before an explicit single-active-plugin cutover and rollback plan.

Historical RC/Pack/migration references remain in `CHANGELOG.md` and `docs/releases/`; they do **not** describe the active v1.3.1 UI or authorize Production restore.

See [Recovery Runbook](RECOVERY_RUNBOOK.md), [Release QA](docs/RELEASE_QA.md) and [Issue #66](https://github.com/thiendzung/mg-saoluu/issues/66).
