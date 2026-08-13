# Worker API v2 — Independent Verification

**Date:** 2026-08-13
**Scope:** breaking migration to the resource-oriented Worker API v2 across four feature branches
**Method:** read-only audit against current code, actual Git diff, unmodified MikoPBX Core behaviour and real module/client contracts, followed by TDD corrections. No prior session report, ledger, commit message, `[x]` marker, pre-existing build artifact or claimed test pass was accepted as evidence.

**Final status: `LOCALLY VERIFIED — LIVE PBX MATRIX PENDING`**

---

## 1. Worktrees, branches and ranges (independently verified)

Verified with `git worktree list --porcelain`, `git rev-parse HEAD`, `git merge-base HEAD develop`, `git status --short`.

| Project | Worktree | Branch | Stated HEAD | Actual HEAD (start) | Merge-base | Clean |
|---|---|---|---|---|---|---|
| ModuleLocalSpeechToText | `.worktrees/worker-api-v2-stt` | `feature/worker-api-v2-stt` | `bc8f9ea` | `bc8f9ead145a1180ceffb5f1962fb5a2c9e060a8` ✅ | `3415f0bfcb6e074e4dd0ec3ff029355f6f4fbb57` ✅ | yes |
| ModuleAISupervisor | `.worktrees/worker-api-v2-ai` | `feature/worker-api-v2-ai` | `7763552` | `776355241182485488883e54042f799510952bb2` ✅ | `ed40df24302a0658ea8f43d9d19b63590a341e18` ✅ | yes |
| LocalSTTWorkerMac | `.worktrees/worker-api-v2-stt-mac` | `feature/worker-api-v2-stt-mac` | `07990ad` | `07990ad7c6f540bc1f32b953a70610eea97f8062` ✅ | `48eaebde00802bec4fad7034b5b9b8e8a30f6608` ✅ | yes |
| AISupervisorWorkerMac | `.worktrees/worker-api-v2-ai-mac` | `feature/worker-api-v2-ai-mac` | `31e6cae` | **`e409cd6a963d88f3d0d314d9d86aee62af939161`** ⚠️ | `27c687878d56ecf39347a15d584a4fc3418f5261` ✅ | yes |

**Deviation from the brief (benign):** the AISupervisorWorkerMac worktree was **one commit ahead** of the stated HEAD. `31e6cae` is a verified ancestor of `e409cd6`; the extra commit is `e409cd6 chore: remove dead AIEmptyRequest and read retained worker id`. The audit was performed against the actual HEAD, not the stated one.

Commit counts over the range: STT 55, AI 36, STT-Mac 22, AI-Mac 27.
Diff sizes: STT 154 files (+9138/−1656), AI 202 files (+26546/−16439), STT-Mac 29 files (+1766/−205), AI-Mac 27 files (+1806/−172).

`git diff --check` clean over `merge-base..HEAD` in all four ranges, both before and after corrections.

## 2. MikoPBX Core — proof of non-modification

```
HEAD 361dd80b054622ef874f2ee52a5bfd9511651859   (matches expected)
git status --short:  ?? .agents/  ?? .codex/  ?? AGENTS.md  ?? SECURITY_AUDIT_2026-07-09.md
git diff --stat:     (empty)
```

Core HEAD unchanged, tracked diff empty, and the four pre-existing untracked paths were left exactly as found — not deleted, edited or staged. No Core branch, worktree or commit was created. `PBXApiResult`, `BaseController`, the authentication/permission checker and `RouterProvider` were read only. No `responseHeaders` were introduced, no newer Core is required, and no streaming/raw-response/file-response workaround is used. Core is not in the release scope.

Both modules retain `"min_pbx_version": "2025.1.1"`.

## 3. Initial audit matrix (recorded before any correction)

