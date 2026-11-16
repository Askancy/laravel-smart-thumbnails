# Frozen 4.3 identity and manifest writers

Copied from commit `bac730b381743db9a3ec9f722874b5a5213ad46f` (4.3.0).
Only the namespace and the ThumbnailGenerator import were changed to load these alongside current
classes. LegacyAdoptionTest uses these original implementations to create real derivative paths and
schema-1 JSON manifests, including date-based paths. Do not update them with the current identity code.
