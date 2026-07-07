-- Create settings table for system configuration
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'text',
    category VARCHAR(50) DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default company settings
INSERT INTO settings (setting_key, setting_value, setting_type, category) VALUES
('company_name', 'Velocity Rentals', 'text', 'company'),
('company_email', 'admin@velocityrentals.com', 'email', 'company'),
('company_phone', '+1 555-100-2000', 'text', 'company'),
('company_tax_id', 'VR-2026-9988', 'text', 'company'),
('company_address', '100 Fleet Avenue, New York, NY', 'textarea', 'company'),
('terms_conditions', '1. The renter is responsible for returning the vehicle in the same condition as released, excluding normal wear and tear.

2. Late returns are subject to hourly charges based on the rental policy.

3. Any damage, traffic violation, or legal issue incurred during the rental period is chargeable to the renter.

4. Security deposits are refundable after post-return inspection and clearance.

5. By proceeding, the renter agrees to all policies stated by Velocity Rentals.', 'textarea', 'legal'),
('privacy_policy', '', 'textarea', 'legal'),
('rental_policy', '', 'textarea', 'legal'),
('cancellation_policy', '', 'textarea', 'legal')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
