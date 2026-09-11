PRAGMA foreign_keys = OFF;

CREATE TABLE m_STTSetting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, key TEXT NOT NULL UNIQUE, value TEXT);
CREATE TABLE m_STTWorker (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, worker_uid TEXT NOT NULL UNIQUE, name TEXT, ip TEXT,
    status TEXT DEFAULT 'offline', model TEXT, api_key_id INTEGER, last_seen INTEGER, created_at INTEGER NOT NULL,
    has_settings_changed INTEGER NOT NULL DEFAULT 1, api_version INTEGER NOT NULL DEFAULT 1,
    app_version TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE m_STTJob (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, call_id TEXT NOT NULL, file_path TEXT NOT NULL UNIQUE,
    status TEXT DEFAULT 'pending', priority INTEGER DEFAULT 5, worker_id INTEGER, attempts INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, cdr_endtime INTEGER, cdr_id INTEGER,
    cdr_uniqueid TEXT, cdr_start_ms INTEGER, cdr_end_ms INTEGER, recording_fingerprint TEXT,
    recording_duration_sec INTEGER, started_at INTEGER, lease_token TEXT, lease_expires_at INTEGER,
    finished_at INTEGER, error_msg TEXT
);
CREATE TABLE m_STTResult (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, job_id INTEGER NOT NULL UNIQUE, transcript TEXT NOT NULL,
    language TEXT, duration REAL, model_used TEXT, technical_info TEXT, created_at INTEGER NOT NULL
);
CREATE TABLE m_STTTranscriptEvent (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event_type TEXT NOT NULL, event_key TEXT NOT NULL UNIQUE,
    result_id INTEGER NOT NULL, job_id INTEGER, call_id TEXT, update_type TEXT, payload TEXT, created_at INTEGER NOT NULL
);
CREATE TABLE m_STTRetryState (
    cdr_id INTEGER PRIMARY KEY, retry_type TEXT NOT NULL, payload TEXT NOT NULL, reason TEXT NOT NULL,
    first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL, next_retry_at INTEGER NOT NULL
);
CREATE TABLE m_STTCallTranscript (
    id INTEGER PRIMARY KEY AUTOINCREMENT, call_id TEXT NOT NULL, current_revision INTEGER NOT NULL DEFAULT 0,
    current_content_hash TEXT NOT NULL DEFAULT '', current_parts_hash TEXT NOT NULL DEFAULT '',
    pending_content_hash TEXT NOT NULL DEFAULT '', pending_parts_hash TEXT NOT NULL DEFAULT '',
    pending_payload TEXT NOT NULL DEFAULT '', completeness_status TEXT NOT NULL DEFAULT 'pending',
    stable_after INTEGER NOT NULL DEFAULT 0, published_at INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE TABLE m_STTCallTranscriptPart (
    id INTEGER PRIMARY KEY AUTOINCREMENT, call_transcript_id INTEGER NOT NULL, result_id INTEGER NOT NULL,
    job_id INTEGER, cdr_id INTEGER, cdr_uniqueid TEXT, cdr_start_ms INTEGER, cdr_end_ms INTEGER,
    recording_fingerprint TEXT NOT NULL, content_hash TEXT NOT NULL, source_label TEXT NOT NULL,
    resolution_status TEXT NOT NULL DEFAULT 'complete', segments_json TEXT NOT NULL DEFAULT '[]',
    is_current INTEGER NOT NULL DEFAULT 1, superseded_by_part_id INTEGER, created_at INTEGER NOT NULL
);
CREATE TABLE m_STTCallTranscriptRevision (
    id INTEGER PRIMARY KEY AUTOINCREMENT, call_transcript_id INTEGER NOT NULL, revision INTEGER NOT NULL,
    content_hash TEXT NOT NULL, parts_hash TEXT NOT NULL, completeness_status TEXT NOT NULL,
    payload TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE m_STTCallTranscriptEvent (
    id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, event_key TEXT NOT NULL,
    call_transcript_id INTEGER NOT NULL, call_id TEXT NOT NULL, revision INTEGER NOT NULL,
    content_hash TEXT NOT NULL, parts_hash TEXT NOT NULL, completeness_status TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE m_STTJobSubmissionReceipt (
    id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, worker_id INTEGER NOT NULL,
    lease_token_hash TEXT NOT NULL, operation TEXT NOT NULL CHECK(operation IN ('result', 'failure')),
    payload_digest TEXT NOT NULL, response_json TEXT NOT NULL, http_code INTEGER NOT NULL,
    created_at INTEGER NOT NULL, UNIQUE(job_id, lease_token_hash)
);
CREATE TABLE m_STTSchemaMigration (
    version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL, applied_at INTEGER NOT NULL
);

CREATE UNIQUE INDEX m_STTCallTranscript_call_id ON m_STTCallTranscript(call_id);
CREATE INDEX m_STTCallTranscript_publish ON m_STTCallTranscript(stable_after, pending_content_hash);
CREATE UNIQUE INDEX m_STTCallTranscriptPart_version ON m_STTCallTranscriptPart(call_transcript_id, result_id, content_hash, recording_fingerprint);
CREATE UNIQUE INDEX m_STTCallTranscriptPart_current_result ON m_STTCallTranscriptPart(call_transcript_id, result_id) WHERE is_current = 1;
CREATE UNIQUE INDEX m_STTCallTranscriptRevision_number ON m_STTCallTranscriptRevision(call_transcript_id, revision);
CREATE UNIQUE INDEX m_STTCallTranscriptRevision_hash ON m_STTCallTranscriptRevision(call_transcript_id, content_hash);
CREATE UNIQUE INDEX m_STTCallTranscriptEvent_key ON m_STTCallTranscriptEvent(event_key);
CREATE INDEX m_STTCallTranscriptEvent_cursor ON m_STTCallTranscriptEvent(created_at, id);
CREATE UNIQUE INDEX m_STTTranscriptEvent_event_key ON m_STTTranscriptEvent(event_key);
CREATE INDEX m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(created_at, id);
CREATE INDEX m_STTTranscriptEvent_result_id ON m_STTTranscriptEvent(result_id);
CREATE INDEX m_STTRetryState_due ON m_STTRetryState(retry_type, next_retry_at, first_seen, cdr_id);
CREATE INDEX m_STTJob_call_status ON m_STTJob(call_id, status);
CREATE INDEX m_STTJob_cdr_id ON m_STTJob(cdr_id);
CREATE INDEX m_STTResult_created_at ON m_STTResult(created_at);
CREATE UNIQUE INDEX i_m_STTWorker_api_key_id_v2_unique ON m_STTWorker(api_key_id) WHERE api_key_id IS NOT NULL AND api_version = 2;
CREATE INDEX i_m_STTJobSubmissionReceipt_worker ON m_STTJobSubmissionReceipt(worker_id, created_at);

INSERT INTO m_STTSchemaMigration(version, name, checksum, applied_at) VALUES
 (1,'base settings','stt-schema-001-base-settings',1700000001),
 (2,'worker and job schema','stt-schema-002-worker-job',1700000002),
 (3,'durable retry state','stt-schema-003-retry-state',1700000003),
 (4,'transcript result events','stt-schema-004-transcript-events',1700000004),
 (5,'call transcript revisions','stt-schema-005-call-transcripts',1700000005),
 (6,'worker processing defaults','stt-schema-006-worker-processing',1700000006),
 (7,'canonical module indexes','stt-schema-007-module-indexes',1700000007),
 (8,'complete transcript result event backfill','stt-schema-008-complete-transcript-events',1700000008),
 (9,'complete call transcript backfill','stt-schema-009-complete-call-transcripts',1700000009),
 (10,'transcript retention policy','stt-schema-010-transcript-retention',1700000010),
 (11,'worker API v2 contract','stt-schema-011-worker-api-v2',1700000011),
 (12,'exclusive worker API-key bindings','stt-schema-012-exclusive-worker-api-key-bindings',1700000012),
 (13,'repair v2-only worker API-key binding index','stt-schema-013-repair-v2-worker-api-key-index',1700000013),
 (14,'terminal job submission receipts','stt-schema-014-job-submission-receipts',1700000014);
INSERT INTO m_STTSetting(key,value) VALUES
 ('cdr_scan_cursor_endtime','2026-09-01 10:00:00'),('cdr_scan_cursor_id','100'),
 ('cdr_backfill_cursor_endtime','2026-08-01 10:00:00'),('cdr_backfill_cursor_id','50'),
 ('delayed_recordings','[]'),('skipped_recordings','[]'),('worker_api_key_ids','[41]'),
 ('worker_api_key_history_ids','[40]'),('transcript_retention_days','365');
INSERT INTO m_STTWorker(id,worker_uid,name,status,api_key_id,last_seen,created_at,has_settings_changed,api_version,app_version,updated_at)
 VALUES(1,'fixture-worker','Fixture worker','online',41,1700000300,1700000000,0,2,'1.0',1700000300);
INSERT INTO m_STTJob(id,call_id,file_path,status,priority,worker_id,attempts,created_at,updated_at,cdr_endtime,cdr_id,cdr_uniqueid,cdr_start_ms,cdr_end_ms,recording_fingerprint,finished_at)
 VALUES(1,'1700000000.1','/storage/fixture.wav','done',5,1,1,1700000000,1700000200,1700000100,1,'1700000000.2',1700000000000,1700000060000,'fixture-fingerprint',1700000200);
INSERT INTO m_STTResult(id,job_id,transcript,language,duration,model_used,technical_info,created_at)
 VALUES(1,1,'[{"start_ms":0,"end_ms":1000,"text":"fixture"}]','en',60.0,'fixture-model','{}',1700000200);
INSERT INTO m_STTTranscriptEvent(id,event_type,event_key,result_id,job_id,call_id,update_type,payload,created_at)
 VALUES(1,'transcript.completed','transcript.completed:1',1,1,'1700000000.1','created','{}',1700000200);
INSERT INTO m_STTRetryState(cdr_id,retry_type,payload,reason,first_seen,last_seen,next_retry_at)
 VALUES(2,'delayed','{"cdr_id":2}','recording_not_ready',1700000000,1700000010,1700000100);
INSERT INTO m_STTCallTranscript(id,call_id,current_revision,current_content_hash,current_parts_hash,completeness_status,published_at,created_at,updated_at)
 VALUES(1,'1700000000.1',1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','complete',1700000200,1700000000,1700000200);
INSERT INTO m_STTCallTranscriptPart(id,call_transcript_id,result_id,job_id,cdr_id,cdr_uniqueid,cdr_start_ms,cdr_end_ms,recording_fingerprint,content_hash,source_label,segments_json,created_at)
 VALUES(1,1,1,1,1,'1700000000.2',1700000000000,1700000060000,'fixture-fingerprint','cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc','recording','[]',1700000200);
INSERT INTO m_STTCallTranscriptRevision(id,call_transcript_id,revision,content_hash,parts_hash,completeness_status,payload,created_at)
 VALUES(1,1,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','complete','{}',1700000200);
INSERT INTO m_STTCallTranscriptEvent(id,event_type,event_key,call_transcript_id,call_id,revision,content_hash,parts_hash,completeness_status,created_at)
 VALUES(1,'call-transcript.completed','call-transcript.completed:1:1',1,'1700000000.1',1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','complete',1700000200);
INSERT INTO m_STTJobSubmissionReceipt(id,job_id,worker_id,lease_token_hash,operation,payload_digest,response_json,http_code,created_at)
 VALUES(1,1,1,'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd','result','eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','{"success":true}',200,1700000200);
