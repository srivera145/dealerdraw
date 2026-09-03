-- Demo requests from the marketing site. Not tenant-scoped: a lead exists
-- before there is a dealership record to attach it to.

CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_name VARCHAR(255) NOT NULL,
    contact_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    rooftop_count INT NULL,
    message TEXT NULL,
    source VARCHAR(64) NOT NULL DEFAULT 'landing_page',
    ip VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leads_created (created_at),
    INDEX idx_leads_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
