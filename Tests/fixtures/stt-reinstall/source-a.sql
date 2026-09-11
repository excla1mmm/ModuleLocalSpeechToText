PRAGMA foreign_keys = OFF;

CREATE TABLE m_STTSetting (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL UNIQUE,
    value TEXT
);
CREATE TABLE m_STTCallTranscript (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    call_id TEXT NOT NULL,
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
CREATE UNIQUE INDEX m_STTCallTranscript_call_id ON m_STTCallTranscript(call_id);
CREATE INDEX m_STTCallTranscript_publish ON m_STTCallTranscript(stable_after, pending_content_hash);
CREATE TABLE m_STTCallTranscriptPart (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
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
CREATE UNIQUE INDEX m_STTCallTranscriptPart_version ON m_STTCallTranscriptPart(call_transcript_id, result_id, content_hash, recording_fingerprint);
CREATE UNIQUE INDEX m_STTCallTranscriptPart_current_result ON m_STTCallTranscriptPart(call_transcript_id, result_id) WHERE is_current = 1;
CREATE TABLE m_STTCallTranscriptRevision (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    call_transcript_id INTEGER NOT NULL,
    revision INTEGER NOT NULL,
    content_hash TEXT NOT NULL,
    parts_hash TEXT NOT NULL,
    completeness_status TEXT NOT NULL,
    payload TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX m_STTCallTranscriptRevision_number ON m_STTCallTranscriptRevision(call_transcript_id, revision);
CREATE UNIQUE INDEX m_STTCallTranscriptRevision_hash ON m_STTCallTranscriptRevision(call_transcript_id, content_hash);
CREATE TABLE m_STTCallTranscriptEvent (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    event_key TEXT NOT NULL,
    call_transcript_id INTEGER NOT NULL,
    call_id TEXT NOT NULL,
    revision INTEGER NOT NULL,
    content_hash TEXT NOT NULL,
    parts_hash TEXT NOT NULL,
    completeness_status TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX m_STTCallTranscriptEvent_key ON m_STTCallTranscriptEvent(event_key);
CREATE INDEX m_STTCallTranscriptEvent_cursor ON m_STTCallTranscriptEvent(created_at, id);

INSERT INTO m_STTSetting(key, value) VALUES ('source_instance_id', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
WITH RECURSIVE calls(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM calls WHERE n < 100)
INSERT INTO m_STTCallTranscript
    (id, call_id, current_revision, current_content_hash, current_parts_hash,
     completeness_status, published_at, created_at, updated_at)
SELECT n, printf('call-%03d', n), 1, printf('%064x', n), printf('%064x', n + 1000),
       'complete', 1800000000 - (n * 60),
       CASE WHEN n <= 50 THEN 1800000000 - (n * 86400) ELSE 1700000000 - (n * 86400) END,
       1800000000 - (n * 60)
FROM calls;
WITH RECURSIVE calls(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM calls WHERE n < 100)
INSERT INTO m_STTCallTranscriptPart
    (id, call_transcript_id, result_id, job_id, cdr_id, cdr_uniqueid, cdr_start_ms, cdr_end_ms,
     recording_fingerprint, content_hash, source_label, segments_json, created_at)
SELECT n, n, n, n, n, printf('source-a-%03d', n), n * 1000, n * 1000 + 60000,
       printf('%064x', n + 2000), printf('%064x', n), 'mono', '[]', 1800000000 - (n * 60)
FROM calls;
WITH RECURSIVE calls(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM calls WHERE n < 100)
INSERT INTO m_STTCallTranscriptRevision
    (id, call_transcript_id, revision, content_hash, parts_hash, completeness_status, payload, created_at)
SELECT n, n, 1, printf('%064x', n), printf('%064x', n + 1000), 'complete',
       json_object('call_id', printf('call-%03d', n), 'part_manifest', json_array()),
       1800000000 - (n * 60)
FROM calls;
WITH RECURSIVE calls(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM calls WHERE n < 100)
INSERT INTO m_STTCallTranscriptEvent
    (id, event_type, event_key, call_transcript_id, call_id, revision,
     content_hash, parts_hash, completeness_status, created_at)
SELECT n, 'call-transcript.completed', printf('source-a:%03d', n), n, printf('call-%03d', n), 1,
       printf('%064x', n), printf('%064x', n + 1000), 'complete', 1800000000 - 10000 + n
FROM calls;
