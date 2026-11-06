CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE remember_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_remember_tokens_expiry (expires_at),
  CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE customers (
  id CHAR(36) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  original_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  outstanding_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_customers_active (archived_at, outstanding_due),
  INDEX idx_customers_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE transactions (
  id CHAR(36) PRIMARY KEY,
  customer_id CHAR(36) NOT NULL,
  type ENUM('given','received') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_customer_transaction (customer_id, id),
  INDEX idx_transactions_period (type, occurred_at),
  CONSTRAINT fk_transactions_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

CREATE TABLE sync_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id VARCHAR(64) NOT NULL,
  payload JSON NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_sync_pending (synced_at, attempts)
) ENGINE=InnoDB;
