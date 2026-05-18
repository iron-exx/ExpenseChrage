-- Indizes für llx_wallbox_sessions (reinstall-safe, MySQL 5.7+)

SET @db = DATABASE();
SET @tbl = 'llx_wallbox_sessions';

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name=@tbl AND index_name='idx_wallbox_sessions_rfid')=0,
    'CREATE INDEX `idx_wallbox_sessions_rfid` ON `llx_wallbox_sessions` (`rfid_hash`)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name=@tbl AND index_name='idx_wallbox_sessions_user')=0,
    'CREATE INDEX `idx_wallbox_sessions_user` ON `llx_wallbox_sessions` (`fk_user`)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name=@tbl AND index_name='idx_wallbox_sessions_start')=0,
    'CREATE INDEX `idx_wallbox_sessions_start` ON `llx_wallbox_sessions` (`start_time`)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name=@tbl AND index_name='idx_wallbox_sessions_status')=0,
    'CREATE INDEX `idx_wallbox_sessions_status` ON `llx_wallbox_sessions` (`status`)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name=@tbl AND index_name='idx_wallbox_sessions_transmitted')=0,
    'CREATE INDEX `idx_wallbox_sessions_transmitted` ON `llx_wallbox_sessions` (`transmitted_at`)',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
