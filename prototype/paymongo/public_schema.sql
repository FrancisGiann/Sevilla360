-- ONLY after schema.sql in a NEW, EMPTY, DEDICATED demo database.
-- Never apply either file to the application database.
CREATE TABLE pm_demo_identity (purpose VARCHAR(60) PRIMARY KEY);
INSERT INTO pm_demo_identity VALUES ('isolated-public-test-demo-v1');
CREATE TABLE pm_demo_visitors (id INT PRIMARY KEY AUTO_INCREMENT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
ALTER TABLE venues MODIFY id INT AUTO_INCREMENT;
ALTER TABLE bookings MODIFY id INT AUTO_INCREMENT;
CREATE TABLE pm_demo_budget (day DATE PRIMARY KEY, operations INT NOT NULL DEFAULT 0, visitors INT NOT NULL DEFAULT 0);
