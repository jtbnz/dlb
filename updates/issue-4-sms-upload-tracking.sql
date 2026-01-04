-- Issue #4: Add SMS upload tracking to callouts
-- Run this on existing databases to add the new columns
-- Note: The application will automatically run these migrations on next load,
-- but you can run this manually if needed.

-- Add SMS upload tracking columns
ALTER TABLE callouts ADD COLUMN sms_uploaded INTEGER DEFAULT 1;
ALTER TABLE callouts ADD COLUMN sms_uploaded_at DATETIME;
ALTER TABLE callouts ADD COLUMN sms_uploaded_by TEXT;

-- All existing callouts default to "uploaded" status (sms_uploaded = 1)
-- New callouts will need to be manually marked as uploaded
