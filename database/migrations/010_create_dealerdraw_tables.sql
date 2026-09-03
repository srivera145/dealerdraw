-- DealerDraw promotional games: campaign shell, squares boards, claims, prizes, wins.
-- campaigns/campaign_types stay game-type agnostic; boards is the squares-specific instance table.

CREATE TABLE IF NOT EXISTS campaign_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO campaign_types (slug, name, active)
VALUES ('squares', 'Football Squares', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), active = VALUES(active);

CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    campaign_type_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('draft', 'active', 'paused', 'complete') NOT NULL DEFAULT 'draft',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    brand_primary_color VARCHAR(7) NULL,
    brand_logo_path VARCHAR(255) NULL,
    public_slug VARCHAR(64) NOT NULL UNIQUE,
    terms_text TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_campaigns_tenant (tenant_id, status),
    CONSTRAINT fk_campaigns_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaigns_type FOREIGN KEY (campaign_type_id) REFERENCES campaign_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- League schedule data is shared across tenants; tenant scoping happens at the campaign level.
CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    league ENUM('nfl', 'ncaaf') NOT NULL,
    external_id VARCHAR(64) NULL,
    home_team VARCHAR(100) NOT NULL,
    away_team VARCHAR(100) NOT NULL,
    kickoff_at DATETIME NOT NULL,
    status ENUM('scheduled', 'in_progress', 'final') NOT NULL DEFAULT 'scheduled',
    q1_home_score INT NULL,
    q1_away_score INT NULL,
    q2_home_score INT NULL,
    q2_away_score INT NULL,
    q3_home_score INT NULL,
    q3_away_score INT NULL,
    q4_home_score INT NULL,
    q4_away_score INT NULL,
    final_home_score INT NULL,
    final_away_score INT NULL,
    scores_source ENUM('feed', 'manual') NOT NULL DEFAULT 'feed',
    last_synced_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_games_league_external (league, external_id),
    INDEX idx_games_status_kickoff (status, kickoff_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    game_id INT NOT NULL,
    status ENUM('open', 'locked', 'scoring', 'complete') NOT NULL DEFAULT 'open',
    locked_at DATETIME NULL,
    row_digits JSON NULL,
    col_digits JSON NULL,
    claim_limit INT NOT NULL DEFAULT 5,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_boards_campaign (campaign_id),
    INDEX idx_boards_game_status (game_id, status),
    CONSTRAINT fk_boards_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_boards_game FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    consent_sms TINYINT(1) NOT NULL DEFAULT 0,
    consent_email TINYINT(1) NOT NULL DEFAULT 0,
    ip VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claims_board_email (board_id, email),
    INDEX idx_claims_board_phone (board_id, phone),
    CONSTRAINT fk_claims_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS squares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    row_index TINYINT UNSIGNED NOT NULL,
    col_index TINYINT UNSIGNED NOT NULL,
    claim_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_squares_cell (board_id, row_index, col_index),
    INDEX idx_squares_claim (claim_id),
    CONSTRAINT fk_squares_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_squares_claim FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    scoring_period ENUM('q1', 'q2', 'q3', 'final') NOT NULL,
    label VARCHAR(255) NOT NULL,
    retail_value DECIMAL(10, 2) NULL,
    terms_text TEXT NULL,
    expires_days INT NOT NULL DEFAULT 30,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_prizes_board_period (board_id, scoring_period),
    CONSTRAINT fk_prizes_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- uniq_wins_board_period is what makes winner resolution idempotent.
CREATE TABLE IF NOT EXISTS wins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    prize_id INT NOT NULL,
    square_id INT NOT NULL,
    claim_id INT NOT NULL,
    scoring_period ENUM('q1', 'q2', 'q3', 'final') NOT NULL,
    redemption_code CHAR(8) NOT NULL,
    redeemed_at DATETIME NULL,
    redeemed_by_user_id INT NULL,
    notified_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wins_board_period (board_id, scoring_period),
    UNIQUE KEY uniq_wins_code (redemption_code),
    INDEX idx_wins_claim (claim_id),
    CONSTRAINT fk_wins_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_wins_prize FOREIGN KEY (prize_id) REFERENCES prizes(id),
    CONSTRAINT fk_wins_square FOREIGN KEY (square_id) REFERENCES squares(id),
    CONSTRAINT fk_wins_claim FOREIGN KEY (claim_id) REFERENCES claims(id),
    CONSTRAINT fk_wins_redeemer FOREIGN KEY (redeemed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tenant_id NULL means a platform-wide default offer every dealer can start from.
CREATE TABLE IF NOT EXISTS prize_library (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    label VARCHAR(255) NOT NULL,
    retail_value DECIMAL(10, 2) NULL,
    terms_text TEXT NULL,
    expires_days INT NOT NULL DEFAULT 30,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_prize_library_tenant (tenant_id),
    CONSTRAINT fk_prize_library_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO prize_library (tenant_id, label, retail_value, terms_text, expires_days)
SELECT * FROM (
    SELECT NULL AS tenant_id,
           'Free Oil Change' AS label,
           79.95 AS retail_value,
           'Conventional oil and filter. Most vehicles. Not combinable with other offers.' AS terms_text,
           90 AS expires_days
    UNION ALL
    SELECT NULL, 'Free Tire Rotation and Brake Inspection', 49.95, 'Most vehicles. Appointment required. Not combinable with other offers.', 90
    UNION ALL
    SELECT NULL, 'Free Full Detail', 249.00, 'Interior and exterior detail. Appointment required. Not combinable with other offers.', 90
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM prize_library WHERE tenant_id IS NULL);
