<?php

namespace Core;

use PDO;

class Database
{
    public $pdo;
    public $statement;
    public function __construct($config)
    {
        // Ambil data database dari array config bawaan, atau fallback ke $_ENV Railway
        $username = $config['user'] ?? $_ENV['DB_USER'] ?? 'root';
        $password = $config['password'] ?? $_ENV['DB_PASS'] ?? '';

        // Kita bersihkan dulu array config dari key user & password biar gak mengacaukan string DSN mysql
        unset($config['user']);
        unset($config['password']);

        // Bangun string DSN (host=...;port=...;dbname=...)
        $dsn = 'mysql:' . http_build_query($config, '', ';');

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    public function query($query, $parms = [])
    {
        $this->statement = $this->pdo->prepare($query);

        $this->statement->execute($parms);

        return $this;
    }
    public function take()
    {
        return $this->statement->fetch();
    }
    public function get()
    {
        return $this->statement->fetchAll();
    }
    public function liveOrDie()
    {
        $hasil = $this->take();
        if (!$hasil) {
            abort(404);
        }
        return $hasil;
    }
    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }
}
