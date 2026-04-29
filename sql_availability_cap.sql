-- Add availability toggle and daily booking cap to providers
ALTER TABLE service_providers
  ADD COLUMN IF NOT EXISTS is_available     TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS daily_booking_cap INT        NOT NULL DEFAULT 0;
