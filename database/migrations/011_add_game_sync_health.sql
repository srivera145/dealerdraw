-- Feed health per game: consecutive failure count, the backoff gate the poller
-- honours, and the flag an admin sees when a game stops syncing.

ALTER TABLE games
    ADD COLUMN sync_failure_count INT NOT NULL DEFAULT 0 AFTER last_synced_at,
    ADD COLUMN sync_alert TINYINT(1) NOT NULL DEFAULT 0 AFTER sync_failure_count,
    ADD COLUMN sync_retry_after DATETIME NULL AFTER sync_alert,
    ADD COLUMN last_sync_error VARCHAR(255) NULL AFTER sync_retry_after,
    ADD COLUMN last_sync_error_at DATETIME NULL AFTER last_sync_error;

CREATE INDEX idx_games_sync_alert ON games (sync_alert, kickoff_at);