| # | Requirement | Verdict | Evidence | Covering test |
|---|---|---|---|---|
| 1 | Contract endpoint values `contract`/`api_version`/`module`/capabilities | PASS | `Lib/Services/WorkerApiContract.php` both modules | `check-worker-api-v2-negotiation.php` |
| 2 | Contract request not version-gated | PASS | `Lib/RestAPI/WorkerApiContract/Processor.php:24` — no `requireVersion` | `check-worker-api-v2-negotiation.php` |
| 3 | Envelope success field is `result`, not `success` | PASS | standard `PBXApiResult` used unchanged | — |
| 4 | 426 body carries `error_code=worker_upgrade_required` | **FAIL (AI)** | `Lib/Services/WorkerApiContract.php:47` emitted key `error` | none — existing tests encoded the defect |
| 5 | 410 body carries `error_code=api_route_removed` | **FAIL (AI)** | `Lib/RestAPI/Common/LegacyRouteRejector.php:22` emitted key `error` | none — same |
| 6 | Same fields in STT module | PASS | STT `WorkerApiContract.php:58`, `LegacyRouteRejector.php:23` | `check-worker-api-v2-*.php` |
| 7 | Version validation precedes context, guard and domain calls | PASS | `Lib/RestAPI/JobLeases/Processor.php:28-36` — `requireVersion` before `WorkerRequestContext`/actions | `check-worker-job-resources-v2.php` (asserts `calls == 0`) |
| 8 | `file_url` is exactly `/job-recordings/{job_id}`, no prefix/query/token | PASS | `JobLeases/Actions/CreateAction.php:24` | `check-worker-job-resources-v2.php` |
| 9 | AI lease uses `recording_download.file_url` (not `.url`) | PASS | `Lib/Services/AIAnalysisJobLeasePayload.php:21`, `Jobs/DataStructure.php:267` | `check-worker-job-resources-v2.php` |
| 10 | Swift validates the path before building the download request | PASS | `AISupervisorClient.swift:359-363` (`fileURL == expectedPath`) | `AISupervisorClientV2ContractTests` |
| 11 | Voice analytics uses PATCH, not PUT | PASS | `JobVoiceAnalytics/Controller.php:12` `mapping: ['PATCH' => ['patch']]` | `check-worker-api-v2-route-contract.php` |
| 12 | Recording ownership header-only | PASS | `Common/WorkerRequestContext.php:58`; `JobRecordings/Processor.php:58` strips body-borne credentials | `check-worker-job-resources-v2.php` |
| 13 | `POST /job-leases/{id}` returns 405 without claiming | PASS | `check-worker-job-resources-v2.php:120` incl. mutation check at :129 | same |
| 14 | Operation ID uniqueness | PASS | AI 41/41 unique, STT 18/18 unique | `check-openapi-*` |
| 15 | No working v1 callers in either Swift app | PASS | grep over all `*.swift`: 0 hits for the full legacy action list | `check-worker-api-v2-routes.sh` (see finding 3) |
| 16 | Startup order negotiate → register → settings/model → lease | PASS | STT `WorkerService.swift:152/159/169`; AI `AIWorkerService.swift:140/148` | `check-worker-api-v2-routes.sh`, `WorkerLifecycleTests` |
| 17 | Sentiment enum + CodingKeys match server schema | PASS | `Models.swift:119-127`, `:450-460` vs `AIAnalysisLocalResultPayload.php:105` | `AISupervisorClientV2ContractTests` |
| 18 | Only allowed top-level result fields; finite scores 0…100 | PASS | `Models.swift` encoder; `AIAnalysisLocalResultPayload.php:90-104` | `testEncodedFinalResultMatchesV2SubmissionContract` |
| 19 | Bounded `raw_response` in Swift encoder | **FAIL** | `Models.swift:578` passed `rawResponse` through unbounded; server rejects `strlen > 200000` | none |
| 20 | Unicode boundary consistent between client and runtime | **FAIL** | server bounds bytes (`strlen`), Swift bounded nothing; its other `truncated()` uses Character count | none |
| 21 | Module versions 1.45 / 1.73 | PASS | `module.json` both | packaging |
| 22 | `min_pbx_version` 2025.1.1 both | PASS | `module.json` both | `check-worker-api-v2-release-contract.php` |
| 23 | Built worker versions 1.7 / build 34 | PASS | built `Info.plist` both apps | Release build |
| 24 | Bundled React artifact matches a fresh build | PASS | `npm run build` left `git status` empty → byte-identical | — |
| 25 | Package hygiene (no `.git`/Tests/docs/react-app/scripts/node_modules/runtime state) | PASS | `unzip -l` filters return 0 matches for both zips | — |
| 26 | `run-shell-contracts.sh` green on both workers | **CANNOT VERIFY locally** | `rg` absent → exit 127 (STT-Mac) / 1 (AI-Mac) | see finding 3 |
| 27 | Migration preservation / idempotency / rollback | PASS (suite-level) | `check-worker-api-v2-migration-sqlite.php` executes real SQLite migration, forced rollback and repeat setup | that check |
| 28 | Live PBX behavioural matrix | **NOT RUN** | no isolated test PBX available in this environment | — |

## 4. Findings

### Finding 1 — Critical — AI module emitted the wrong error key, breaking 426 compatibility detection

