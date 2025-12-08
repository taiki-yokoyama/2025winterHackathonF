<?php
session_start();

// CSRF トークン生成
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// ==========================================
// 1. 設定 & DB接続
// ==========================================
$host = 'db'; 
$dbname = 'hackathon_app';
$db_user = 'root';
$db_pass = 'root';
$upload_dir = __DIR__ . '/uploads/';

if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

function get_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}
function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

// アップロード検証 (画像限定, サイズ上限)
function is_allowed_image($tmp_path, $orig_name) {
    $maxBytes = 2 * 1024 * 1024; // 2MB
    if (!is_uploaded_file($tmp_path)) return false;
    if (filesize($tmp_path) > $maxBytes) return false;
    $info = @getimagesize($tmp_path);
    if ($info === false) return false;
    $mime = $info['mime'];
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($mime, $allowed, true)) return false;
    // 拡張子チェック
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowedExt = ['jpg','jpeg','png','gif','webp'];
    return in_array($ext, $allowedExt, true);
}

function safe_filename($prefix, $orig_name) {
    $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
    $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $name = $prefix . '_' . bin2hex(random_bytes(8));
    if ($ext) $name .= '.' . $ext;
    return $name;
}
// ==========================================
// 2. ロジック処理
// ==========================================

// ログアウト
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: index.php");
    exit;
}

// ログイン・登録
$error_message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $team = trim($_POST['team_name']);
    $name = trim($_POST['name']);
    $pass = $_POST['password'];

    // CSRF 検証
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "不正なアクセスです";
    }

    if (empty($error_message) && $team && $name && $pass) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE team_name = ? AND name = ?");
        $stmt->execute([$team, $name]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $uuid = get_uuid();
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (id, team_name, name, password_hash) VALUES (?, ?, ?, ?)");
            $stmt->execute([$uuid, $team, $name, $hash]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $uuid;
            $_SESSION['name'] = $name;
            $_SESSION['team_name'] = $team;
            header("Location: index.php"); exit;
        } else {
            if (password_verify($pass, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['team_name'] = $user['team_name'];
                header("Location: index.php"); exit;
            } else {
                $error_message = "パスワードが違います";
            }
        }
    }
}

$is_logged_in = isset($_SESSION['user_id']);
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_name = $_SESSION['name'] ?? null;
$current_team = $_SESSION['team_name'] ?? null;

