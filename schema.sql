-- Суммы хранятся целыми числами в единицах 1e-8 (как сатоши) — без ошибок округления.

CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  seed_fp CHAR(64) NOT NULL UNIQUE,      -- отпечаток сид-фразы (HMAC), саму фразу сервер не хранит
  blocked TINYINT NOT NULL DEFAULT 0,
  hide_zero TINYINT NOT NULL DEFAULT 0,
  session_ver INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wallets (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INTEGER NOT NULL,
  name VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX wallets_user ON wallets(user_id);

CREATE TABLE addresses (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  wallet_id INTEGER NOT NULL,
  coin VARCHAR(10) NOT NULL,
  address VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX addresses_wallet ON addresses(wallet_id);

CREATE TABLE balances (
  wallet_id INTEGER NOT NULL,
  coin VARCHAR(10) NOT NULL,
  amount BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (wallet_id, coin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE txs (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  hash CHAR(64) NOT NULL UNIQUE,
  type VARCHAR(16) NOT NULL,             -- transfer | credit | debit
  coin VARCHAR(10) NOT NULL,
  amount BIGINT NOT NULL,
  from_wallet INTEGER NULL,
  to_wallet INTEGER NULL,
  from_addr VARCHAR(80) NULL,
  to_addr VARCHAR(80) NULL,
  coin2 VARCHAR(10) NULL,                -- обмен: какая монета получена
  amount2 BIGINT NULL,
  note VARCHAR(140) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX txs_from ON txs(from_wallet);
CREATE INDEX txs_to ON txs(to_wallet);

-- Заявки пользователей администратору: пополнение и вывод
CREATE TABLE requests (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INTEGER NOT NULL,
  wallet_id INTEGER NOT NULL,
  type VARCHAR(10) NOT NULL,             -- deposit | withdraw
  coin VARCHAR(10) NOT NULL,
  amount BIGINT NOT NULL,
  note VARCHAR(200) NULL,                -- комментарий пользователя
  status VARCHAR(10) NOT NULL DEFAULT 'pending',  -- pending | approved | rejected | cancelled
  admin_note VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX requests_user ON requests(user_id);
CREATE INDEX requests_status ON requests(status);

-- Расширение для Chrome: ключи доступа и подключение через сайт
CREATE TABLE api_tokens (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INTEGER NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,   -- сам ключ хранится только в расширении
  label VARCHAR(60) NOT NULL DEFAULT 'Chrome',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX api_tokens_user ON api_tokens(user_id);

CREATE TABLE ext_links (
  code_hash CHAR(64) PRIMARY KEY,
  user_id INTEGER NULL,
  token VARCHAR(80) NULL,                -- выдаётся расширению один раз и сразу удаляется
  created_at INTEGER NOT NULL            -- unix-время, ссылка живёт 10 минут
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Контакты: подписанные адреса других участников
CREATE TABLE contacts (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id INTEGER NOT NULL,
  name VARCHAR(60) NOT NULL,
  address VARCHAR(80) NOT NULL,
  coin VARCHAR(10) NOT NULL,
  note VARCHAR(140) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY contacts_user_addr (user_id, address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
