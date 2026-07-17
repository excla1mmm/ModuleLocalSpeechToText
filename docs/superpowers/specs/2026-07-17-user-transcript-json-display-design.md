# User Transcript JSON Display Design

## Goal

Replace the technical diagnostics JSON shown on the transcript card with a concise, readable JSON representation of the call and every saved transcript phrase.

## Scope

This change affects only the administrator transcript-card presentation.

It must not change:

- worker-to-PBX request payloads;
- stored `transcript` or `technical_info` values;
- REST request or response schemas;
- transcript integration API output;
- transcript event payloads;
- server-side filtering, aggregation, or call-transcript revision behavior.

The existing technical diagnostics remain stored and available to existing integrations, but the transcript card no longer renders them.

## User JSON Shape

The expanded JSON uses this stable presentation-only structure:

```json
{
  "call": {
    "id": "call-id",
    "started_at": "2026-07-17 12:00:00",
    "ended_at": "2026-07-17 12:02:02",
    "duration_seconds": 122.088,
    "source_number": "201",
    "destination_number": "74950000000",
    "language": "ru",
    "model": "large-v3-v20240930_turbo"
  },
  "phrases": [
    {
      "speaker": "Участник 1",
      "start_ms": 1670,
      "end_ms": 5560,
      "text": "Текст фразы"
    }
  ]
}
```

All call keys remain present. Unavailable optional values are represented as `null`, not empty strings. `duration_seconds` remains numeric and preserves the stored call duration.

## Phrase Mapping

The presentation reads the existing stored transcript JSON without modifying it. It preserves the source array order and emits one phrase for every valid, non-empty saved segment.

Each phrase contains only:

- `speaker`: localized participant label;
- `start_ms`: integer start time or `null`;
- `end_ms`: integer end time or `null`;
- `text`: trimmed transcript text.

Speaker mapping is:

- `left` to the localized `Speaker1` label;
- `right` to the localized `Speaker2` label;
- another non-empty `speaker` value to that value;
- another non-empty `channel` value to that value;
- no speaker/channel to the generic localized `Speaker` label.

Non-array segments and segments with empty text are omitted. Malformed transcript JSON produces an empty `phrases` array and still renders the available call metadata.

## UI

The transcript card keeps one collapsed details element below the conversation. Its summary is the localized equivalent of `Show JSON`. Expanding it shows pretty-printed JSON with unescaped Unicode, indentation, a neutral code-style background, horizontal and vertical scrolling, and a bounded height.

The technical diagnostics heading, metric grid, and technical raw JSON are removed from this card. No copy/download action or syntax-highlighting dependency is added.

## Implementation Boundary

A focused presentation service builds the display payload and formatted JSON from call metadata and the stored transcript. The controller supplies already-loaded result, job, and CDR data. The Volt template only renders the prepared string, keeping JSON construction out of the view.

The existing technical diagnostics service and integration service contracts are not modified. They may remain in the repository for non-card use and compatibility.

## Testing

Tests must verify:

- the exact top-level `call` and `phrases` shape;
- model, language, call times, duration, and source/destination numbers;
- all non-empty phrases remain in source order;
- localized left/right participant labels;
- invalid and empty transcript elements are omitted;
- malformed transcript JSON yields an empty phrase list;
- technical diagnostics fields do not appear in presentation JSON;
- the transcript template renders the presentation JSON block and no longer renders the technical diagnostics block;
- existing REST and integration contract tests continue to pass unchanged.