The macOS AI worker decodes the inner `data` object of a 426 with `WorkerUpgradeRequiredPayload`, whose CodingKeys are `error_code` and `required_worker_api_version` (`AISupervisorWorker/Core/WorkerAPIContract.swift:141-151`). `isWorkerUpgradeRequired` is true only when `error_code == "worker_upgrade_required"` **and** `required_worker_api_version == 2`.

`ModuleAISupervisor` emitted the code under the key **`error`** (`Lib/Services/WorkerApiContract.php:47`). The predicate was therefore never true, and `AISupervisorClient.swift:270-272` classified every genuine 426 as `.unsupportedAPIVersion` instead of `.workerUpgradeRequired`. The operator saw *"MikoPBX does not provide a compatible AI Supervisor worker API"* instead of *"update the worker app"* — a wrong diagnosis pointing at the wrong component. The same wrong key was used for the 410 `api_route_removed` tombstone (`LegacyRouteRejector.php:22`).

This also violates the mandated contract directly (`error_code=worker_upgrade_required`, `error_code=api_route_removed`). `ModuleLocalSpeechToText` was already correct, so the two modules disagreed on the wire format.

Notably, **the existing module tests encoded the defect** (`check-worker-api-v2-negotiation.php:88`, `:114`, `:116`, `check-worker-api-v2-route-contract.php:173`, `check-worker-job-writes-v2.php:101`, and the stub plus assertions in `check-worker-job-resources-v2.php`), which is why a fully green suite did not surface it.

**Fixed** — correction commit `c1c996c` on `feature/worker-api-v2-ai`.

- **RED:** new `Tests/check-worker-api-v2-error-code-contract.php` → `FAILED: Upgrade rejection must carry worker_upgrade_required under the Swift-decoded error_code key`, exit 1.
- **Fix:** `error` → `error_code` in `WorkerApiContract::upgradeRequired()` and `LegacyRouteRejector::removed()`; six existing checks corrected to the real contract.
- **GREEN:** new check passes; full suite `php Tests/run-all.php` → **126 contract checks passed**, exit 0.

The new check pins the key the Swift client decodes, asserts the legacy `error` key is absent, and covers `2.1`/`3`/`two`/absent version rejections plus both tombstones.

### Finding 2 — Important — AI worker submitted an unbounded `raw_response` the module always rejects

`AIAnalysisLocalResultPayload::assertSubmissionContract` rejects a submission whose `raw_response` exceeds 200000 bytes, measured with PHP `strlen` (UTF-8 **bytes**). `canonicalizedForSubmission()` truncated `summary` and every indicator string but passed `rawResponse` through untouched (`Models.swift:578`). A verbose local model therefore produced a result the module refused at the final submission step, failing the job after all analysis work was done.

This is also the Unicode-boundary inconsistency: the server bounds bytes, while the Swift `truncated()` helper bounds `Character` count (`text.count` / `prefix(limit)`). A Character-based bound would not have been sufficient — 150000 Cyrillic scalars are 150000 Characters but 300000 bytes.

**Fixed** — correction commit `c5affd3` on `feature/worker-api-v2-ai-mac`.

- **RED:** two new tests in `AISupervisorClientV2ContractTests` failed with `("300000") is greater than ("200000")` — both the ASCII case and the multi-byte case. `Executed 41 tests, with 2 failures`, `** TEST FAILED **`.
- **Fix:** added `rawResponseByteLimit = 200_000` and `truncatedUTF8(_:byteLimit:)`, which cuts on a Unicode scalar boundary, and applied it to `rawResponse` in `canonicalizedForSubmission()`.
- **GREEN:** full suite `Executed 147 tests, with 0 failures`, `** TEST SUCCEEDED **` (was 145; +2 new). Release build re-run: `** BUILD SUCCEEDED **`.

### Finding 3 — Environment blocker (not a branch defect) — `run-shell-contracts.sh` cannot pass locally

Both macOS workers fail this required check on this machine:

- `LocalSTTWorkerMac` → exit **127**
- `AISupervisorWorkerMac` → exit **1**, `ERROR: AI Supervisor Worker bundle identifier is incorrect`

Root cause: **`ripgrep` is not installed**. `rg` resolves in the interactive zsh only as a shell function (`/Users/excla1m/.claude/shell-snapshots/…`), which `#!/usr/bin/env bash` scripts do not inherit, so every `rg` invocation inside the contract scripts fails with `rg: command not found`.

