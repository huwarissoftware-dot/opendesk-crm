<?php
declare(strict_types=1);
/* Database connection via PDO (MySQL / MariaDB). Config is written by the installer. */
function db(): PDO {
    static $pdo=null;
    if ($pdo instanceof PDO) return $pdo;
    $cfgFile=dirname(__DIR__).'/config/config.php';
    if (!is_file($cfgFile)) { header('Location: install/'); exit; }
    $c=require $cfgFile;
    $dsn='mysql:host='.$c['db_host'].';port='.($c['db_port']??3306).';dbname='.$c['db_name'].';charset=utf8mb4';
    $pdo=new PDO($dsn,$c['db_user'],$c['db_pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $tz=$c['timezone']??'Asia/Karachi';
    date_default_timezone_set($tz);
    return $pdo;
}
