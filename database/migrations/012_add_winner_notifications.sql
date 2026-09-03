-- Winner notification delivery state, unclaimed winning squares, and SMS opt-outs.

-- A winning square nobody claimed still produces a win row so the dealer sees it
-- and can decide what to do with the prize. Those rows carry no claimant.
ALTER TABLE wins
    MODIFY COLUMN claim_id INT NULL,
    ADD COLUMN sms_message_uuid VARCHAR(64) NULL AFTER notified_at,
    ADD COLUMN sms_status VARCHAR(32) NULL AFTER sms_message_uuid,
    ADD COLUMN sms_status_at DATETIME NULL AFTER sms_status,
    ADD COLUMN email_status VARCHAR(32) NULL AFTER sms_status_at,
    ADD COLUMN notify_error VARCHAR(255) NULL AFTER email_status;

CREATE INDEX idx_wins_sms_message_uuid ON wins (sms_message_uuid);

-- One row per opted-out number per tenant. The unique key is what makes the
-- suppression apply across every board that tenant runs, not just the one the
-- STOP came from.
CREATE TABLE IF NOT EXISTS sms_opt_outs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    phone_e164 VARCHAR(20) NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'inbound_stop',
    keyword VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sms_opt_out (tenant_id, phone_e164),
    CONSTRAINT fk_sms_opt_outs_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
