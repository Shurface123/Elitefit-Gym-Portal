-- Add icon column if it doesn't exist
ALTER TABLE `trainer_notifications` 
ADD COLUMN IF NOT EXISTS `icon` VARCHAR(50) DEFAULT 'bell' AFTER `message`,
ADD COLUMN IF NOT EXISTS `link` VARCHAR(255) AFTER `is_read`;

-- Remove the title and type columns if they exist
SET @dbname = DATABASE();
SET @tablename = 'trainer_notifications';
SET @columnname = 'title';
SET @prepstmt = (SELECT IF(
  EXISTS(
    SELECT * FROM INFORMATION_SCHEMA.COLUMNS
    WHERE 
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ),
  "ALTER TABLE `trainer_notifications` DROP COLUMN `title`",
  'SELECT 1'
));
PREPARE alterIfExists FROM @prepstmt;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

SET @columnname = 'type';
SET @prepstmt = (SELECT IF(
  EXISTS(
    SELECT * FROM INFORMATION_SCHEMA.COLUMNS
    WHERE 
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ),
  "ALTER TABLE `trainer_notifications` DROP COLUMN `type`",
  'SELECT 1'
));
PREPARE alterIfExists FROM @prepstmt;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Add index on is_read if it doesn't exist
SET @dbname = DATABASE();
SET @tablename = 'trainer_notifications';
SET @indexname = 'is_read';
SET @prepstmt = (SELECT IF(
  NOT EXISTS(
    SELECT * FROM INFORMATION_SCHEMA.STATISTICS
    WHERE 
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (INDEX_NAME = @indexname)
  ),
  "ALTER TABLE `trainer_notifications` ADD INDEX (`is_read`)",
  'SELECT 1'
));
PREPARE createIndexIfNotExists FROM @prepstmt;
EXECUTE createIndexIfNotExists;
DEALLOCATE PREPARE createIndexIfNotExists;
