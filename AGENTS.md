# ModuleLocalSpeechToText Codex Notes

## Project Shape

`ModuleLocalSpeechToText` scans completed CDR recordings, creates local transcription jobs, serves recordings only to the assigned worker, stores transcript segments and diagnostics, applies server-side transcript quality filtering, and publishes idempotent `transcript.completed` events.

## Important Contracts

- Use the MikoPBX REST v3 `PBXApiResult` envelope for JSON endpoints.
- Preserve job ownership checks around `worker_uid`, `job_id`, and `lease_token`.
- Serve recordings only from validated monitor paths after `realpath` checks.
- Keep transcript segment timestamps in integer milliseconds in worker payloads.
- Preserve stable `segment_id` values in transcript detail responses.
- Keep `m_STTTranscriptEvent` suitable for cursor-based downstream imports.
- Do not expose plaintext worker API keys after the one-time creation response.

## Verification

Backend contract checks:

```bash
php Tests/run-all.php
```

Package check:

```bash
./scripts/package-module.sh /tmp/module-build
```

## Review Defaults

For API or worker-payload changes, use `module-contract-guardian`.
For queue, lease, heartbeat, delayed, skipped, or retry behavior, use `queue-resilience-reviewer`.
For STT quality, timestamps, model payload, or transcript diagnostics, use `worker-stt-quality-reviewer`.
For credentials, auth, logs, or recording download access, use `module-security-reviewer`.
