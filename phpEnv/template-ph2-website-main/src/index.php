<?php
session_start();

// ==========================================
// 1. 設定 & DB接続
// ==========================================
// ★TiDBを使う場合はここを書き換えてください
$host = 'db';
$dbname = 'hackathon_app';
$db_user = 'root';
$db_pass = 'root';
$upload_dir = __DIR__ . '/uploads/';

// アップロード用ディレクトリの自動作成
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // テーブル作成（自動実行）
    $sql = "
    CREATE TABLE IF NOT EXISTS users (
        id CHAR(36) PRIMARY KEY,
        team_name VARCHAR(255) NOT NULL,
        name VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL DEFAULT '', -- パスワード用
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS reflections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id CHAR(36) NOT NULL,
        screenshot_url TEXT,
        comment TEXT,
        next_plan TEXT,
        is_plan_done TINYINT(1) DEFAULT 0,
        progress_score INT DEFAULT 3,
        workload_score INT DEFAULT 3,
        improvement_score INT DEFAULT 3,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        author_id CHAR(36) NOT NULL,
        author_name VARCHAR(255) NOT NULL,
        target_user_name VARCHAR(255) NOT NULL,
        type ENUM('GOOD', 'MORE') NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ";
    $pdo->exec($sql);

    // カラム追加用（エラー無視）
    try { $pdo->exec("ALTER TABLE reflections ADD COLUMN workload_score INT DEFAULT 3"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE reflections ADD COLUMN improvement_score INT DEFAULT 3"); } catch(Exception $e) {}
    // ★念のためここでもパスワードカラム追加を試みる（SQL実行忘れ防止）
    try { $pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT ''"); } catch(Exception $e) {}

} catch (PDOException $e) {
    die("<div style='text-align:center; padding:2rem;'><h3>System Initializing...</h3><p>データベース構成中です。10秒後にリロードしてください。<br>Error: " . $e->getMessage() . "</p></div>");
}

function get_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

// ==========================================
// 2. ロジック処理
// ==========================================

// ▼▼▼ しっかり実装したログアウト機能 ▼▼▼
if (isset($_GET['logout'])) {
    $_SESSION = array(); // セッション変数を空にする
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: index.php");
    exit;
}

// ▼▼▼ しっかり実装したログイン/登録機能 ▼▼▼
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $team = trim($_POST['team_name']);
    $name = trim($_POST['name']);
    $pass = $_POST['password']; // パスワード

    if ($team && $name && $pass) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE team_name = ? AND name = ?");
        $stmt->execute([$team, $name]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // --- 新規登録 ---
            $uuid = get_uuid();
            $hash = password_hash($pass, PASSWORD_DEFAULT); // 暗号化
            
            $stmt = $pdo->prepare("INSERT INTO users (id, team_name, name, password_hash) VALUES (?, ?, ?, ?)");
            $stmt->execute([$uuid, $team, $name, $hash]);
            
            session_regenerate_id(true);
            $_SESSION['user_id'] = $uuid;
            $_SESSION['name'] = $name;
            $_SESSION['team_name'] = $team;
            header("Location: index.php");
            exit;
        } else {
            // --- 既存ログイン ---
            // パスワード照合 (password_verify)
            // ※古いユーザーなどパスワードが空の場合はそのまま通す等の調整も可能ですが、今回は厳密にチェックします
            if (password_verify($pass, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['team_name'] = $user['team_name'];
                header("Location: index.php");
                exit;
            } else {
                $error_message = "パスワードが間違っています。";
            }
        }
    } else {
        $error_message = "全ての項目を入力してください。";
    }
}

$is_logged_in = isset($_SESSION['user_id']);
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_name = $_SESSION['name'] ?? null;
$current_team = $_SESSION['team_name'] ?? null;

// 投稿処理
if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 振り返り投稿
    if (isset($_POST['action']) && $_POST['action'] === 'reflection') {
        $image_path = '';
        if (isset($_FILES['screenshot_file']) && $_FILES['screenshot_file']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['screenshot_file']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['screenshot_file']['tmp_name'], $upload_dir . $filename)) {
                $image_path = 'uploads/' . $filename;
            }
        }

        if (isset($_POST['prev_reflection_id']) && isset($_POST['plan_done_check'])) {
            $stmt = $pdo->prepare("UPDATE reflections SET is_plan_done = 1 WHERE id = ?");
            $stmt->execute([$_POST['prev_reflection_id']]);
        }

        $stmt = $pdo->prepare("INSERT INTO reflections (user_id, screenshot_url, comment, next_plan, progress_score, workload_score, improvement_score) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $current_user_id, 
            $image_path, 
            $_POST['comment'], 
            $_POST['next_plan'], 
            $_POST['progress_score'],
            $_POST['workload_score'],
            $_POST['improvement_score']
        ]);
        header("Location: index.php");
        exit;
    }
    
    // メモ投稿
    if (isset($_POST['action']) && $_POST['action'] === 'note') {
        $stmt = $pdo->prepare("INSERT INTO notes (author_id, author_name, target_user_name, type, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$current_user_id, $current_user_name, $_POST['target_user_name'], $_POST['type'], $_POST['content']]);
        header("Location: index.php?tab=notes");
        exit;
    }
}

