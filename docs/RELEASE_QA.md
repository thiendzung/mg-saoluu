# MG Sao Lưu v1.2.1 — Stable release gate

## State

RC9 Backup/Restore runtime acceptance completed on an isolated WordPress + database clone.

Accepted functional runtime source:

`3952bac938b8ce934d16e6a061cd4a123f12639b`

Final reviewed RC9 PR head:

`81e67b3bdbbf1a29d8aa9118ca1a27a16a928606`

The only changes between those two RC9 heads were `readme.txt` changelog labels and the matching integrity hash. RC9 was merged to `main` as:

`e407e1c19912a3e0647bc8f1c65a9d4813a9c4b4`

The Stable release branch starts from that exact merge tree. Stable promotion must not re-enable Data Transfer/Deploy.

## Runtime evidence already accepted

- Encrypted SQL backup: verified and download SHA matched.
- Encrypted Full backup: verified and download SHA matched.
- Full Restore: PASS on independent clone; posts/media/products/Artwork/HPOS, URL rewrite, theme and health verified.
- Explicit Rollback: PASS; journal `rolled_back`, `rollback_attempt_status=succeeded`, baseline DB/files restored, cleanup truthful.
- Running restore engine remained unchanged when archive contained an older MG Sao Lưu plugin.
- Runtime negative tests PASS: wrong Recovery Key, corrupt encrypted backup, duplicate ZIP, wrong DB prefix, symlink leaf, symlink ancestor, missing mysql CLI, simulated low disk and operation lock contention.
- Debug JSONL redaction: no sensitive matches.
- Interrupted import/rollback: NOT TESTED because no safe deterministic fault-injection mechanism exists. Do not simulate by killing MySQL/PHP processes.

## Stable production restore policy

Stable removes the RC-only hard block, but production restore remains fail-closed unless all applicable gates pass:

1. Environment is actually Production.
2. A paired Local device signs a restore challenge; unlock lasts 10 minutes.
3. Administrator re-enters current password.
4. Administrator enters the exact production host and confirmation phrase.
5. Backup source environment is Production and source host exactly matches the current host.
6. Manifest signature verifies for the current site.
7. Self-integrity, storage, CLI, disk, prefix, baseline-health and operation-lock preflights pass.
8. A pre-restore checkpoint is created before DB/files are changed.

Production restore is **same-host disaster recovery**, not Local → live synchronization or migration.

## Exact Stable package gate

From a clean checkout at the exact Stable PR HEAD run:

```sh
sh scripts/verify_rc3_release.sh /ABSOLUTE/PRIVATE/NEW/mg-saoluu-v1.2.1.zip
```

Require:

- source PHP lint PASS;
- integrity manifest PASS;
- contract tests PASS, including Stable production lock/unlock regression;
- unsafe-config tests PASS;
- schedule-health tests PASS;
- deterministic ZIP build + CRC/payload PASS;
- the same checks PASS against the extracted ZIP;
- final ZIP SHA-256 recorded.

Any mismatch between source SHA, package SHA, header version and integrity version is NO-GO.

## Release boundary

- No `motgu.test`, `motgu.com`, AZDIGI, DNS, database or production cron changes are part of this release operation.
- Do not upgrade unrelated plugins such as Ego Lite during release QA.
- Do not enable production debug by default.
- Google Drive upload remains offsite storage; an upload alone is not proof of recoverability.
- Keep hosting-level backups/JetBackup independently.

A GitHub Stable release/tag is created only after the exact Stable PR HEAD passes CI and the merged Stable tree matches the reviewed release branch.
