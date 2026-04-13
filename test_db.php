<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=pidev1', 'root', '');
$stmt = $pdo->query("SHOW COLUMNS FROM users");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . $c['Default'] . PHP_EOL;
}
