-- 1. データベースの選択/作成
CREATE DATABASE IF NOT EXISTS hackathon_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hackathon_app;

-- 2. ユーザーテーブル (ログイン/パスワードハッシュ対応)
CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. チーム目標テーブル (期間付き)
CREATE TABLE IF NOT EXISTS team_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL,
    start_date DATE,
    end_date DATE,
    goal_text TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 4. 個人タスクテーブル
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    content VARCHAR(255) NOT NULL,
    is_done TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. 毎回の振り返りテーブル (画像2枚対応)
CREATE TABLE IF NOT EXISTS reflections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    screenshot_url TEXT,
    code_url TEXT,
    comment TEXT,
    next_plan TEXT,
    is_plan_done TINYINT(1) DEFAULT 0,
    progress_score INT DEFAULT 3,
    workload_score INT DEFAULT 3,
    improvement_score INT DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. 週次振り返りテーブル (goal_id連携)
CREATE TABLE IF NOT EXISTS weekly_reflections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    goal_id INT,
    progress_score INT DEFAULT 3,
    workload_score INT DEFAULT 3,
    improvement_score INT DEFAULT 3,
    team_good TEXT,
    team_more TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. 人格メモテーブル (非公開メモ)
CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_id CHAR(36) NOT NULL,
    author_name VARCHAR(255) NOT NULL,
    target_user_name VARCHAR(255) NOT NULL,
    type ENUM('GOOD', 'MORE') NOT NULL,
    content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. 中間振り返りリンクテーブル
CREATE TABLE IF NOT EXISTS intermediate_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    review_date DATE NOT NULL,
    sheet_url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
