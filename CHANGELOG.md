# Changelog

## Unreleased

- Added one persistent source-instance identity to the Local STT database and to call event/detail responses, including empty event pages.
- Normal in-place upgrades preserve the identity. A clean database creates a different identity so source-local aggregate, result, job, revision, and cursor values cannot collide across installations.
- Ordinary coordinated upgrades are zero-touch in either order. Install the source-aware AI Supervisor build before intentionally deleting an old Local STT database; a clean reset is not safe while AI Supervisor 1.73 remains installed.
- AI Supervisor can preserve existing results for unchanged `call_id + content_hash` calls and automatically queue changed or uncertain content without adding an administrator action.
