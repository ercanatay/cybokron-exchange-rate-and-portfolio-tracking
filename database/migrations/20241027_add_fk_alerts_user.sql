-- Remove orphaned alerts
DELETE FROM alerts WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users);

-- Add foreign key constraint
ALTER TABLE alerts ADD CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
