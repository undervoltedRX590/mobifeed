-- MobiFeed upgrade for databases created with the FIRST schema.sql.
-- Adds: shared posts in DMs, and likes on comments.
-- Run it ONCE (phpMyAdmin -> select the "mobifeed" database -> SQL tab -> paste -> Go).
-- Fresh installs using the new schema.sql do NOT need this file.

USE mobifeed;

ALTER TABLE messages
  ADD COLUMN shared_post_id INT NULL DEFAULT NULL AFTER message_text,
  ADD COLUMN is_share TINYINT(1) NOT NULL DEFAULT 0 AFTER shared_post_id,
  ADD KEY idx_messages_post (shared_post_id),
  ADD CONSTRAINT fk_messages_post FOREIGN KEY (shared_post_id) REFERENCES posts(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS comment_likes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  comment_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comment_likes (comment_id, user_id),
  KEY idx_comment_likes_user (user_id),
  CONSTRAINT fk_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
