<?php

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  // 1) Load local config as fallback (XAMPP/dev)
  $cfg = require __DIR__ . '/config/db.php';

  // 2) Override with Render/production env vars if present
  $host    = getenv('DB_HOST')    ?: ($cfg['host']    ?? '127.0.0.1');
  $db      = getenv('DB_NAME')    ?: ($cfg['db']      ?? '');
  $user    = getenv('DB_USER')    ?: ($cfg['user']    ?? '');
  $pass    = getenv('DB_PASS')    ?: ($cfg['pass']    ?? '');
  $charset = getenv('DB_CHARSET') ?: ($cfg['charset'] ?? 'utf8mb4');

  $port = getenv('DB_PORT'); // optional
  $portPart = $port ? ";port={$port}" : "";

  $dsn = "mysql:host={$host}{$portPart};dbname={$db};charset={$charset}";

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Good for many hosted MySQL services:
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
  ]);

  return $pdo;
}
