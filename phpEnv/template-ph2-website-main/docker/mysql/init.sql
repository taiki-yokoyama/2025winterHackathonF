-- 1. データベースの作成（存在しない場合のみ）
CREATE DATABASE IF NOT EXISTS hackathon_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. データベースを選択
USE hackathon_app;

-- 3. ユーザーテーブル
CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. チーム目標テーブル（期間：開始日・終了日付き）
CREATE TABLE IF NOT EXISTS team_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL,
    start_date DATE,
    end_date DATE,
    goal_text TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 5. 個人タスクテーブル
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    content VARCHAR(255) NOT NULL,
    is_done TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. 毎回の振り返りテーブル（画像2枚：画面・コード）
CREATE TABLE IF NOT EXISTS reflections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    screenshot_url TEXT,
    code_url TEXT,           -- コード画像のパス
    comment TEXT,
    next_plan TEXT,          -- 画面上は削除しましたがDBには残しておきます（エラー防止）
    is_plan_done TINYINT(1) DEFAULT 0,
    progress_score INT DEFAULT 3, -- 画面上は削除しましたがDBには残しておきます
    workload_score INT DEFAULT 3, -- 画面上は削除しましたがDBには残しておきます
    improvement_score INT DEFAULT 3, -- 画面上は削除しましたがDBには残しておきます
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. 週次振り返りテーブル（チーム目標IDと紐づけ）
CREATE TABLE IF NOT EXISTS weekly_reflections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    goal_id INT,             -- team_goalsのidと紐づく
    progress_score INT DEFAULT 3,
    workload_score INT DEFAULT 3,
    improvement_score INT DEFAULT 3,
    team_good TEXT,
    team_more TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. 人格メモ（Good & More）テーブル
CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_id CHAR(36) NOT NULL,
    author_name VARCHAR(255) NOT NULL,
    target_user_name VARCHAR(255) NOT NULL,
    type ENUM('GOOD', 'MORE') NOT NULL,
    content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 9. 中間振り返りリンクテーブル
CREATE TABLE IF NOT EXISTS intermediate_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    review_date DATE NOT NULL,
    sheet_url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);