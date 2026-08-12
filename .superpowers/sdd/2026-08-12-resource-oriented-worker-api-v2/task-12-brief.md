# Task 12 brief — STT v2 suite alignment

Align the repository contracts with the completed resource-oriented Worker API v2 implementation. Preserve `min_pbx_version` `2025.1.1`, Core read-only scope, v2 authentication/provenance controls, lifecycle 503/retry semantics, and all public transcript/event schema coverage.

The bounded scope covers lifecycle-gate contracts, the 500-line controller limit, REST contract migration from v1 worker settings to the caller-bound singleton, tombstone provenance ordering, case-insensitive version-header semantics, and processing-settings endpoint contracts. Verify focused checks and both route/version mutation modes before the full module suite.
