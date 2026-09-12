# Changelog

## Unreleased

- Added one persistent source-instance identity to the Local STT database and to call event/detail responses, including empty event pages.
- Normal in-place upgrades preserve the identity. A clean database creates a different identity so source-local aggregate, result, job, revision, and cursor values cannot collide across installations.
- Ordinary coordinated upgrades are zero-touch in either order. Install the source-aware AI Supervisor build before intentionally deleting an old Local STT database; a clean reset is not safe while AI Supervisor 1.73 remains installed.
- AI Supervisor can preserve existing results for unchanged `call_id + content_hash` calls and automatically queue changed or uncertain content without adding an administrator action.
- Settings tab: numeric fields (poll interval, max recording duration, CDR scan limit, processing timeout) now render as narrow inline inputs with the label beside them, instead of full-width fields stacked above their labels.
- Settings tab: the CDR history and transcript retention sliders now inset their track from the segment edges, so the first and last tick labels no longer touch the box border.
- Workers tab: removed the "Worker update instructions" link and its translation key; it pointed at a fixed docs URL with no per-worker context.
