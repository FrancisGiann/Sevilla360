-- Synthetic schema additions inferred from current application queries and repository migrations.
-- Applies ONLY after schema.sql in the disposable full-app harness, never on an existing database.
DELETE FROM booking_rooms;
DELETE FROM bookings;
ALTER TABLE bookings MODIFY id INT AUTO_INCREMENT, ADD guests_count INT DEFAULT 1, ADD contact_phone VARCHAR(32), ADD base_amount DECIMAL(12,2) DEFAULT 0,
 ADD policy_accepted_at DATETIME, ADD policy_version VARCHAR(80), ADD proof_inventory_conflict TINYINT DEFAULT 0,
 ADD proof_retry_opened_at DATETIME, ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE venues ADD description TEXT, ADD amenities TEXT;
ALTER TABLE booking_rooms ADD nightly_rate DECIMAL(12,2) DEFAULT 0, ADD nights INT DEFAULT 1, ADD line_total DECIMAL(12,2) DEFAULT 0;
CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT,email VARCHAR(255) UNIQUE,password_hash VARCHAR(255),role VARCHAR(20),status VARCHAR(20) DEFAULT 'active',is_verified BOOL DEFAULT 1,auth_version INT DEFAULT 1,phone_signup_required TINYINT DEFAULT 0,recovery_email VARCHAR(255),recovery_email_verified_at DATETIME,reset_token VARCHAR(255),reset_token_hash CHAR(64),reset_expires_at DATETIME,consented_at DATETIME) ENGINE=InnoDB;
CREATE TABLE customers (id INT PRIMARY KEY AUTO_INCREMENT,user_id INT UNIQUE,first_name VARCHAR(100),last_name VARCHAR(100),email VARCHAR(255),phone VARCHAR(32),special_req TEXT) ENGINE=InnoDB;
CREATE TABLE staff (id INT PRIMARY KEY AUTO_INCREMENT,user_id INT,status VARCHAR(30),full_name VARCHAR(100)) ENGINE=InnoDB;
CREATE TABLE rate_limits (id INT PRIMARY KEY AUTO_INCREMENT,ip_address VARCHAR(64),action_name VARCHAR(100),attempts INT,first_attempt DATETIME,last_attempt DATETIME,UNIQUE(ip_address,action_name)) ENGINE=InnoDB;
CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB;
CREATE TABLE media_cms (id INT PRIMARY KEY AUTO_INCREMENT,slot_assignment VARCHAR(100),file_path VARCHAR(255),media_type VARCHAR(30),title VARCHAR(100),description TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE hotel_rooms (venue_id INT PRIMARY KEY,room_type VARCHAR(80),room_number VARCHAR(30),base_capacity INT DEFAULT 2,max_capacity INT DEFAULT 4,bed_count INT DEFAULT 1,nightly_rate DECIMAL(12,2),extra_pax_rate DECIMAL(12,2) DEFAULT 0,check_in_time TIME DEFAULT '14:00:00',check_out_time TIME DEFAULT '12:00:00') ENGINE=InnoDB;
CREATE TABLE event_halls (venue_id INT PRIMARY KEY,base_rate DECIMAL(12,2),max_capacity INT,capacity_theater INT,capacity_classroom INT,capacity_banquet INT) ENGINE=InnoDB;
CREATE TABLE villas (venue_id INT PRIMARY KEY,day_rate DECIMAL(12,2),overnight_rate DECIMAL(12,2),base_capacity INT,max_capacity INT,extra_pax_rate DECIMAL(12,2),has_private_pool BOOL,day_check_in_time TIME,day_check_out_time TIME,overnight_check_in_time TIME,overnight_check_out_time TIME,day_stay_inclusions TEXT,overnight_stay_inclusions TEXT) ENGINE=InnoDB;
CREATE TABLE booking_event_details (booking_id INT PRIMARY KEY,event_style VARCHAR(100),event_type VARCHAR(100),custom_notes TEXT,admin_notes TEXT) ENGINE=InnoDB;
CREATE TABLE booking_villa_details (booking_id INT PRIMARY KEY,stay_type VARCHAR(50)) ENGINE=InnoDB;
CREATE TABLE booking_line_items (id INT PRIMARY KEY AUTO_INCREMENT,booking_id INT,item_name VARCHAR(255),amount DECIMAL(12,2)) ENGINE=InnoDB;
CREATE TABLE cancellations (id INT PRIMARY KEY AUTO_INCREMENT,booking_id INT,status VARCHAR(30),reason TEXT) ENGINE=InnoDB;
CREATE TABLE reschedule_requests (id INT PRIMARY KEY AUTO_INCREMENT,booking_id INT,status VARCHAR(30),new_start_date DATE,new_end_date DATE,reason TEXT) ENGINE=InnoDB;
CREATE TABLE manual_payment_submissions (id INT PRIMARY KEY AUTO_INCREMENT,booking_id INT,status VARCHAR(30),submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE user_notifications (id INT PRIMARY KEY AUTO_INCREMENT,user_id INT,title VARCHAR(255),message TEXT,is_read BOOL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE venue_reviews (id INT PRIMARY KEY AUTO_INCREMENT,customer_id INT,booking_id INT,rating INT,review_text TEXT,moderation_status VARCHAR(30)) ENGINE=InnoDB;
CREATE TABLE payments (id INT PRIMARY KEY AUTO_INCREMENT,booking_id INT,payment_method VARCHAR(40),amount DECIMAL(12,2),transaction_id VARCHAR(120) UNIQUE,payment_date DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE audit_logs (id INT PRIMARY KEY AUTO_INCREMENT,user_id INT,module VARCHAR(80),action TEXT,ip_address VARCHAR(64),event_type VARCHAR(80),entity_type VARCHAR(80),entity_id VARCHAR(80),details_json JSON) ENGINE=InnoDB;
INSERT INTO hotel_rooms (venue_id,room_type,room_number,nightly_rate) VALUES (1,'Standard Room','101',3000),(4,'Standard Room','102',3000);
INSERT INTO event_halls VALUES (3,25000,100,100,80,60);
INSERT INTO villas VALUES (2,8000,12000,5,10,500,1,'08:00:00','17:00:00','14:00:00','12:00:00','Synthetic day stay','Synthetic overnight stay');
INSERT INTO system_settings VALUES ('manual_payment_deadline_hours','24');
ALTER TABLE media_cms ADD is_primary BOOL DEFAULT 0;
ALTER TABLE payments ADD status VARCHAR(20) DEFAULT 'Success';
ALTER TABLE system_settings ADD description TEXT;
DROP TABLE manual_payment_submissions;
CREATE TABLE addons (id INT PRIMARY KEY AUTO_INCREMENT,name VARCHAR(100),price DECIMAL(12,2));
CREATE TABLE booking_addons (id INT PRIMARY KEY AUTO_INCREMENT,booking_id INT,addon_id INT,quantity INT,total_price DECIMAL(12,2));
ALTER TABLE cancellations ADD admin_reply TEXT, ADD refund_amount DECIMAL(12,2), ADD fee_deducted DECIMAL(12,2), ADD fee_percent DECIMAL(5,2), ADD refund_destination_method VARCHAR(40), ADD refund_destination_account_name VARCHAR(120), ADD refund_destination_account_identifier VARCHAR(100), ADD refund_destination_bank_name VARCHAR(100);

ALTER TABLE cancellations ADD refund_transaction_id VARCHAR(160), ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE reschedule_requests ADD admin_reply TEXT;
CREATE TABLE cancellation_history (id INT PRIMARY KEY AUTO_INCREMENT,booking_id INT,cancellation_id INT,action VARCHAR(40),reason TEXT,refund_amount DECIMAL(12,2),fee_deducted DECIMAL(12,2),fee_percent DECIMAL(5,2),admin_reply TEXT,actor_user_id INT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);

ALTER TABLE bookings ADD addons_amount DECIMAL(12,2) DEFAULT 0;
ALTER TABLE seminars ADD hall_venue_id INT, ADD hall_start_date DATE, ADD hall_end_date DATE, ADD agreed_price DECIMAL(12,2), ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ADD name VARCHAR(100);
CREATE TABLE seminar_payments (id INT PRIMARY KEY AUTO_INCREMENT,seminar_id INT,amount DECIMAL(12,2),status VARCHAR(30));
CREATE TABLE seminar_attendees (id INT PRIMARY KEY AUTO_INCREMENT,seminar_id INT);
