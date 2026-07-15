<?php

final class Database
{
    public static function connect(): PDO
    {
        $dsn = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $password = getenv('DB_PASSWORD') ?: '';

        if ($dsn === '') {
            throw new RuntimeException('DB_DSN ist nicht konfiguriert.');
        }

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
