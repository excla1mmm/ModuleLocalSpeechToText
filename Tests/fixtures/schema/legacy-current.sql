CREATE TABLE m_STTJob (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    call_id VARCHAR(0) NOT NULL,
    file_path VARCHAR(0) NOT NULL UNIQUE,
    status VARCHAR(0) DEFAULT 'pending',
    priority INTEGER DEFAULT 5,
    worker_id INTEGER,
    attempts INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    cdr_endtime INTEGER,
    cdr_id INTEGER,
    cdr_uniqueid VARCHAR(0),
    cdr_start_ms INTEGER,
    cdr_end_ms INTEGER,
    recording_fingerprint VARCHAR(0),
    started_at INTEGER,
    lease_token VARCHAR(0),
    lease_expires_at INTEGER,
    finished_at INTEGER,
    error_msg VARCHAR(0),
    recording_duration_sec INTEGER
);
CREATE INDEX i_m_STTJob_queue ON m_STTJob(status, priority, cdr_endtime, created_at);
CREATE UNIQUE INDEX i_m_STTJob_file_path ON m_STTJob(file_path);
CREATE INDEX m_STTJob_call_status ON m_STTJob(call_id, status);
CREATE INDEX m_STTJob_cdr_id ON m_STTJob(cdr_id);

CREATE TABLE m_STTResult (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    job_id INTEGER NOT NULL UNIQUE,
    transcript VARCHAR(0) NOT NULL,
    language VARCHAR(0),
    duration INTEGER,
    model_used VARCHAR(0),
    technical_info VARCHAR(0),
    created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX i_m_STTResult_job_id ON m_STTResult(job_id);

CREATE TABLE m_STTTranscriptEvent (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    event_type VARCHAR(0) NOT NULL,
    event_key VARCHAR(0) NOT NULL UNIQUE,
    result_id INTEGER NOT NULL,
    job_id INTEGER,
    call_id VARCHAR(0),
    update_type VARCHAR(0),
    payload VARCHAR(0),
    created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX i_m_STTTranscriptEvent_event_key ON m_STTTranscriptEvent(event_key);
CREATE INDEX i_m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(created_at, id);
CREATE INDEX i_m_STTTranscriptEvent_result_id ON m_STTTranscriptEvent(result_id);
CREATE UNIQUE INDEX m_STTTranscriptEvent_event_key ON m_STTTranscriptEvent(event_key);
CREATE INDEX m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(created_at, id);
CREATE INDEX m_STTTranscriptEvent_result_id ON m_STTTranscriptEvent(result_id);

CREATE TABLE m_STTSetting (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    key VARCHAR(0) NOT NULL UNIQUE,
    value VARCHAR(0)
);
CREATE UNIQUE INDEX i_m_STTSetting_key ON m_STTSetting(key);

CREATE TABLE m_STTWorker (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    worker_uid VARCHAR(0) NOT NULL UNIQUE,
    name VARCHAR(0),
    ip VARCHAR(0),
    status VARCHAR(0) DEFAULT 'offline',
    model VARCHAR(0),
    api_key_id INTEGER,
    last_seen INTEGER,
    created_at INTEGER NOT NULL,
    has_settings_changed INTEGER NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX i_m_STTWorker_worker_uid ON m_STTWorker(worker_uid);

CREATE TABLE m_STTRetryState (
    cdr_id INTEGER PRIMARY KEY,
    retry_type TEXT NOT NULL,
    payload TEXT NOT NULL,
    reason TEXT NOT NULL,
    first_seen INTEGER NOT NULL,
    last_seen INTEGER NOT NULL,
    next_retry_at INTEGER NOT NULL
);

CREATE TABLE m_STTCallTranscript (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    call_id TEXT NOT NULL UNIQUE,
    current_revision INTEGER NOT NULL DEFAULT 0,
    current_content_hash TEXT NOT NULL DEFAULT '',
    current_parts_hash TEXT NOT NULL DEFAULT '',
    pending_content_hash TEXT NOT NULL DEFAULT '',
    pending_parts_hash TEXT NOT NULL DEFAULT '',
    pending_payload TEXT NOT NULL DEFAULT '',
    completeness_status TEXT NOT NULL DEFAULT 'pending',
    stable_after INTEGER NOT NULL DEFAULT 0,
    published_at INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE m_STTCallTranscriptPart (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    call_transcript_id INTEGER NOT NULL,
    result_id INTEGER NOT NULL,
    job_id INTEGER,
    cdr_id INTEGER,
    cdr_uniqueid TEXT,
    cdr_start_ms INTEGER,
    cdr_end_ms INTEGER,
    recording_fingerprint TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    source_label TEXT NOT NULL,
    resolution_status TEXT NOT NULL DEFAULT 'complete',
    segments_json TEXT NOT NULL DEFAULT '[]',
    is_current INTEGER NOT NULL DEFAULT 1,
    superseded_by_part_id INTEGER,
    created_at INTEGER NOT NULL
);
CREATE TABLE m_STTCallTranscriptRevision (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    call_transcript_id INTEGER NOT NULL,
    revision INTEGER NOT NULL,
    content_hash TEXT NOT NULL,
    parts_hash TEXT NOT NULL,
    completeness_status TEXT NOT NULL,
    payload TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE m_STTCallTranscriptEvent (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    event_type TEXT NOT NULL,
    event_key TEXT NOT NULL UNIQUE,
    call_transcript_id INTEGER NOT NULL,
    call_id TEXT NOT NULL,
    revision INTEGER NOT NULL,
    content_hash TEXT NOT NULL,
    parts_hash TEXT NOT NULL,
    completeness_status TEXT NOT NULL,
    created_at INTEGER NOT NULL
);

INSERT INTO m_STTSetting(key, value) VALUES
    ('poll_interval_sec', '17'),
    ('cdr_history_days', '730'),
    ('processing_timeout_sec', '1800'),
    ('call_transcript_stability_sec', '30'),
    ('cdr_scan_cursor_endtime', '2026-08-11 10:20:30'),
    ('cdr_scan_cursor_id', '701'),
    ('cdr_backfill_cursor_endtime', '2026-07-01 01:02:03'),
    ('cdr_backfill_cursor_id', '509');
INSERT INTO m_STTWorker(id, worker_uid, name, status, api_key_id, has_settings_changed, last_seen, created_at)
VALUES (1, 'legacy-worker', 'Legacy Mac', 'online', 41, 0, 1700000000, 1690000000);
INSERT INTO m_STTJob(
    id, call_id, file_path, status, worker_id, attempts, created_at, updated_at,
    started_at, lease_token, lease_expires_at, error_msg
) VALUES
    (10, 'active', '/tmp/active.wav', 'processing', 1, 1, 1690000000, 1700000000,
     1700000000, 'secret-lease', 1800000000, ''),
    (11, 'done', '/tmp/done.wav', 'done', 1, 2, 1690000000, 1700000000,
     1700000000, NULL, NULL, '');
INSERT INTO m_STTJob(id, call_id, file_path, status, created_at, updated_at)
VALUES (41, 'legacy-call', '/storage/legacy.wav', 'done', 1700000000, 1700000100);
INSERT INTO m_STTResult(id, job_id, transcript, created_at)
VALUES (51, 41, '[{"start_ms":0,"end_ms":1000,"text":"legacy"}]', 1700000200);
INSERT INTO m_STTTranscriptEvent(
    id, event_type, event_key, result_id, job_id, call_id, update_type, payload, created_at
) VALUES (
    61, 'transcript.completed', 'transcript.completed:51', 51, 41, 'legacy-call', 'created', '{}', 1700000200
);