// データ取得 & 集計
$latest_reflection = null;
$team_members = [];
$notes = [];
$reflections = [];
$team_stats = ['avg_progress' => 0, 'min_progress_user' => null, 'min_score' => 5];

if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE team_name = ?");
    $stmt->execute([$current_team]);
    $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM reflections WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$current_user_id]);
    $latest_reflection = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM notes WHERE author_id = ? ORDER BY created_at DESC");
    $stmt->execute([$current_user_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT r.*, u.name FROM reflections r JOIN users u ON r.user_id = u.id WHERE u.team_name = ? ORDER BY r.created_at DESC LIMIT 30");
    $stmt->execute([$current_team]);
    $reflections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 集計ロジック
    $latest_scores = [];
    foreach ($team_members as $member) {
        $stmt = $pdo->prepare("SELECT progress_score FROM reflections WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$member['id']]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $score = $res['progress_score'];
            $latest_scores[] = $score;
            if ($score < $team_stats['min_score']) {
                $team_stats['min_score'] = $score;
                $team_stats['min_progress_user'] = $member['name'];
            }
        }
    }
    if (count($latest_scores) > 0) {
        $avg = array_sum($latest_scores) / count($latest_scores);
        $team_stats['avg_progress'] = round(($avg / 5) * 100);
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync | Team Growth</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        :root { --primary: #4F46E5; --bg: #F3F4F6; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg); color: #1f2937; }
        .navbar { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); border-bottom: 1px solid #e5e7eb; }
        .card { border: none; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); overflow: hidden; }
        .btn-primary { background-color: var(--primary); border: none; border-radius: 10px; padding: 0.6rem 1.2rem; }
        .form-control, .form-select { border-radius: 10px; padding: 0.7rem; }
        .note-card { border-left: 5px solid #ddd; }
        .my-good { background: #eff6ff; border-left-color: #3b82f6; }
        .my-more { background: #fefce8; border-left-color: #eab308; }
        .member-msg { background: #f0fdf4; border-left-color: #22c55e; }
        .range-label { font-size: 0.8rem; font-weight: bold; color: #6b7280; display: flex; justify-content: space-between; margin-bottom: 5px; }
        .range-value { font-size: 1.2rem; font-weight: bold; color: var(--primary); }
    </style>
</head>
<body>

<nav class="navbar sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold text-primary" href="index.php"><i class="bi bi-diagram-3-fill me-2"></i>Sync</a>
        
        <?php if ($is_logged_in): ?>
            <div class="d-flex align-items-center gap-3">
                <span class="small fw-bold">Team: <?= h($current_team) ?> / <?= h($current_user_name) ?></span>
                <a href="?logout=true" class="btn btn-sm btn-outline-danger" onclick="return confirm('ログアウトしますか？');">
                    <i class="bi bi-box-arrow-right"></i> ログアウト
                </a>
            </div>
        <?php endif; ?>
    </div>
</nav>

<div class="container pb-5">
    <?php if (!$is_logged_in): ?>
        <div class="row justify-content-center mt-5">
            <div class="col-md-4">
                <div class="card p-4">
                    <h4 class="text-center fw-bold mb-4">Login</h4>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger p-2 small"><?= h($error_message) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="action" value="login">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">チーム名</label>
                            <input type="text" name="team_name" class="form-control" required placeholder="posse_team1">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">名前</label>
                            <input type="text" name="name" class="form-control" required placeholder="Taro">
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">パスワード</label>
                            <input type="password" name="password" class="form-control" required placeholder="半角英数字">
                            <div class="form-text text-xs">初回は入力したパスワードで新規登録されます</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Login / Register</button>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>

        <div class="card mb-4 bg-white">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8 mb-3 mb-md-0">
                        <h6 class="text-muted fw-bold mb-2"><i class="bi bi-speedometer2 me-2"></i>チーム全体の進捗率 (Average)</h6>
                        <div class="progress" style="height: 25px; border-radius: 12px;">
                            <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: <?= $team_stats['avg_progress'] ?>%">
                                 <?= $team_stats['avg_progress'] ?>%
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <?php if ($team_stats['min_progress_user'] && $team_stats['min_score'] <= 2): ?>
                            <div class="alert alert-danger mb-0 py-2 border-0 rounded-3 shadow-sm">
                                <i class="bi bi-exclamation-circle-fill me-1"></i>
                                <strong>SOS!</strong> <?= h($team_stats['min_progress_user']) ?>さんが遅れています(Lv.<?= $team_stats['min_score'] ?>)
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mb-0 py-2 border-0 rounded-3">
                                <i class="bi bi-check-circle-fill me-1"></i> 順調です！この調子！
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#daily">PDCA振り返り</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#notes">3色ストックメモ</button></li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="daily">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="card h-100">
                            <div class="card-header bg-white fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>今日の振り返り</div>
                            <div class="card-body">
                                <form method="post" enctype="multipart/form-data"> 
                                    <input type="hidden" name="action" value="reflection">
                                    
                                    <?php if ($latest_reflection): ?>
                                        <div class="p-3 mb-4 rounded-3 bg-light border border-warning">
                                            <input type="hidden" name="prev_reflection_id" value="<?= $latest_reflection['id'] ?>">
                                            <div class="form-check">
                                                <input type="checkbox" name="plan_done_check" class="form-check-input" <?= $latest_reflection['is_plan_done'] ? 'checked disabled' : '' ?>>
                                                <label class="form-check-label fw-bold">前回の宣言: <?= h($latest_reflection['next_plan']) ?></label>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mb-4 p-3 bg-light rounded-3">
                                        <div class="mb-3">
                                            <div class="range-label"><span>🚀 進捗 (Progress)</span> <span id="val_p" class="range-value">3</span></div>
                                            <input type="range" name="progress_score" class="form-range" min="1" max="5" value="3" oninput="document.getElementById('val_p').innerText=this.value">
                                        </div>
                                        <div class="mb-3">
                                            <div class="range-label"><span>⚖️ 負荷 (Workload)</span> <span id="val_w" class="range-value">3</span></div>
                                            <input type="range" name="workload_score" class="form-range" min="1" max="5" value="3" oninput="document.getElementById('val_w').innerText=this.value">
                                        </div>
                                        <div>
                                            <div class="range-label"><span>💡 改善度 (Kaizen)</span> <span id="val_i" class="range-value">3</span></div>
                                            <input type="range" name="improvement_score" class="form-range" min="1" max="5" value="3" oninput="document.getElementById('val_i').innerText=this.value">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">📸 スクリーンショット</label>
                                        <input type="file" name="screenshot_file" class="form-control" accept="image/*">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">コメント</label>
                                        <textarea name="comment" class="form-control" rows="3" required placeholder="事実・原因・発見..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-primary">🚩 Next Plan (明日やること)</label>
                                        <input type="text" name="next_plan" class="form-control" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">投稿する</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <h6 class="text-muted fw-bold mb-3">みんなの活動記録</h6>
                        <?php foreach ($reflections as $ref): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="fw-bold d-flex align-items-center gap-2">
                                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:30px;height:30px;"><?= substr(h($ref['name']),0,1) ?></div>
                                            <?= h($ref['name']) ?>
                                        </div>
                                        <small class="text-muted"><?= date('m/d H:i', strtotime($ref['created_at'])) ?></small>
                                    </div>

                                    <div class="d-flex gap-2 mb-3">
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary">進捗: <?= $ref['progress_score'] ?></span>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border">負荷: <?= $ref['workload_score'] ?? '-' ?></span>
                                        <span class="badge bg-warning bg-opacity-10 text-dark border border-warning">改善: <?= $ref['improvement_score'] ?? '-' ?></span>
                                    </div>

                                    <p class="card-text"><?= nl2br(h($ref['comment'])) ?></p>
                                    
                                    <?php if($ref['screenshot_url']): ?>
                                        <div class="mb-3">
                                            <img src="<?= h($ref['screenshot_url']) ?>" class="img-fluid rounded border" style="max-height: 200px;">
                                        </div>
                                    <?php endif; ?>

                                    <div class="alert alert-light border py-2 d-flex justify-content-between align-items-center">
                                        <small><strong>Next:</strong> <?= h($ref['next_plan']) ?></small>
                                        <?php if ($ref['is_plan_done']): ?><span class="badge bg-success rounded-pill">Done</span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="notes">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-white fw-bold">メモをストック</div>
                            <div class="card-body">
                                <form method="post">
                                    <input type="hidden" name="action" value="note">
                                    <div class="mb-3">
                                        <label class="small fw-bold">対象</label>
                                        <select name="target_user_name" class="form-select">
                                            <option value="<?= h($current_user_name) ?>">自分 (Myself)</option>
                                            <?php foreach ($team_members as $m): if($m['name'] !== $current_user_name): ?>
                                                <option value="<?= h($m['name']) ?>"><?= h($m['name']) ?>さん</option>
                                            <?php endif; endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="small fw-bold">種類</label>
                                        <div class="btn-group w-100">
                                            <input type="radio" class="btn-check" name="type" id="t1" value="GOOD" checked><label class="btn btn-outline-primary" for="t1">GOOD</label>
                                            <input type="radio" class="btn-check" name="type" id="t2" value="MORE"><label class="btn btn-outline-warning" for="t2">MORE</label>
                                        </div>
                                    </div>
                                    <div class="mb-3"><textarea name="content" class="form-control" rows="4" required></textarea></div>
                                    <button type="submit" class="btn btn-dark w-100">保存</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <h6 class="text-muted fw-bold">Stock List</h6>
                        <div class="row g-2">
                        <?php foreach ($notes as $note): 
                            $cls = "member-msg"; 
                            if ($note['target_user_name'] === $current_user_name) {
                                $cls = ($note['type'] === 'GOOD') ? "my-good" : "my-more";
                            }
                        ?>
                            <div class="col-12">
                                <div class="card note-card <?= $cls ?> p-3">
                                    <div class="d-flex justify-content-between">
                                        <strong>To: <?= h($note['target_user_name']) ?> <span class="badge bg-white border text-dark"><?= h($note['type']) ?></span></strong>
                                        <small class="text-muted"><?= substr($note['created_at'], 5, 5) ?></small>
                                    </div>
                                    <p class="mb-0 mt-1"><?= nl2br(h($note['content'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>