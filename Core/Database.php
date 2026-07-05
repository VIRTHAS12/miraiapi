<?php

namespace Core;

use PDO;

class Database
{
    public $pdo;
    public $statement;
    public function __construct($config, $username = 'root', $password = '')
    {
        $dsn = 'mysql:' . http_build_query($config, '', ';');
        $this->pdo = new PDO($dsn, $username, $password, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