// ★データ処理
if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 検証 (ログイン後フォームすべて)
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "不正なアクセスです";
    }
    
    if (empty($error_message)) {
    // チーム目標更新
    if (isset($_POST['action']) && $_POST['action'] === 'update_team_goal') {
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        
        $stmt = $pdo->prepare("SELECT id, start_date FROM team_goals WHERE team_name = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$current_team]);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($latest && $latest['start_date'] === $start) {
            $stmt = $pdo->prepare("UPDATE team_goals SET goal_text = ?, end_date = ? WHERE id = ?");
            $stmt->execute([$_POST['goal_text'], $end, $latest['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO team_goals (team_name, start_date, end_date, goal_text) VALUES (?, ?, ?, ?)");
            $stmt->execute([$current_team, $start, $end, $_POST['goal_text']]);
        }
        header("Location: index.php"); exit;
    }

    // タスク追加
    if (isset($_POST['action']) && $_POST['action'] === 'add_task') {
        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, content) VALUES (?, ?)");
        $stmt->execute([$current_user_id, $_POST['content']]);
        header("Location: index.php"); exit;
    }
    
    // タスク完了切り替え
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_task') {
        $stmt = $pdo->prepare("UPDATE tasks SET is_done = NOT is_done WHERE id = ? AND user_id = ?");
        $stmt->execute([$_POST['task_id'], $current_user_id]);
        header("Location: index.php"); exit;
    }

    // 進捗の振り返り投稿
    if (isset($_POST['action']) && $_POST['action'] === 'reflection') {
        $screen_path = '';
        if (isset($_FILES['screen_file']) && $_FILES['screen_file']['error'] === UPLOAD_ERR_OK) {
            if (is_allowed_image($_FILES['screen_file']['tmp_name'], $_FILES['screen_file']['name'])) {
                $filename = safe_filename('screen', $_FILES['screen_file']['name']);
                if (move_uploaded_file($_FILES['screen_file']['tmp_name'], $upload_dir . $filename)) { $screen_path = 'uploads/' . $filename; }
            } else {
                $error_message = 'スクリーンショットは画像 (最大2MB) を指定してください。';
            }
        }
        $code_path = '';
        if (isset($_FILES['code_file']) && $_FILES['code_file']['error'] === UPLOAD_ERR_OK) {
            if (is_allowed_image($_FILES['code_file']['tmp_name'], $_FILES['code_file']['name'])) {
                $filename = safe_filename('code', $_FILES['code_file']['name']);
                if (move_uploaded_file($_FILES['code_file']['tmp_name'], $upload_dir . $filename)) { $code_path = 'uploads/' . $filename; }
            } else {
                $error_message = 'コード画像は画像 (最大2MB) を指定してください。';
            }
        }
        $stmt = $pdo->prepare("INSERT INTO reflections (user_id, screenshot_url, code_url, comment, next_plan) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$current_user_id, $screen_path, $code_path, $_POST['comment'], ""]);
        header("Location: index.php?tab=daily"); exit;
    }

    // 週次振り返り投稿
    if (isset($_POST['action']) && $_POST['action'] === 'weekly_reflection') {
        $stmt = $pdo->prepare("SELECT id FROM weekly_reflections WHERE user_id = ? AND goal_id = ?");
        $stmt->execute([$current_user_id, $_POST['goal_id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE weekly_reflections SET progress_score=?, workload_score=?, improvement_score=?, team_good=?, team_more=? WHERE id=?");
            $stmt->execute([$_POST['progress_score'], $_POST['workload_score'], $_POST['improvement_score'], $_POST['team_good'], $_POST['team_more'], $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO weekly_reflections (user_id, goal_id, progress_score, workload_score, improvement_score, team_good, team_more) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$current_user_id, $_POST['goal_id'], $_POST['progress_score'], $_POST['workload_score'], $_POST['improvement_score'], $_POST['team_good'], $_POST['team_more']]);
        }
        header("Location: index.php?tab=weekly&sub=share"); exit;
    }

    // 人格メモ投稿
    if (isset($_POST['action']) && $_POST['action'] === 'note') {
        $stmt = $pdo->prepare("INSERT INTO notes (author_id, author_name, target_user_name, type, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$current_user_id, $current_user_name, $_POST['target_user_name'], $_POST['type'], $_POST['content']]);
        header("Location: index.php?tab=daily&sub=personality"); exit;
    }

    // 中間振り返りリンク
    if (isset($_POST['action']) && $_POST['action'] === 'add_intermediate') {
        $stmt = $pdo->prepare("INSERT INTO intermediate_reviews (user_id, review_date, sheet_url) VALUES (?, ?, ?)");
        $stmt->execute([$current_user_id, $_POST['review_date'], $_POST['sheet_url']]);
        header("Location: index.php?tab=notes"); exit;
    }
    }
}

// データ取得
$team_goal_data = null;
$team_goal_text = "目標未設定";
$current_period = "期間未設定";
$tasks_by_user = [];
$team_members = [];
$reflections = [];
$notes = [];
$weekly_data_list = [];
$team_avg_percent = 0;
$min_progress = 5;
$intermediate_links = [];
$archive_data = [];

if ($is_logged_in) {
    // メンバー
    $stmt = $pdo->prepare("SELECT * FROM users WHERE team_name = ?");
    $stmt->execute([$current_team]);
    $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ★現在のチーム目標
    $stmt = $pdo->prepare("SELECT * FROM team_goals WHERE team_name = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$current_team]);
    $team_goal_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($team_goal_data) {
        $team_goal_text = $team_goal_data['goal_text'];
        if ($team_goal_data['start_date'] && $team_goal_data['end_date']) {
            $current_period = date('n/j', strtotime($team_goal_data['start_date'])) . ' 〜 ' . date('n/j', strtotime($team_goal_data['end_date']));
        }
    }

    // タスク
    foreach ($team_members as $m) {
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? ORDER BY created_at ASC");
        $stmt->execute([$m['id']]);
        $tasks_by_user[$m['name']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 進捗スレッド
    $stmt = $pdo->prepare("SELECT r.*, u.name FROM reflections r JOIN users u ON r.user_id = u.id WHERE u.team_name = ? ORDER BY r.created_at DESC LIMIT 50");
    $stmt->execute([$current_team]);
    $reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 人格メモ
    $stmt = $pdo->prepare("SELECT * FROM notes WHERE author_id = ? ORDER BY created_at DESC");
    $stmt->execute([$current_user_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 週次集計
    if ($team_goal_data) {
        $total_progress = 0;
        $count_member = 0;
        foreach ($team_members as $m) {
            $stmt = $pdo->prepare("SELECT * FROM weekly_reflections WHERE user_id = ? AND goal_id = ?");
            $stmt->execute([$m['id'], $team_goal_data['id']]);
            $w_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($w_data) {
                $p_score = $w_data['progress_score'];
                $weekly_data_list[] = [
                    'name' => $m['name'],
                    'progress' => $p_score,
                    'workload' => $w_data['workload_score'],
                    'improvement' => $w_data['improvement_score'],
                    'good' => $w_data['team_good'],
                    'more' => $w_data['team_more']
                ];
                $total_progress += $p_score;
                $count_member++;
                if ($p_score < $min_progress) { $min_progress = $p_score; }
            } else {
                $weekly_data_list[] = ['name' => $m['name'], 'progress' => '-', 'workload' => '-', 'improvement' => '-', 'good' => '', 'more' => ''];
            }
        }
        if ($count_member > 0) {
            $avg = $total_progress / $count_member;
            $team_avg_percent = round(($avg / 5) * 100);
        }
    }

    // 中間振り返り
    $stmt = $pdo->prepare("SELECT * FROM intermediate_reviews WHERE user_id = ? ORDER BY review_date DESC");
    $stmt->execute([$current_user_id]);
    $intermediate_links = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // アーカイブ
    $stmt = $pdo->prepare("
        SELECT tg.*, wr.progress_score, wr.workload_score, wr.improvement_score, wr.team_good, wr.team_more 
        FROM team_goals tg 
        LEFT JOIN weekly_reflections wr ON tg.id = wr.goal_id AND wr.user_id = ?
        WHERE tg.team_name = ? 
        ORDER BY tg.start_date DESC
    ");
    $stmt->execute([$current_user_id, $current_team]);
    $archive_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 初期タブ判定
$initial_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$initial_sub = isset($_GET['sub']) ? $_GET['sub'] : 'progress'; 
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;500;700&display=swap" rel="stylesheet">
    <style>
        /* =========================================================
           カラーパレット定義
           薄い茶色：#E3D3BF
           枠の中間茶色：#D1C1AC
           一番濃い茶色：#9B7B5B
           ミントグリーン：#AFEEEE
        ========================================================= */
        :root {
            --color-bg-light: #E3D3BF;
            --color-bg-medium: #D1C1AC;
            --color-bg-dark: #9B7B5B;
            --color-accent: #AFEEEE;
            --color-text-main: #5a4a42; /* 濃い茶色ベースの文字色 */
        }

        body { 
            font-family: 'Noto Sans JP', sans-serif; 
            background-color: #ffffff; /* ベースは白 */
            color: #333;
            overflow-x: hidden; 
            padding-top: 60px; 
        }
        
        /* 固定ヘッダー */
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 60px;
            background-color: #ffffff;
            border-bottom: 2px solid var(--color-bg-medium); /* 枠の中間茶色 */
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .brand-logo {
            /* font-weight: 700;
            font-size: 1.5rem;
            color: var(--color-bg-dark); 
            text-decoration: none; */
            display: flex;
            align-items: center;
            /* gap: 0.5rem; */
        }

        /* サイドバー */
        .sidebar {
            width: 120px;
            height: calc(100vh - 60px);
            background-color: var(--color-bg-dark); /* 一番濃い茶色 */
            position: fixed;
            left: 0;
            top: 60px;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 2rem;
            padding-bottom: 2rem;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-thumb { background-color: rgba(255,255,255,0.2); border-radius: 3px; }

        .nav-btn {
            width: 70px;
            height: 70px;
            background-color: var(--color-accent); /* ミントグリーン */
            border-radius: 50%;
            margin-bottom: 0.5rem;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: all 0.2s;
            flex-shrink: 0;
            color: var(--color-bg-dark); /* アイコン色は濃い茶色 */
        }
        
        /* ★修正: アクティブ・ホバー時に色を反転 */
        .nav-btn:hover, .nav-btn.active, .nav-btn:active { 
            transform: scale(1.05); 
            box-shadow: 0 0 10px rgba(175, 238, 238, 0.6); 
            background-color: var(--color-bg-dark); /* 背景を濃い茶色に */
            color: var(--color-accent); /* アイコンをミントに */
            border: 2px solid var(--color-accent); /* 視認性向上のため枠線追加 */
        }
        .nav-btn i { font-size: 1.8rem; }
        
        /* サイドバーの文字色を白に変更 */
        .nav-label { 
            font-size: 0.7rem; 
            text-align: center; 
            margin-bottom: 2rem; 
            font-weight: bold; 
            color: #ffffff; 
        }
        .nav-arrow { 
            font-size: 1.5rem; 
            color: #ffffff; 
            margin-bottom: 1rem; 
        }

        /* メインコンテンツ */
        .main-content { margin-left: 120px; padding: 2rem 4rem; min-height: calc(100vh - 60px); background-color: #ffffff; }
        
        /* デザイン要素 */
        .section-header { text-align: center; margin-bottom: 2rem; }
        .section-header h2 { 
            font-size: 1.5rem; 
            font-weight: bold; 
            color: var(--color-bg-dark); /* 濃い茶色 */
        }
        
        /* 背景ボックス (gray-box, form-area, timeline-area) */
        .gray-box, .form-area, .timeline-area { 
            background-color: var(--color-bg-light); /* 薄い茶色 */
            padding: 2rem; 
            border-radius: 4px; 
            margin-bottom: 2rem;
        }
        .gray-box { text-align: center; margin-bottom: 3rem; }
        .form-area { height: 100%; }
        .timeline-area { height: 700px; overflow-y: auto; }
        
        /* タブボタン */
        .sub-tab-container { display: flex; gap: 20px; margin-bottom: 2rem; align-items: center; }
        .sub-tab-btn {
            background-color: var(--color-bg-light); /* 薄い茶色 */
            color: var(--color-text-main);
            padding: 15px 20px;
            font-size: 1.2rem;
            border: none;
            cursor: pointer;
            flex: 1; 
            text-align: center;
            font-weight: 700;
            transition: 0.3s;
        }
        
        /* ★修正: タブアクティブ時に色を反転 (濃い茶色背景 + ミント文字) */
        .sub-tab-btn.active, .sub-tab-btn:hover { 
            background-color: var(--color-bg-dark); 
            color: var(--color-accent); 
        }
        
        .next-plan-btn {
            background-color: var(--color-bg-medium);
            color: #fff;
            padding: 15px 20px;
            font-size: 1rem;
            border: none;
            text-decoration: none;
            text-align: center;
            font-weight: 500;
            display: block;
            width: 200px;
            transition: 0.3s;
        }
        /* ★修正: ホバー時に色を反転 */
        .next-plan-btn:hover, .next-plan-btn:active { 
            background-color: var(--color-bg-dark); 
            color: var(--color-accent); 
        }

        .timeline-card { 
            background: white; 
            padding: 1.5rem; 
            margin-bottom: 1rem; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
            border-left: 4px solid var(--color-bg-medium); /* 左端にアクセント */
        }

        .note-card-good { background-color: #fff9e6; border-left: 6px solid #ffc107; color: #856404; }
        .note-card-more { background-color: #e6f2ff; border-left: 6px solid #17a2b8; color: #0c5460; }
        .note-emoji { font-size: 1.5rem; margin-right: 0.5rem; }
        .table-warning-row { background-color: #ffe6e6 !important; color: #dc3545; font-weight: bold; }

        .login-wrapper { display: flex; justify-content: center; align-items: center; height: 100vh; margin-left: 0; background-color: #fcfcfc; padding-top: 0; }
        .arrow-icon { font-size: 2rem; color: var(--color-bg-dark); font-weight: bold; }

        /* Bootstrap上書き (ボタン類を茶色ベースに) */
        .btn-dark {
            background-color: var(--color-bg-dark) !important;
            border-color: var(--color-bg-dark) !important;
            color: #fff !important;
        }
        /* ★修正: アクティブ・ホバー時に色を反転 (ミント背景 + 濃い茶色文字) */
        .btn-dark:hover, .btn-dark:active, .btn-dark:focus {
            background-color: var(--color-accent) !important; /* ミント */
            border-color: var(--color-bg-dark) !important;
            color: var(--color-bg-dark) !important; /* 濃い茶色 */
        }

        .btn-outline-secondary {
            color: var(--color-bg-dark) !important;
            border-color: var(--color-bg-dark) !important;
        }
        .btn-outline-secondary:hover, .btn-outline-secondary:active {
            background-color: var(--color-bg-dark) !important;
            color: var(--color-accent) !important; /* ミント */
        }

        /* フォームのラベル等を茶色系に */
        .form-label, .fw-bold { color: var(--color-bg-dark); }
        .text-secondary { color: #887060 !important; }

        /* 入力フォームの微調整 */
        .form-control, .form-select {
            border: 1px solid var(--color-bg-medium);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--color-bg-dark);
            box-shadow: 0 0 0 0.25rem rgba(155, 123, 91, 0.25);
        }

    </style>
</head>
<body>

<?php if (!$is_logged_in): ?>
    <div class="container login-wrapper">
        <div class="card p-5 shadow-lg" style="width: 400px; border-radius: 20px; background-color: #fff; border-top: 10px solid var(--color-bg-dark);">
            <h3 class="text-center fw-bold mb-4" style="color:var(--color-bg-dark)">Sync ログイン</h3>
            <?php if ($error_message): ?><div class="alert alert-danger py-2"><?= h($error_message) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="mb-3"><label class="form-label small fw-bold">チーム名</label><input type="text" name="team_name" class="form-control" required placeholder="posse_team1"></div>
                <div class="mb-3"><label class="form-label small fw-bold">名前</label><input type="text" name="name" class="form-control" required placeholder="Taro"></div>
                <div class="mb-4"><label class="form-label small fw-bold">パスワード</label><input type="password" name="password" class="form-control" required placeholder="半角英数字"></div>
                <button type="submit" class="btn btn-dark w-100 py-2">はじめる</button>
            </form>
        </div>
    </div>

<?php else: ?>
    <header class="top-header">
        <a href="index.php" class="brand-logo">
            <img src="./img/logo-winter.png" alt="Sync ロゴ" class="img-fluid" style="max-height: 80px;">
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="small fw-bold" style="color: #666;">チーム: <?= h($current_team) ?> / <?= h($current_user_name) ?></span>
            <a href="?logout=true" class="btn btn-sm btn-outline-danger" onclick="return confirm('ログアウトしますか？')">
                <i class="bi bi-box-arrow-right"></i> ログアウト
            </a>
        </div>
    </header>

    <div class="sidebar">
        <button class="nav-btn <?= $initial_tab === 'dashboard' ? 'active' : '' ?>" onclick="switchTab(this,'dashboard')"><i class="bi bi-house-door-fill"></i></button>
        <div class="nav-label">今週のPLAN</div>
        <div class="nav-arrow"><i class="bi bi-chevron-down"></i></div>

        <button class="nav-btn <?= $initial_tab === 'daily' ? 'active' : '' ?>" onclick="switchTab(this,'daily')"><i class="bi bi-pencil-square"></i></button>
        <div class="nav-label">毎回の<br>振り返り</div>
        <div class="nav-arrow"><i class="bi bi-chevron-down"></i></div>

        <button class="nav-btn <?= $initial_tab === 'weekly' ? 'active' : '' ?>" onclick="switchTab(this,'weekly')"><i class="bi bi-calendar-check"></i></button>
        <div class="nav-label">1週間の<br>振り返り</div>
        <div class="nav-arrow"><i class="bi bi-chevron-down"></i></div>

        <button class="nav-btn <?= $initial_tab === 'notes' ? 'active' : '' ?>" onclick="switchTab(this,'notes')"><i class="bi bi-chat-heart-fill"></i></button>
        <div class="nav-label">中間<br>Good&More</div>
    </div>

    <div class="main-content">
        
        <div id="tab-dashboard" class="content-section <?= $initial_tab !== 'dashboard' ? 'd-none' : '' ?>">
            <div class="section-header"><h2>今週1週間のPLANはこちら</h2></div>
            <div class="gray-box position-relative group">
                <div class="badge bg-secondary mb-2" style="font-size: 0.9rem; background-color: var(--color-bg-dark) !important;">現在の期間: <?= h($current_period) ?></div>
                <h5>チームとしての1週間の目標</h5>
                <h3 class="fw-bold mt-2"><?= nl2br(h($team_goal_text)) ?></h3>
                <button class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-2" onclick="document.getElementById('edit-goal-form').classList.toggle('d-none')">編集/次週設定</button>
                
                <form method="post" id="edit-goal-form" class="d-none mt-3 text-start bg-white p-3 rounded shadow-sm">
                    <input type="hidden" name="action" value="update_team_goal">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="small fw-bold">開始日</label><input type="date" name="start_date" class="form-control form-control-sm" required value="<?= $team_goal_data['start_date'] ?? date('Y-m-d') ?>"></div>
                        <div class="col-6"><label class="small fw-bold">終了日</label><input type="date" name="end_date" class="form-control form-control-sm" required value="<?= $team_goal_data['end_date'] ?? date('Y-m-d', strtotime('+6 days')) ?>"></div>
                    </div>
                    <label class="small fw-bold">目標テキスト</label>
                    <input type="text" name="goal_text" class="form-control mb-2" value="<?= h($team_goal_text) ?>" required>
                    <button type="submit" class="btn btn-dark btn-sm w-100">保存</button>
                </form>
            </div>
            <div class="gray-box text-start p-4">
                <h4 class="text-center mb-4 text-secondary">個人ごとに分けられたタスク一覧</h4>
                <div class="row">
                    <?php foreach ($team_members as $member): ?>
                        <div class="col-md-6 mb-3">
                            <div class="bg-white p-3 rounded">
                                <h6 class="fw-bold border-bottom pb-2 mb-2" style="border-color:var(--color-bg-medium)!important"><i class="bi bi-person-circle"></i> <?= h($member['name']) ?></h6>
                                <ul class="list-unstyled mb-2">
                                    <?php if(isset($tasks_by_user[$member['name']])): foreach($tasks_by_user[$member['name']] as $task): ?>
                                        <li class="d-flex align-items-center mb-1">
                                            <?php if($member['name'] === $current_user_name): ?>
                                                <form method="post" class="me-2">
                                                    <input type="hidden" name="action" value="toggle_task">
                                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                    <button type="submit" class="btn btn-sm p-0 border-0"><i class="bi <?= $task['is_done'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?>"></i></button>
                                                </form>
                                            <?php else: ?><i class="bi <?= $task['is_done'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?> me-2"></i><?php endif; ?>
                                            <span class="<?= $task['is_done'] ? 'text-decoration-line-through text-muted' : '' ?>"><?= h($task['content']) ?></span>
                                        </li>
                                    <?php endforeach; endif; ?>
                                </ul>
                                <?php if($member['name'] === $current_user_name): ?>
                                    <form method="post" class="d-flex gap-2">
                                        <input type="hidden" name="action" value="add_task">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="text" name="content" class="form-control form-control-sm" required placeholder="タスク追加">
                                        <button type="submit" class="btn btn-sm btn-dark">+</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="tab-daily" class="content-section <?= $initial_tab !== 'daily' ? 'd-none' : '' ?>">
            <div class="sub-tab-container">
                <button id="btn-progress" class="sub-tab-btn <?= $initial_sub === 'progress' ? 'active' : '' ?>" onclick="switchSubTab(this,'progress')">進捗の振り返り</button>
                <button id="btn-personality" class="sub-tab-btn <?= $initial_sub === 'personality' ? 'active' : '' ?>" onclick="switchSubTab(this,'personality')">人格の振り返り</button>
            </div>
            <div id="daily-progress" class="h-100 <?= $initial_sub !== 'progress' ? 'd-none' : '' ?>">
                <div class="row h-100">
                    <div class="col-md-5">
                        <div class="form-area">
                            <h5 class="fw-bold mb-3">投稿フォーム</h5>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="reflection">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <div class="mb-3 p-3 bg-white rounded">
                                    <label class="small fw-bold d-block mb-2">1. コードの写真 (Code)</label>
                                    <input type="file" name="code_file" class="form-control form-control-sm mb-3" accept="image/*">
                                    <label class="small fw-bold d-block mb-2">2. 実際の画面 (Screen)</label>
                                    <input type="file" name="screen_file" class="form-control form-control-sm" accept="image/*">
                                </div>
                                <div class="mb-3"><label class="small fw-bold">コメント</label><textarea name="comment" class="form-control" rows="6" placeholder="実装した内容や、詰まっているポイントを共有しよう" required></textarea></div>
                                <button type="submit" class="btn btn-dark w-100 fw-bold py-2 mt-2">保存する</button>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="timeline-area">
                            <h5 class="fw-bold mb-3">進捗共有スレッド一覧</h5>
                            <?php if (empty($reflections)): ?><p class="text-muted text-center mt-5">まだ投稿がありません。</p><?php else: foreach ($reflections as $ref): ?>
                                <div class="timeline-card">
                                    <div class="d-flex justify-content-between align-items-center mb-2"><div class="d-flex align-items-center gap-2"><div class="text-white rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-weight:bold; background-color:var(--color-bg-dark);"><?= substr(h($ref['name']), 0, 1) ?></div><span class="fw-bold"><?= h($ref['name']) ?></span></div><small class="text-muted"><?= date('m/d H:i', strtotime($ref['created_at'])) ?></small></div>
                                    <p class="mb-3" style="font-size: 0.95rem; white-space: pre-wrap;"><?= h($ref['comment']) ?></p>
                                    <div class="row g-2">
                                        <?php if(!empty($ref['code_url'])): ?><div class="col-6"><div class="small fw-bold text-muted mb-1">Code</div><img src="<?= h($ref['code_url']) ?>" class="img-fluid rounded border w-100" style="height: 150px; object-fit: cover; cursor: pointer;" onclick="window.open(this.src)"></div><?php endif; ?>
                                        <?php if(!empty($ref['screenshot_url'])): ?><div class="col-6"><div class="small fw-bold text-muted mb-1">Screen</div><img src="<?= h($ref['screenshot_url']) ?>" class="img-fluid rounded border w-100" style="height: 150px; object-fit: cover; cursor: pointer;" onclick="window.open(this.src)"></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div id="daily-personality" class="h-100 <?= $initial_sub !== 'personality' ? 'd-none' : '' ?>">
                <div class="row h-100">
                    <div class="col-md-5">
                        <div class="form-area">
                            <h5 class="fw-bold mb-4">人格振り返りフォーム</h5>
                            <form method="post">
                                <input type="hidden" name="action" value="note">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <div class="mb-3"><label class="small fw-bold d-block mb-1">対象者</label><select name="target_user_name" class="form-select bg-white"><option value="">プルダウン選択</option><option value="<?= h($current_user_name) ?>">自分 (Myself)</option><?php foreach ($team_members as $m): if($m['name'] !== $current_user_name): ?><option value="<?= h($m['name']) ?>"><?= h($m['name']) ?>さん</option><?php endif; endforeach; ?></select></div>
                                <div class="mb-3"><label class="small fw-bold d-block mb-1">Good / More</label><select name="type" class="form-select bg-white"><option value="">プルダウン選択</option><option value="GOOD">Good (良い点)</option><option value="MORE">More (改善点)</option></select></div>
                                <div class="mb-4"><label class="small fw-bold d-block mb-1">コメント</label><textarea name="content" class="form-control" rows="8" placeholder="具体的な行動や発言についてメモしておこう" required></textarea></div>
                                <button type="submit" class="btn btn-dark w-100 fw-bold py-2 border shadow-sm">保存する</button>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="timeline-area">
                            <h5 class="fw-bold mb-3">人格メモストック一覧 (非公開)</h5>
                            <?php if (empty($notes)): ?><p class="text-muted text-center mt-5">まだメモがありません。</p><?php else: foreach ($notes as $note): 
                                $card_class = ($note['type'] === 'GOOD') ? 'note-card-good' : 'note-card-more'; $emoji = ($note['type'] === 'GOOD') ? '😊' : '😢'; ?>
                                <div class="timeline-card <?= $card_class ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-bold d-flex align-items-center"><span class="note-emoji"><?= $emoji ?></span> To: <?= h($note['target_user_name']) ?></div><small style="opacity: 0.7;"><?= date('m/d', strtotime($note['created_at'])) ?></small></div>
                                    <div class="fw-bold mb-1 small"><?= h($note['type']) ?></div><p class="mb-0" style="white-space: pre-wrap;"><?= h($note['content']) ?></p>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-weekly" class="content-section <?= $initial_tab !== 'weekly' ? 'd-none' : '' ?>">
            <div class="sub-tab-container justify-content-between">
                <div class="d-flex gap-3 flex-grow-1 align-items-center">
                    <button id="btn-weekly-input" class="sub-tab-btn active" onclick="switchWeeklySubTab(this,'input')">進捗の入力</button>
                    <div class="arrow-icon"><i class="bi bi-chevron-right"></i></div>
                    <button id="btn-weekly-share" class="sub-tab-btn" onclick="switchWeeklySubTab(this,'share')">進捗の共有</button>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <a href="index.php?tab=dashboard" class="next-plan-btn">次のPLAN設定</a>
                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#archiveModal"><i class="bi bi-clock-history"></i> 過去の記録</button>
                </div>
            </div>

            <div id="weekly-input">
                <form method="post">
                    <input type="hidden" name="action" value="weekly_reflection">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="goal_id" value="<?= $team_goal_data['id'] ?? '' ?>">
                    <?php if(!$team_goal_data): ?><div class="alert alert-warning">まずDASHBOARDで期間と目標を設定してください。</div><?php else: ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-area">
                                    <h5 class="fw-bold mb-4">数値入力 (<?= h($current_period) ?>)</h5>
                                    <div class="mb-4"><label class="d-flex justify-content-between fw-bold mb-2"><span>進捗</span><span class="text-primary" id="val_p">3</span></label><input type="range" name="progress_score" class="form-range" min="1" max="5" value="3" oninput="document.getElementById('val_p').innerText=this.value"></div>
                                    <div class="mb-4"><label class="d-flex justify-content-between fw-bold mb-2"><span>負荷</span><span class="text-primary" id="val_w">3</span></label><input type="range" name="workload_score" class="form-range" min="1" max="5" value="3" oninput="document.getElementById('val_w').innerText=this.value"></div>
                                    <div class="mb-4"><label class="d-flex justify-content-between fw-bold mb-2"><span>改善度</span><span class="text-primary" id="val_i">3</span></label><input type="range" name="improvement_score" class="form-range" min="1" max="5" value="3" oninput="document.getElementById('val_i').innerText=this.value"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-area d-flex flex-column h-100">
                                    <h5 class="fw-bold mb-4">チームへの Good & More</h5>
                                    <div class="mb-3"><label class="fw-bold small mb-1">Good</label><textarea name="team_good" class="form-control" rows="4" required></textarea></div>
                                    <div class="mb-3"><label class="fw-bold small mb-1">More</label><textarea name="team_more" class="form-control" rows="4" required></textarea></div>
                                    <div class="mt-auto text-end"><button type="submit" class="btn btn-dark px-5 py-2">共有する</button></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
            
            <div id="weekly-share" class="d-none">
                <div class="row">
                    <div class="col-md-7">
                        <div class="form-area">
                            <h5 class="fw-bold mb-3">チーム全体の進捗 (平均: <?= h($current_period) ?>)</h5>
                            <div class="progress mb-5" style="height: 30px; background-color: #fff;"><div class="progress-bar bg-success" role="progressbar" style="width: <?= $team_avg_percent ?>%; font-weight:bold; font-size:1.1rem; background-color: var(--color-bg-dark) !important;"><?= $team_avg_percent ?>%</div></div>
                            <table class="table table-bordered bg-white text-center align-middle">
                                <thead class="table-light"><tr><th>名前</th><th>進捗</th><th>負荷</th><th>改善度</th></tr></thead>
                                <tbody>
                                    <?php foreach ($weekly_data_list as $wd): $is_warning = ($wd['progress'] !== '-' && $wd['progress'] == $min_progress); ?>
                                        <tr class="<?= $is_warning ? 'table-warning-row' : '' ?>"><td class="fw-bold"><?= h($wd['name']) ?></td><td><?= h($wd['progress']) ?></td><td><?= h($wd['workload']) ?></td><td><?= h($wd['improvement']) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if($min_progress <= 2): ?><p class="text-danger small mt-2 fw-bold text-center">※ 進捗が遅れているメンバーがいます。</p><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-area" style="overflow-y:auto; height: 500px;">
                            <h5 class="fw-bold mb-4">チームへの Good & More 全体</h5>
                            <?php foreach ($weekly_data_list as $wd): ?>
                                <?php if (!empty($wd['good'])): ?><div class="note-card-good p-3 mb-3 rounded shadow-sm"><div class="fw-bold small mb-1"><i class="bi bi-emoji-smile-fill"></i> <?= h($wd['name']) ?> : Good</div><div class="small"><?= nl2br(h($wd['good'])) ?></div></div><?php endif; ?>
                                <?php if (!empty($wd['more'])): ?><div class="note-card-more p-3 mb-3 rounded shadow-sm"><div class="fw-bold small mb-1"><i class="bi bi-emoji-frown-fill"></i> <?= h($wd['name']) ?> : More</div><div class="small"><?= nl2br(h($wd['more'])) ?></div></div><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-notes" class="content-section <?= $initial_tab !== 'notes' ? 'd-none' : '' ?>">
            <div class="section-header"><h2>中間 Good & More</h2></div>
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="gray-box text-start p-4 mb-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-link-45deg"></i> スプレッドシートのリンクを記録</h5>
                        <form method="post" class="d-flex align-items-end gap-3">
                            <input type="hidden" name="action" value="add_intermediate">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <div class="flex-grow-1"><label class="small fw-bold mb-1">実施日</label><input type="date" name="review_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                            <div class="flex-grow-1" style="flex-basis: 50%;"><label class="small fw-bold mb-1">スプレッドシートのリンク (URL)</label><input type="url" name="sheet_url" class="form-control" placeholder="https://docs.google.com/..." required></div>
                            <button type="submit" class="btn btn-dark" style="min-width: 100px;">追加</button>
                        </form>
                    </div>
                    <div class="timeline-area bg-white border" style="height: auto; min-height: 300px;">
                        <h5 class="fw-bold mb-3 text-secondary">過去の実施ログ</h5>
                        <?php if(empty($intermediate_links)): ?><p class="text-center text-muted py-5">まだ記録がありません。</p><?php else: ?><div class="list-group"><?php foreach($intermediate_links as $link): ?><a href="<?= h($link['sheet_url']) ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-3"><div><h5 class="mb-1 fw-bold"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i><?= date('Y年n月j日', strtotime($link['review_date'])) ?> 実施分</h5><small class="text-muted"><?= h($link['sheet_url']) ?></small></div><span class="badge bg-primary rounded-pill" style="background-color: var(--color-bg-dark) !important;">開く <i class="bi bi-box-arrow-up-right ms-1"></i></span></a><?php endforeach; ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="modal fade" id="archiveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">過去の記録 (アーカイブ)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                </div>
                <div class="modal-body bg-light">
                    <?php if(empty($archive_data)): ?>
                        <p class="text-center text-muted py-3">過去の記録はまだありません。</p>
                    <?php else: ?>
                        <div class="accordion" id="archiveAccordion">
                            <?php foreach($archive_data as $i => $arc): ?>
                                <div class="accordion-item mb-3 border shadow-sm">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $i ?>">
                                            <div class="d-flex flex-column">
                                                <span class="fw-bold"><?= h(date('Y/m/d', strtotime($arc['start_date']))) ?> 〜 <?= h(date('m/d', strtotime($arc['end_date']))) ?></span>
                                                <span class="small text-muted text-truncate" style="max-width:400px;"><?= h($arc['goal_text']) ?></span>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#archiveAccordion">
                                        <div class="accordion-body">
                                            <?php if($arc['progress_score']): ?>
                                                <div class="row text-center mb-3">
                                                    <div class="col"><strong>進捗:</strong> <?= h($arc['progress_score']) ?></div>
                                                    <div class="col"><strong>負荷:</strong> <?= h($arc['workload_score']) ?></div>
                                                    <div class="col"><strong>改善:</strong> <?= h($arc['improvement_score']) ?></div>
                                                </div>
                                                <div class="card mb-2 border-warning"><div class="card-header bg-warning bg-opacity-10 py-1 fw-bold">Good</div><div class="card-body py-2"><?= nl2br(h($arc['team_good'])) ?></div></div>
                                                <div class="card border-info"><div class="card-header bg-info bg-opacity-10 py-1 fw-bold">More</div><div class="card-body py-2"><?= nl2br(h($arc['team_more'])) ?></div></div>
                                            <?php else: ?>
                                                <div class="text-muted small">この期間の振り返り記録はありません。</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
function switchTab(el, tabName) {
    document.querySelectorAll('.content-section').forEach(section => section.classList.add('d-none'));
    if (el) {
        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
        el.classList.add('active');
    }
    const target = document.getElementById('tab-' + tabName);
    if (target) target.classList.remove('d-none');
}
function switchSubTab(el, subName) {
    document.getElementById('daily-progress').classList.add('d-none');
    document.getElementById('daily-personality').classList.add('d-none');
    const target = document.getElementById('daily-' + subName);
    if (target) target.classList.remove('d-none');
    document.getElementById('btn-progress').classList.remove('active');
    document.getElementById('btn-personality').classList.remove('active');
    if (el) el.classList.add('active');
}
function switchWeeklySubTab(el, subName) {
    document.getElementById('weekly-input').classList.add('d-none');
    document.getElementById('weekly-share').classList.add('d-none');
    document.getElementById('btn-weekly-input').classList.remove('active');
    document.getElementById('btn-weekly-share').classList.remove('active');
    const target = document.getElementById('weekly-' + subName);
    if (target) target.classList.remove('d-none');
    if (el) el.classList.add('active');
}
<?php if(isset($_GET['tab']) && $_GET['tab'] == 'weekly' && isset($_GET['sub']) && $_GET['sub'] == 'share'): ?>switchWeeklySubTab(null,'share');<?php endif; ?>
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

