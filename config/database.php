<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

Kone\Config\Env::load(dirname(__DIR__));

define('DB_HOST', Kone\Config\Env::get('DB_HOST', 'localhost'));
define('DB_PORT', Kone\Config\Env::get('DB_PORT', '3306'));
define('DB_USER', Kone\Config\Env::get('DB_USER', 'root'));
define('DB_PASS', Kone\Config\Env::get('DB_PASS', ''));
define('DB_NAME', Kone\Config\Env::get('DB_NAME', 'sanchaya'));

define('BASE_URL', Kone\Config\Env::get('BASE_URL', 'http://localhost/k-one'));
define('APP_NAME', 'K-one');
define('APP_VERSION', '1.0.0');

define('JWT_SECRET', Kone\Config\Env::get('JWT_SECRET', 'k-one-dev-secret-change-me'));
define('JWT_EXPIRES_HOURS', Kone\Config\Env::int('JWT_EXPIRES_HOURS', 12));
define('TIMEZONE', Kone\Config\Env::get('TIMEZONE', 'Asia/Jakarta'));
define('API_ENV', Kone\Config\Env::get('API_ENV', 'dev'));


class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    
    private function __clone() {}

    
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

function db() {
    return Database::getInstance()->getConnection();
}