The reported failure is a **false negative**: the actual `project.pbxproj` does contain `PRODUCT_BUNDLE_IDENTIFIER = com.mikopbx.AISupervisorWorker;` and `PRODUCT_NAME = "AI Supervisor Worker";`. `.gitlab-ci.yml:63` installs `ripgrep`, so CI is unaffected.

`Tests/check-ai-only-boundary-contract.sh` is **not** part of the v2 range (last touched by `5555426`, `b07875c`, `9617adf`) — this is pre-existing.

Two distinct failure modes are worth recording:

- `rg -q … || fail` → missing `rg` produces a **false failure** (the bundle-identifier case).
- `if rg …; then fail; fi` → missing `rg` makes the assertion **silently pass**. `Tests/check-worker-api-v2-routes.sh` (added by this branch) uses this shape for its two most important assertions — "no legacy v1 action routes" and "no worker-side `GET /settings`". Its later `nego_line=$(rg … | head -1 | cut -d: -f1)` under `set -euo pipefail` aborts the script, so the overall run does still fail rather than falsely reporting OK — but the two headline assertions themselves provide no signal when `rg` misbehaves.

Because these assertions could not run, **every one was verified manually** with equivalent `grep`:

- legacy v1 action callers (`workers:register`, `workers:getProcessingSettings`, `jobs:getNext`, `:download`, `:downloadRecording`, `:heartbeat`, `:release`, `:submitResult`, `:submitError`, `:submitPartial`, `:submitVoiceAnalytics`) across all `*.swift`: **0 hits in both apps**;
- worker-side `getSettings`/`loadStartupRuntimeProfile`/`stationModelProfile`/`AISettingsResponse`: **0 hits**;
- negotiate-before-register ordering: AI `AIWorkerService.swift:140` < `:148`; STT `WorkerService.swift:152` < `:159` < `:169`.

Not fixed: making the scripts `grep`-portable is out of scope for this branch and CI already provisions `ripgrep`. **Deferred** — the residual risk is that a developer without `ripgrep` gets a misleading local result.

### Deferred Minor

- **Mixed `error` / `error_code` keys for non-mandated codes.** Both modules still use the bare `error` key for `access_denied` (14 sites in AI), `validation_error`, `internal_error`, `unknown_action`, `stt_dependency_unavailable`; STT additionally uses `error_code` for `worker_identity_forbidden` and emits both keys for `validation_error`. The contract only mandates `error_code` for `worker_upgrade_required` and `api_route_removed`, which are now correct in both modules, and the Swift `serverErrorCode` accessor reads `error_code` with a fallback to `error`. Normalising the rest would touch many call sites and their tests for no behavioural gain. Risk: low — cosmetic inconsistency only.
- **macOS TLS behaviour** left untouched by explicit instruction; any existing TLS risk is pre-existing and was not introduced by the Worker API v2 diff.

## 5. Fresh command results

No stored output or pre-existing artifact was reused; everything below was produced in this session.

| Pipeline | Command | Exit | Result |
|---|---|---|---|
| STT module | `php Tests/run-all.php` | 0 | 90 checks executed, `All module contract tests passed` |
| STT packaging | `./scripts/package-module.sh` | 0 | zip valid, 188 files |
| AI module (after fix) | `php Tests/run-all.php` | 0 | **126 contract checks passed** |
| AI react-app | `npm test -- --run` | 0 | **194 pass / 0 fail**, 0 skipped |
| AI react-app | `npm run build` | 0 | 47 modules, `ai-supervisor.bundle.js` 670.12 kB (gzip 179.64 kB) |
| AI packaging (after fix) | `./scripts/package-module.sh` | 0 | zip valid, 448 files |
| STT-Mac | `./scripts/ci/check-repository.sh` | 0 | pass |
| STT-Mac | `./scripts/ci/run-shell-contracts.sh` | **127** | environment blocker — finding 3 |
| STT-Mac | `xcodebuild … LocalSTTWorkerTests test` | 0 | **Executed 168 tests, 0 failures**, `** TEST SUCCEEDED **` |
| STT-Mac | `xcodebuild … LocalSTTWorker Release build` | 0 | `** BUILD SUCCEEDED **` |
| AI-Mac | `./scripts/ci/check-repository.sh` | 0 | pass |
| AI-Mac | `./scripts/ci/run-shell-contracts.sh` | **1** | environment blocker — finding 3 |
| AI-Mac (after fix) | `xcodebuild … AISupervisorWorkerTests test` | 0 | **Executed 147 tests, 0 failures**, `** TEST SUCCEEDED **` |
| AI-Mac (after fix) | `xcodebuild … AISupervisorWorker Release build` | 0 | `** BUILD SUCCEEDED **` |

