-- =======================================================
-- Sing op Kölsch – Database Schema (structure only, no data)
-- Generated 2026-06-14
-- =======================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------
-- Core: bands / artists
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_bands (
    band_id   INT AUTO_INCREMENT PRIMARY KEY,
    band_name VARCHAR(255) NOT NULL,
    UNIQUE KEY uniq_band (band_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Core: lyrics / songs
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_lyrics (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    title          VARCHAR(255) NOT NULL,
    band_id        INT  NULL,                       -- primary performer (FK compat)
    text_autor_id  INT  NULL,                       -- primary text author (FK compat)
    musik_autor_id INT  NULL,                       -- primary music author (FK compat)
    lyrics         LONGTEXT NULL,
    spotify_link   VARCHAR(512) NULL,
    cover_url      VARCHAR(512) NULL,
    track_number   INT  NULL,
    video_link     VARCHAR(512) NULL,
    link_id        VARCHAR(128) NULL,
    release_year   INT  NULL,
    album          VARCHAR(255) NULL,
    flagged        TINYINT(1) NOT NULL DEFAULT 0,
    flag_reason    TEXT NULL,
    flagged_by     INT  NULL,
    flagged_at     DATETIME NULL,
    INDEX idx_band (band_id),
    INDEX idx_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Multi-artist junction table (1:n per song per role)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_song_artists (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    lyric_id   INT NOT NULL,
    band_id    INT NOT NULL,
    role       ENUM('performer','text','music') NOT NULL,
    sort_order TINYINT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_artist (lyric_id, band_id, role),
    INDEX idx_lyric (lyric_id),
    INDEX idx_band (band_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_users (
    user_id          INT AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    email            VARCHAR(255) NOT NULL,
    password         VARCHAR(255) NOT NULL,
    role             ENUM('user','trusted','admin') NOT NULL DEFAULT 'user',
    email_verified   TINYINT(1) NOT NULL DEFAULT 0,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    points           INT NOT NULL DEFAULT 0,
    unsubscribe_token VARCHAR(64) NULL,
    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- User preferences
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_user_preferences (
    user_id            INT PRIMARY KEY,
    dark_mode          TINYINT(1) NOT NULL DEFAULT 0,
    email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    lang               VARCHAR(16) NOT NULL DEFAULT 'de',
    email_limit        INT NOT NULL DEFAULT 1,
    email_unit         VARCHAR(10) NOT NULL DEFAULT 'week',
    email_count        INT NOT NULL DEFAULT 0,
    email_next_reset   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- User badges (gamification)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_user_badges (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    badge_key  VARCHAR(64) NOT NULL,
    awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_badge (user_id, badge_key),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Change requests / proposals
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_change_requests (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    lyrics_id        INT NOT NULL,
    user_id          INT NOT NULL,
    proposed_lyrics  LONGTEXT NULL,
    proposed_changes LONGTEXT NULL,          -- JSON blob of changed fields
    status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at      TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_lyrics (lyrics_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Invite codes
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_invite_codes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(32) NOT NULL,
    created_by  INT  NULL,
    redeemed_by INT  NULL,
    redeemed_at DATETIME NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    label       VARCHAR(128) NULL,
    UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Site-wide settings (key-value store)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_settings (
    setting_key   VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Favorites
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_favorites (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    song_id    INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fav (user_id, song_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Setlists
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_setlists (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT NULL,
    share_token VARCHAR(64) NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS singopkoelsch_setlist_songs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    setlist_id  INT NOT NULL,
    song_id     INT NOT NULL,
    position    INT NOT NULL DEFAULT 0,
    added_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq (setlist_id, song_id),
    INDEX idx_setlist (setlist_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Page / song views
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_views (
    id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    song_id   INT NOT NULL,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_song (song_id),
    INDEX idx_time (viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Band follows
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_band_follows (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    band_id    INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_follow (user_id, band_id),
    INDEX idx_user (user_id),
    INDEX idx_band (band_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Band sponsors
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_band_sponsors (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    band_id INT NOT NULL,
    name    VARCHAR(100) NOT NULL,
    url     VARCHAR(255) NULL,
    tier    VARCHAR(32) DEFAULT 'standard',
    INDEX idx_band (band_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Song events (Setlists / Veranstaltungen)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_song_events (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    song_id  INT NOT NULL,
    event_id INT NOT NULL,
    INDEX idx_song (song_id),
    INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Cover proposals
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_cover_proposals (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    song_id    INT NOT NULL,
    cover_url  VARCHAR(512) NOT NULL,
    status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_song (song_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Photos
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_photos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    song_id    INT NULL,
    band_id    INT NULL,
    url        VARCHAR(512) NOT NULL,
    caption    TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_song (song_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Web Push subscriptions (PWA)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_push_subs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    endpoint   TEXT NOT NULL,
    p256dh     TEXT NOT NULL,
    auth       VARCHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- iOS / API device tokens
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_device_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(255) NOT NULL,
    platform   VARCHAR(32) NOT NULL DEFAULT 'ios',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_token (token),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Email verification tokens
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS singopkoelsch_verification_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    UNIQUE KEY uniq_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
