-- My Loans feature: migration for the existing loan_management database.
-- Safe to run on a live database:
--   * Only ADDS two new tables. It does not change, empty or drop
--     the existing `clients` or `transactions` tables or their data.
--   * Uses IF NOT EXISTS, so running it twice does nothing the second time.
-- Run in phpMyAdmin (SQL tab) or:  mysql -u root loan_management < my_loans_migration.sql
-- (Index.php also creates these tables automatically on first load, so this is optional.)

CREATE DATABASE IF NOT EXISTS loan_management CHARACTER SET utf8mb4;
USE loan_management;

-- Money the owner owes to other people.
-- A loan is unpaid while settled_at is NULL; the paid amount is the sum of its payments.
CREATE TABLE IF NOT EXISTS my_loans (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150)  NOT NULL,           -- who the owner owes
    amt         DECIMAL(12,2) NOT NULL,           -- amount borrowed (PHP)
    loan_date   DATE          NULL,               -- date borrowed (optional)
    reason      TEXT          NULL,               -- optional reason
    encoded_at  DATETIME      NOT NULL,           -- when it was entered (used when loan_date is empty)
    settled_at  DATETIME      NULL,               -- when it was fully paid
    INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each Settle / Custom settlement adds rows here.
CREATE TABLE IF NOT EXISTS my_loan_payments (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    loan_id  INT           NOT NULL,
    amt      DECIMAL(12,2) NOT NULL,
    paid_at  DATETIME      NOT NULL,
    FOREIGN KEY (loan_id) REFERENCES my_loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