### Built worker versions (read from the built `Info.plist`, not from source)

| App | `CFBundleShortVersionString` | `CFBundleVersion` |
|---|---|---|
| `Local STT Worker.app` | `1.7` ✅ | `34` ✅ |
| `AI Supervisor Worker.app` | `1.7` ✅ | `34` ✅ |

### Module packages

| Module | Path | Size | SHA-256 |
|---|---|---|---|
| ModuleLocalSpeechToText 1.45 | `/tmp/module-stt-v2-audit.wFf043/ModuleLocalSpeechToText.1.45.zip` | 570919 B | `9607006fcfafc62337d9eb3f5b0c86ada79afbd0fdd77ab5a835983f16b4b858` |
| ModuleAISupervisor 1.73 (post-fix) | `/tmp/module-ai-v2-final.TdtfI3/ModuleAISupervisor.1.73.zip` | 1241284 B | `94914f1c4ad6f2e94cf9764c15cd18c3f2bb8c4328efcd13f7014fbefeae5ee7` |

`unzip -t` reports no errors for both. Hygiene: neither archive contains `.git`, `.gitlab`, `Tests/`, `docs/`, `react-app/`, `scripts/`, `node_modules`, or runtime DB/log/secret files. The AI archive contains exactly one bundled React asset, `public/assets/js/vendor/ai-supervisor.bundle.js`, and a fresh `npm run build` left `git status` empty — the committed bundle is byte-identical to a fresh build.

## 6. Cross-project contract table

| Operation | Module method/path | Swift method/path | Body | Headers | Response |
|---|---|---|---|---|---|
| Negotiation | `GET /worker-api-contract` | `GET /worker-api-contract` | — | auth only; **no** version header | `result`+`data{contract,api_version,module,module_version,capabilities}` |
| Registration | `POST /workers` | `POST /workers` | `worker_uid`, `name`, `model`, … | `X-MikoPBX-Worker-API-Version: 2` | numeric `id` + `worker_uid` |
| STT settings | `GET /worker-processing-settings` | `GET /worker-processing-settings` | — | version + `X-MikoPBX-Worker-UID` | settings object |
| Lease claim | `POST /job-leases` | `POST /job-leases` | `worker_uid` | version | job + `lease_token`; STT `file_url`, AI `recording_download.file_url` |
| Heartbeat | `PATCH /job-leases/{job_id}` | `PATCH /job-leases/{job_id}` | `worker_uid`, `lease_token` | version | `result` |
| Release | `DELETE /job-leases/{job_id}` | `DELETE /job-leases/{job_id}` | `worker_uid`, `lease_token` | version | `result` |
| Recording (STT) | `GET /job-recordings/{job_id}` | `GET /job-recordings/{job_id}` | **none** | version, `X-MikoPBX-Worker-UID`, `X-MikoPBX-Job-Lease-Token` | audio, 200/206/416 |
| Recording (AI) | `GET /job-recordings/{job_id}` | `GET /job-recordings/{job_id}` | **none** | as above **+** `X-MikoPBX-Recording-Download-Token` | audio |
| Result (STT) | `PUT /job-results/{job_id}` | `PUT /job-results/{job_id}` | `worker_uid`, `lease_token`, transcript | version | `result` |
| Result (AI) | `PUT /job-results/{job_id}` | `PUT /job-results/{job_id}` | `worker_uid`, `lease_token`, `provider`, `model`, `summary`, `sentiment`, `risk_score`, `quality_score`, `metrics`, `indicators`, `raw_response` | version | `result` |
| Failure | `PUT /job-failures/{job_id}` | `PUT /job-failures/{job_id}` | `worker_uid`, `lease_token`, error | version | `result` |
| AI partial | `PUT /job-partials/{job_id}` | `PUT /job-partials/{job_id}` | `worker_uid`, `lease_token`, partial | version | `result` |
| AI voice analytics | `PATCH /job-voice-analytics/{job_id}` | `PATCH /job-voice-analytics/{job_id}` | `worker_uid`, `lease_token`, analytics | version | `result` |

Verified literally: HTTP method, path, numeric item IDs, snake_case JSON field names against Swift `CodingKeys`, header names, body-vs-header ownership split, `result/data/messages` envelope, error codes, capabilities, and `file_url`. Voice analytics is PATCH-only on both sides. `file_url` carries no module/API prefix, query, fragment, token or worker UID, and Swift compares it to the expected path before constructing the download request.

