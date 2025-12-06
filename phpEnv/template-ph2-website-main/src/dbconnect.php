<?php
    $dsn = 'mysql:host=db;dbname=posse;charset=utf8';
    $user = 'root';
    $password = 'root';

    try {
        $dbh = new PDO($dsn, $user, $password);
        echo 'Connection success!';
    } catch (PDOException $e) {
        echo 'Connection failed: ' . $e->getMessage();
    }

  // SQL ステートメント
$sql = 'SELECT content,id FROM questions';

// テーブル内のレコードを順番に出力
foreach($dbh->query($sql) as $row){
    echo $row['id'];
    echo $row['content'];
}

$questions = $dbh->query("SELECT * FROM questions")->fetchAll(PDO::FETCH_ASSOC);
$choices = $dbh->query("SELECT * FROM choice")->fetchAll(PDO::FETCH_ASSOC);

foreach ($questions as $qKey => $question) {
    $question["choices"] = [];
    foreach ($choices as $cKey => $choice) {
        if ($choice["question_id"] == $question["id"]) {
            $question["choices"][] = $choice;
        }
    }
    $questions[$qKey] = $question;
}
?>