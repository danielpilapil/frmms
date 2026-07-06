-- =========================================
-- FleetGo Promotional System Data
-- Insert promotional discounts for your system
-- =========================================

-- Clear existing promotions (if any)
DELETE FROM promotions;

-- Insert promotional data
INSERT INTO promotions (promo_name, promo_type, discount_percent, min_days, min_bookings, is_active) VALUES
-- First-Time Customer Promotions
('Welcome Discount', 'First-Time', 5.00, 1, 0, 1),
('New Customer Special', 'First-Time', 7.00, 2, 0, 1),

-- Loyalty Program Promotions  
('Bronze Loyalty', 'Loyalty', 3.00, 1, 2, 1),
('Silver Loyalty', 'Loyalty', 5.00, 1, 5, 1),
('Gold Loyalty', 'Loyalty', 8.00, 1, 10, 1),
('Platinum Loyalty', 'Loyalty', 12.00, 1, 20, 1),

-- Long-Term Rental Promotions
('Weekend Getaway', 'Long-Term', 4.00, 3, 0, 1),
('Weekly Rental', 'Long-Term', 6.00, 7, 0, 1),
('Monthly Rental', 'Long-Term', 10.00, 30, 0, 1),
('Extended Stay', 'Long-Term', 15.00, 60, 0, 1),

-- Seasonal Promotions
('Summer Special', 'Seasonal', 3.00, 2, 0, 1),
('Holiday Discount', 'Seasonal', 5.00, 3, 0, 1),
('Weekend Special', 'Seasonal', 2.00, 2, 0, 1);

-- Verify the data was inserted
SELECT * FROM promotions ORDER BY promo_type, discount_percent DESC;