**Absence of working v1 callers:** 0 grep hits across every `*.swift` in both apps for the complete legacy action list.

## 7. Migration evidence

`check-worker-api-v2-migration-sqlite.php` (AI) and the STT equivalents execute against a real SQLite database rather than asserting on source strings, and pass in the fresh run. They exercise: existing workers demoted to `api_version=1` and marked incompatible/offline with UID, numeric ID and API-key binding preserved; legacy duplicate bindings retained; active v1 and missing-worker leases returned to pending with ownership cleared and `attempts` not incremented; active exact-v2 leases untouched; malformed versions (`2x`, `2.1`, future `3`) not treated as v2; terminal jobs/results unchanged; transcripts, events, cursors, revisions, AI partials, cache, fingerprints, map-reduce reuse and voice analytics preserved; the `worker_api_contract_migration_v2=1` marker written inside the transaction; forced pre-commit failure rolling back marker, schema, data and ledger; repeat setup idempotent with no API-key rotation.

**Caveat:** this is suite-level evidence from checks that live in the branch under audit. The forced-rollback and idempotency paths execute real SQLite transactions, which is materially stronger than source inspection, but a production-shaped rehearsal is skipped locally (`SKIP: production-shaped setup rehearsal requires Phalcon`). Fresh-install versus update-of-existing-DB separation is covered by the same suite. Independent confirmation belongs to the live PBX matrix.

## 8. Reviewer verdicts

Both corrections were re-examined against the opposite side of the contract after the fix:

- **Finding 1** — re-read `WorkerAPIContract.swift` and `AISupervisorClient.swift` after the change: the emitted key now matches the decoded CodingKey exactly, `isWorkerUpgradeRequired` becomes true for a real 426, and no other consumer of the old key exists (React app, Volt templates and `App/` contain zero references). Verdict: **no Critical/Important remaining**.
- **Finding 2** — confirmed the bound is applied inside `canonicalizedForSubmission()`, so it covers both the `encode(to:)` path and `validatedForSubmission`, and that byte semantics match PHP `strlen`. Verdict: **no Critical/Important remaining**.

No Critical or Important defect remains open locally.

## 9. Scope confirmations

- **No Core changes:** Core HEAD `361dd80…` unchanged, tracked diff empty, four pre-existing untracked paths untouched.
- **No Windows/Python scope:** no Windows STT Worker or Python STT Worker path was read, modified or packaged; neither module archive contains Windows or Python binaries.
- **No push / merge / tag / release / publish**, no rebase, no `git reset --hard`, no `git clean`, no amend of existing commits, no deletion of other worktrees, no installation on any production PBX, and no real API key or user data was modified.
- Corrections were committed only to the corresponding feature branch, as separate commits.

## 10. Live PBX checks NOT performed

No isolated test PBX was available, so `miko-rest-api-check` was not run and none of the following were exercised. They are explicitly **not** claimed as passing:

1. old worker → new module: 426 with no mutation
2. new worker → old module: local module-upgrade state, no fallback
3. new/new STT lifecycle
4. new/new AI lifecycle
5. restricted key → management API: 403
6. legacy worker route: 426, no mutation
7. legacy admin route: 410, no mutation
8. active v1 lease migration
9. repeated update/setup idempotency
10. recording 200/206/416 and ownership
11. absence of credentials in URL / access / module / worker logs
12. compatibility state without retry storm

Additionally, `run-shell-contracts.sh` on both macOS workers remains unverified locally (finding 3); its assertions were verified manually instead.

## 11. Verdict

All four fresh suites pass, both Release builds succeed, both module packages build clean and hygienic, built worker versions are `1.7`/`34`, `min_pbx_version` stays `2025.1.1`, the cross-project route table matches literally, no working v1 callers remain, all four worktrees are clean with `git diff --check` empty, and Core is unmodified. Two confirmed defects — one Critical, one Important — were fixed with TDD in separate correction commits.

`VERIFIED COMPLETE` is **not** claimed: the live PBX behavioural matrix has not been run, and `run-shell-contracts.sh` could not execute locally.

**`LOCALLY VERIFIED — LIVE PBX MATRIX PENDING`**

| Correction | Branch | Commit |
|---|---|---|
| `error_code` in v2 compatibility envelopes | `feature/worker-api-v2-ai` | `c1c996c` |
| byte-bounded `raw_response` | `feature/worker-api-v2-ai-mac` | `c5affd3` |
