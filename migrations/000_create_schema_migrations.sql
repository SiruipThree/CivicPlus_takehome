CREATE TABLE IF NOT EXISTS schema_migrations (
    migration TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);
