USE quickhire;

-- Add admin_reply to platform_feedback (run if table already exists)
ALTER TABLE platform_feedback ADD COLUMN admin_reply TEXT DEFAULT NULL AFTER message;

-- Commission tracking for cash payments
CREATE TABLE IF NOT EXISTS provider_commissions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT NOT NULL,
    provider_id     INT NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    status          ENUM('owed','paid') DEFAULT 'owed',
    payment_method  VARCHAR(50) DEFAULT NULL,
    paid_at         DATETIME DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
    FOREIGN KEY (provider_id) REFERENCES service_providers(provider_id)
);
