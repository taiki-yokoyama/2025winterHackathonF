＜アプリ確認方法＞
2025winterHackathonF\phpEnv\template-ph2-website-mainの階層で
docker compose up -d　を実行
http://localhost:8080/を検索


データベースエラーの場合
init.sqlの内容をhttp://localhost:8081/のphpmyadminのhackathon_appのsqlで実行で直ります



本リポジトリは、デフォルトでは Docker 上の ローカル MySQL に接続し、
clone → docker compose up ですぐに動作する仕様になっています。

一方、実際のチーム開発を想定し、
複数人が同じ1つのデータベースを参照する構成も念頭に置いています。

想定構成（例）

1台の共有サーバーに MySQL を立てる
（例: 192.168.0.10:3306）

各メンバーは自身の PC で Web コンテナのみ起動

PHP から参照する DB接続先の情報を、共有DBサーバーに変更

# 共有DBを使用する場合の設定例（手動で書き換える）
DB_HOST=192.168.0.10
DB_NAME=hackathon_app
DB_USER=appuser
DB_PASS=app_pass


※現状の成果物では、この環境変数の切り替え処理は 未実装 です。
共有DBで動作させる場合は、DB接続ファイルを手動で編集してください。