ALTER TABLE comment_users
  ADD COLUMN token_plain VARCHAR(128) NULL AFTER token_hash;
