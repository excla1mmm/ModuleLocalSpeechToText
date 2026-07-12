<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class CallTranscriptSchemaService
{
    public function ensure(mixed $connection): void
    {
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTCallTranscript (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                call_id TEXT NOT NULL,
                current_revision INTEGER NOT NULL DEFAULT 0,
                current_content_hash TEXT NOT NULL DEFAULT \'\',
                current_parts_hash TEXT NOT NULL DEFAULT \'\',
                pending_content_hash TEXT NOT NULL DEFAULT \'\',
                pending_parts_hash TEXT NOT NULL DEFAULT \'\',
                pending_payload TEXT NOT NULL DEFAULT \'\',
                completeness_status TEXT NOT NULL DEFAULT \'pending\',
                stable_after INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS m_STTCallTranscript_call_id
                ON m_STTCallTranscript (call_id)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS m_STTCallTranscript_publish
                ON m_STTCallTranscript (stable_after, pending_content_hash)'
        );

        $connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTCallTranscriptPart (
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
                resolution_status TEXT NOT NULL DEFAULT \'complete\',
                segments_json TEXT NOT NULL DEFAULT \'[]\',
                is_current INTEGER NOT NULL DEFAULT 1,
                superseded_by_part_id INTEGER,
                created_at INTEGER NOT NULL
            )'
        );
        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS m_STTCallTranscriptPart_version
                ON m_STTCallTranscriptPart (call_transcript_id, result_id, content_hash, recording_fingerprint)'
        );
        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS m_STTCallTranscriptPart_current_result
                ON m_STTCallTranscriptPart (call_transcript_id, result_id)
                WHERE is_current = 1'
        );

        $connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTCallTranscriptRevision (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                call_transcript_id INTEGER NOT NULL,
                revision INTEGER NOT NULL,
                content_hash TEXT NOT NULL,
                parts_hash TEXT NOT NULL,
                completeness_status TEXT NOT NULL,
                payload TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );
        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS m_STTCallTranscriptRevision_number
                ON m_STTCallTranscriptRevision (call_transcript_id, revision)'
        );
        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS m_STTCallTranscriptRevision_hash
                ON m_STTCallTranscriptRevision (call_transcript_id, content_hash)'
        );

        $connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTCallTranscriptEvent (
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
            )'
        );
        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS m_STTCallTranscriptEvent_key
                ON m_STTCallTranscriptEvent (event_key)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS m_STTCallTranscriptEvent_cursor
                ON m_STTCallTranscriptEvent (created_at, id)'
        );
    }
}
