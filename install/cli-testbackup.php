<?php
/**
 * install/cli-testbackup.php — ทดสอบการสำรองและนำเข้าฐานข้อมูล (Gcms\Database\ImportExportService)
 *
 * เปิด MariaDB/MySQL ชั่วคราวของตัวเองในโฟลเดอร์ชั่วคราว (socket ส่วนตัว ไม่เปิดเครือข่าย) แล้วลบทิ้งเมื่อจบ
 * จึงไม่แตะฐานข้อมูล settings/ หรือ datas/ ของโปรเจ็คเลย
 *
 * ใช้:  php install/cli-testbackup.php
 *
 * ใช้เวลาราว 1 นาที
 *
 * ครอบคลุม: นำเข้าล้มแล้วตารางกลับมาเหมือนเดิม · process ถูก kill แล้วกู้คืนได้ · FK · ENUM ว่าง · id 0 · คอลัมน์
 * generated · ทุกแบบของ key ตอน export · gzip · ไฟล์ที่ถูกตัด · นำเข้าซ้อน · การเลี่ยงตัวตรวจ SQL · ลบไฟล์นอกโฟลเดอร์
 *
 * exit code : 0 = ผ่านทั้งหมดหรือข้ามเพราะไม่มี mariadbd/mysqld, 1 = มีข้อไม่ผ่าน
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$project = dirname(__DIR__);
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Dies by SIGKILL at the chosen moment, which no catch or finally survives.
if (($argv[1] ?? '') === '--crash-child') {
    [, , $S, $file, $modesJson, $overwrite, $when] = $argv;
    backupTestBootstrap($project, $S);
    Kotchasan\Database::config(backupTestConfig($S));
    $svc = new Gcms\Database\ImportExportService();
    if ($when === 'beat') {
        $nextBeat = new ReflectionProperty($svc, 'nextBeat');
        $uncommitted = new ReflectionProperty($svc, 'uncommitted');
        $database = new ReflectionProperty($svc, 'database');
        $beats = 0;
        $svc->setHeartbeat(function () use ($svc, $nextBeat, $uncommitted, $database, &$beats) {
            $saved = (array) $database->getValue($svc)->raw("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA='site_a' AND TABLE_NAME='app_import_state'")->fetch();
            $nextBeat->setValue($svc, 0);
            // Past saving the tables: the statements of the file are running.
            if ((int) $saved['c'] > 0 && ($uncommitted->getValue($svc) > 0 || ++$beats > 40)) {
                posix_kill(getmypid(), SIGKILL);
            }
        });
        $nextBeat->setValue($svc, 0);
    }
    $modes = json_decode($modesJson, true);
    $svc->importSql($file, $modes, array_keys($modes), $overwrite === '1');
    if ($when === 'done') {
        posix_kill(getmypid(), SIGKILL);
    }
    echo "finished without being killed\n";
    exit(0);
}

$server = trim((string) shell_exec('command -v mariadbd || command -v mysqld'));
$installer = trim((string) shell_exec('command -v mariadb-install-db || command -v mysql_install_db'));
if ($server === '' || $installer === '' || !function_exists('posix_kill')) {
    echo "Skipped: needs mariadbd or mysqld, mariadb-install-db, and the posix extension\n";
    exit(0);
}

// Short on purpose: a socket path may not be longer than 107 bytes.
$S = sys_get_temp_dir().'/gcmsbk'.bin2hex(random_bytes(4));
mkdir($S, 0700);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, static function () {
            exit(1);
        });
    }
}
register_shutdown_function(function () use ($S) {
    if (is_file($S.'/pid')) {
        @posix_kill((int) file_get_contents($S.'/pid'), SIGTERM);
        for ($i = 0; $i < 100 && is_file($S.'/pid'); $i++) {
            usleep(100000);
        }
    }
    shell_exec('rm -rf '.escapeshellarg($S));
});
shell_exec(escapeshellarg($installer).' --no-defaults --datadir='.escapeshellarg($S.'/data').' --auth-root-authentication-method=normal --skip-test-db --user='.escapeshellarg((string) posix_getpwuid(posix_geteuid())['name']).' >/dev/null 2>&1');
shell_exec(escapeshellarg($server).' --no-defaults --datadir='.escapeshellarg($S.'/data').' --socket='.escapeshellarg($S.'/s.sock').' --skip-networking --pid-file='.escapeshellarg($S.'/pid').' --innodb-buffer-pool-size=128M --character-set-server=utf8mb4 --max-allowed-packet=64M >/dev/null 2>&1 &');
for ($i = 0; $i < 150 && !file_exists($S.'/s.sock'); $i++) {
    usleep(100000);
}
if (!file_exists($S.'/s.sock')) {
    echo "Could not start a temporary database server\n";
    exit(1);
}

backupTestBootstrap($project, $S);
$service = $project.'/Gcms/Database/ImportExportService.php';
$sock = $S.'/s.sock';
chdir($S);

use Gcms\Database\ImportExportService as Svc;
use Kotchasan\Database;

$root = new PDO("mysql:unix_socket=$sock;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$root->exec('SET GLOBAL lock_wait_timeout=10');
$root->exec('SET GLOBAL innodb_lock_wait_timeout=5');
$pass = 0;
$fail = 0;

function backupTestBootstrap($project, $S)
{
    define('ROOT_PATH', $S.'/root/');
    define('DATA_FOLDER', 'datas/users/site/');
    define('DB_LOG', false);
    define('DB_CACHE', false);
    define('DEBUG', 0);
    @mkdir(ROOT_PATH.DATA_FOLDER, 0777, true);
    spl_autoload_register(function ($c) use ($project) {
        if (strpos($c, 'Kotchasan\\') === 0 || strpos($c, 'Gcms\\') === 0) {
            $f = $project.'/'.str_replace('\\', '/', $c).'.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
    });
}

function backupTestConfig($S)
{
    return ['default' => ['driver' => 'mysql', 'host' => 'localhost', 'unix_socket' => $S.'/s.sock', 'database' => 'site_a', 'username' => 'root', 'password' => '', 'prefix' => 'app', 'charset' => 'utf8mb4']];
}

function t($label, $ok, $detail = '')
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : '  => '.substr((string) $detail, 0, 300));
}

function fresh(PDO $r)
{
    foreach ($r->query("SELECT ID FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND USER='root'")->fetchAll() as $p) {
        try {
            $r->exec('KILL '.(int) $p['ID']);
        } catch (\Throwable $e) {
        }
    }
    $r->exec('DROP DATABASE IF EXISTS site_a');
    $r->exec('DROP DATABASE IF EXISTS site_b');
    $r->exec('CREATE DATABASE site_a CHARACTER SET utf8mb4');
    $r->exec('CREATE DATABASE site_b CHARACTER SET utf8mb4');
    $r->exec('CREATE TABLE site_b.secret (id INT PRIMARY KEY, v VARCHAR(50))');
    $r->exec("INSERT INTO site_b.secret VALUES (1,'top secret')");
    $r->exec('USE site_a');
}

function svc()
{
    global $S;
    Database::config(backupTestConfig($S));

    return new Svc();
}

function importText($content, $modes, $overwrite = false, $json = false, $service = null)
{
    global $S;
    $f = $S.'/fx'.($json ? '.json' : '.sql');
    file_put_contents($f, $content);
    try {
        $s = $service ?: svc();
        $r = $json ? $s->importJson($f, $modes, array_keys($modes), $overwrite) : $s->importSql($f, $modes, array_keys($modes), $overwrite);

        return ['ok' => true, 'stats' => $r['stats'], 'code' => 0, 'msg' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'code' => $e->getCode(), 'msg' => $e->getMessage(), 'stats' => []];
    }
}

function imp($file, $modes, $overwrite = false, $json = false)
{
    try {
        $s = svc();
        $r = $json ? $s->importJson($file, $modes, array_keys($modes), $overwrite) : $s->importSql($file, $modes, array_keys($modes), $overwrite);

        return ['ok' => true, 'stats' => $r['stats'], 'code' => 0, 'msg' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'code' => $e->getCode(), 'msg' => $e->getMessage(), 'stats' => []];
    }
}

function rows(PDO $r, $t)
{
    return $r->query("SELECT * FROM site_a.`$t` ORDER BY 1")->fetchAll();
}

function all(PDO $r, $t, $order = '1')
{
    return $r->query("SELECT * FROM site_a.`$t` ORDER BY $order")->fetchAll();
}

function copies(PDO $r)
{
    return array_column($r->query("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA='site_a' AND (TABLE_NAME LIKE '%\\_\\_restore' OR TABLE_NAME = 'app_import_state')")->fetchAll(), 't');
}

function leftovers(PDO $r)
{
    return copies($r);
}

$both = ['structure' => true, 'data' => true];
$dataOnly = ['structure' => false, 'data' => true];
$tmp = $S.'/f2.sql';

$seed = function () use ($root) {
    fresh($root);
    $root->exec("CREATE TABLE site_a.app_t (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, v VARCHAR(50) COMMENT 'ไทย', KEY k (v)) ENGINE=InnoDB AUTO_INCREMENT=50");
    $root->exec("INSERT INTO site_a.app_t VALUES (1,'orig'),(2,'ไทย')");
};
$expected = [['id' => 1, 'v' => 'orig'], ['id' => 2, 'v' => 'ไทย']];
$ddl = function () use ($root) {
    return $root->query('SHOW CREATE TABLE site_a.app_t')->fetch()['Create Table'];
};

echo "### Issue 1: a failed import leaves the tables as they were\n";
$seed();
$ddl0 = $ddl();
$bad = "DROP TABLE IF EXISTS `app_t`;\nCREATE TABLE `app_t` (`id` int NOT NULL PRIMARY KEY, `other` varchar(50));\nINSERT INTO `app_t` VALUES (1,'a');\nINSERT INTO `app_t` VALUES (1,'dup');\n";
$r = importText($bad, ['app_t' => $both]);
t('struct+data: reports failure', !$r['ok'] && strpos($r['msg'], 'put back as they were') !== false, $r['msg']);
t('struct+data: rows are back', rows($root, 'app_t') === $expected, json_encode(rows($root, 'app_t')));
t('struct+data: definition and AUTO_INCREMENT are back', $ddl() === $ddl0, $ddl());
t('struct+data: no copy left', copies($root) === [], json_encode(copies($root)));

$seed();
$r = importText("INSERT INTO `app_t` VALUES (5,'a');\nINSERT INTO `app_t` VALUES (5,'dup');\n", ['app_t' => $dataOnly], true);
t('data+overwrite: rows are back', !$r['ok'] && rows($root, 'app_t') === $expected, json_encode(rows($root, 'app_t')));
t('data+overwrite: no copy left', copies($root) === []);

$seed();
$r = importText("INSERT INTO `app_t` VALUES (5,'a');\nINSERT INTO `app_t` VALUES (5,'dup');\n", ['app_t' => $dataOnly], false);
t('data only: rows are back', !$r['ok'] && rows($root, 'app_t') === $expected, json_encode(rows($root, 'app_t')));

$seed();
$root->exec("CREATE TABLE site_a.app_my (id INT PRIMARY KEY, v VARCHAR(9)) ENGINE=MyISAM");
$root->exec("INSERT INTO site_a.app_my VALUES (1,'orig')");
$r = importText("INSERT INTO `app_my` VALUES (5,'a');\nINSERT INTO `app_my` VALUES (5,'dup');\n", ['app_my' => $dataOnly]);
t('MyISAM data only: rows are back (no transaction there)', !$r['ok'] && rows($root, 'app_my') === [['id' => 1, 'v' => 'orig']], json_encode(rows($root, 'app_my')));

$seed();
$js = json_encode(['tables' => ['app_t' => ['create' => 'CREATE TABLE `app_t` (`id` int NOT NULL PRIMARY KEY, `v` varchar(50))', 'rows' => [['id' => 7, 'v' => 'x'], ['id' => 7, 'v' => 'dup']]]]]);
$r = importText($js, ['app_t' => $both], false, true);
t('JSON: rows and definition are back', !$r['ok'] && rows($root, 'app_t') === $expected && $ddl() === $ddl0, json_encode(rows($root, 'app_t')).' '.$r['msg']);

$seed();
$js = json_encode(['tables' => ['app_t' => ['create' => 'CREATE TABLE `app_t` (`id` nosuchtype)', 'rows' => []]]]);
$r = importText($js, ['app_t' => $both], false, true);
t('JSON: a CREATE the server rejects loses nothing', !$r['ok'] && rows($root, 'app_t') === $expected, json_encode(rows($root, 'app_t')).' '.$r['msg']);

$seed();
$root->exec("CREATE TABLE site_a.app_zero (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, v VARCHAR(9))");
$root->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
$root->exec("INSERT INTO site_a.app_zero VALUES (0,'zero'),(1,'one')");
$root->exec('SET SESSION sql_mode=DEFAULT');
$r = importText("DROP TABLE `app_zero`;\nCREATE TABLE `app_zero` (`id` int);\nINSERT INTO `app_zero` VALUES ('x');\nALTER TABLE `app_zero` ADD COLUMN `id` int;\n", ['app_zero' => $both]);
t('restore keeps an id of 0', !$r['ok'] && rows($root, 'app_zero') === [['id' => 0, 'v' => 'zero'], ['id' => 1, 'v' => 'one']], json_encode(rows($root, 'app_zero')).' '.$r['msg']);

$seed();
$r = importText("SET SQL_MODE = '';\nSET FOREIGN_KEY_CHECKS=1;\nSET AUTOCOMMIT=0;\nSET NAMES latin1;\nDROP TABLE `app_t`;\nCREATE TABLE `app_t` (`id` int NOT NULL PRIMARY KEY);\nINSERT INTO `app_t` VALUES (1);\nINSERT INTO `app_t` VALUES (1);\n", ['app_t' => $both]);
$fresh = new PDO("mysql:unix_socket=$sock;charset=utf8mb4", 'root', '', [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$seen = $fresh->query('SELECT * FROM site_a.app_t ORDER BY 1')->fetchAll();
t('session changed by the file: restore still exact, seen by another connection', !$r['ok'] && $seen === $expected && $ddl() === $ddl0, json_encode($seen).' '.$r['msg']);

echo "\n### FK on the table being restored\n";
$fk = function () use ($root) {
    fresh($root);
    $root->exec('CREATE TABLE site_a.app_parent (id INT PRIMARY KEY) ENGINE=InnoDB');
    $root->exec('CREATE TABLE site_a.app_child (id INT PRIMARY KEY, pid INT, CONSTRAINT fk_child_parent FOREIGN KEY (pid) REFERENCES app_parent(id)) ENGINE=InnoDB');
    $root->exec('INSERT INTO site_a.app_parent VALUES (1)');
    $root->exec('INSERT INTO site_a.app_child VALUES (1,1)');
};
$fkDefined = function () use ($root) {
    return (int) $root->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='site_a' AND CONSTRAINT_TYPE='FOREIGN KEY' AND CONSTRAINT_NAME='fk_child_parent'")->fetchColumn();
};
$fk();
$r = importText("DROP TABLE `app_child`;\nCREATE TABLE `app_child` (`id` int PRIMARY KEY);\nINSERT INTO `app_child` VALUES (1);\nINSERT INTO `app_child` VALUES (1);\n", ['app_child' => $both]);
t('failed import on a child: rows and its FK are back', !$r['ok'] && rows($root, 'app_child') === [['id' => 1, 'pid' => 1]] && $fkDefined() === 1, json_encode(rows($root, 'app_child')).' fk='.$fkDefined().' '.$r['msg']);
$fk();
$r = importText("DROP TABLE `app_parent`;\nCREATE TABLE `app_parent` (`id` int PRIMARY KEY);\nINSERT INTO `app_parent` VALUES (1);\nINSERT INTO `app_parent` VALUES (1);\n", ['app_parent' => $both]);
t('failed import on a parent: rows back, child FK survives', !$r['ok'] && rows($root, 'app_parent') === [['id' => 1]] && $fkDefined() === 1, $r['msg']);
$fk();
$create = $root->query('SHOW CREATE TABLE site_a.app_child')->fetch()['Create Table'];
$r = importText(json_encode(['tables' => ['app_child' => ['create' => $create, 'rows' => [['id' => 2, 'pid' => 1]]]]]), ['app_child' => $both], false, true);
t('Issue 3: JSON import of a table with a named FK works', $r['ok'] && rows($root, 'app_child') === [['id' => 2, 'pid' => 1]] && $fkDefined() === 1 && copies($root) === [], $r['msg']);
$fk();
$fkModes = ['app_parent' => $both, 'app_child' => $both];
$dump = $S.'/fk2.sql';
svc()->exportSqlByTableModes($dump, $fkModes, 500);
$r = importText(file_get_contents($dump), $fkModes, true);
t('overwrite on an FK parent works (was error 1701)', $r['ok'] && rows($root, 'app_child') === [['id' => 1, 'pid' => 1]] && $fkDefined() === 1, $r['msg']);

echo "\n### Issue 2: own backup restores on a strict server\n";
fresh($root);
$root->exec("CREATE TABLE site_a.app_en (id INT PRIMARY KEY, e ENUM('a','b') DEFAULT 'a') ENGINE=InnoDB");
$root->exec("SET SESSION sql_mode=''");
$root->exec("INSERT INTO site_a.app_en VALUES (1,'a'),(2,''),(3,'b')");
$root->exec('SET SESSION sql_mode=DEFAULT');
t('server default is strict', strpos((string) $root->query('SELECT @@SESSION.sql_mode')->fetchColumn(), 'STRICT_TRANS_TABLES') !== false);
$want = rows($root, 'app_en');
$dump = $S.'/en2.sql';
$em = ['app_en' => $both];
svc()->exportSqlByTableModes($dump, $em, 500);
t('export writes the sql_mode line', strpos(file_get_contents($dump), "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n") !== false);
$r = importText(file_get_contents($dump), $em);
t('backup with an empty ENUM restores', $r['ok'] && rows($root, 'app_en') === $want, $r['msg']);
$old = str_replace("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n", '', file_get_contents($dump));
$r = importText($old, $em);
t('a backup made before this fix (no sql_mode line) restores too', $r['ok'] && rows($root, 'app_en') === $want, $r['msg']);
$jf = $S.'/en2.json';
svc()->exportJson($jf, ['app_en'], 'both', 500);
$r = importText(file_get_contents($jf), $em, false, true);
t('JSON backup with an empty ENUM restores', $r['ok'] && rows($root, 'app_en') === $want, $r['msg']);
fresh($root);
$root->exec('CREATE TABLE site_a.app_gen (id INT PRIMARY KEY, g INT GENERATED ALWAYS AS (id * 2) VIRTUAL)');
$root->exec('INSERT INTO site_a.app_gen (id) VALUES (1),(2)');
$gm = ['app_gen' => $both];
$dump = $S.'/gen2.sql';
svc()->exportSqlByTableModes($dump, $gm, 500);
$r = importText(file_get_contents($dump), $gm);
t('generated column: own backup restores', $r['ok'] && rows($root, 'app_gen') === [['id' => 1, 'g' => 2], ['id' => 2, 'g' => 4]], $r['msg']);
$r = importText("DROP TABLE `app_gen`;\nCREATE TABLE `app_gen` (`id` int PRIMARY KEY);\nINSERT INTO `app_gen` VALUES (1);\nINSERT INTO `app_gen` VALUES (1);\n", $gm);
t('generated column: failed import puts it back', !$r['ok'] && rows($root, 'app_gen') === [['id' => 1, 'g' => 2], ['id' => 2, 'g' => 4]], $r['msg']);

echo "\n### Session after an import\n";
$seed();
Database::config(backupTestConfig($S));
$conn = Database::create();
$s = new Svc($conn);
$read = function () use ($conn) {
    return (array) $conn->raw('SELECT @@SESSION.sql_mode m, @@SESSION.foreign_key_checks f, @@SESSION.autocommit a, @@SESSION.character_set_client c')->fetch();
};
$before = $read();
$r = importText("SET AUTOCOMMIT=0;\nSET NAMES latin1;\nSET FOREIGN_KEY_CHECKS=0;\nINSERT INTO `app_t` VALUES (9,'n');\n", ['app_t' => $dataOnly], false, false, $s);
t('after success the session is as it was', $r['ok'] && $read() === $before, json_encode($read()).' '.$r['msg']);
$r = importText("SET AUTOCOMMIT=0;\nSET NAMES latin1;\nINSERT INTO `app_t` VALUES (1,'dup');\n", ['app_t' => $dataOnly], false, false, $s);
t('after failure the session is as it was', !$r['ok'] && $read() === $before, json_encode($read()));
$fresh = new PDO("mysql:unix_socket=$sock;charset=utf8mb4", 'root', '', [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
t('a file asking for SET AUTOCOMMIT=0 is still committed', count($fresh->query('SELECT * FROM site_a.app_t')->fetchAll()) === 3);

echo "\n### A copy left by a killed import\n";
$seed();
$root->exec("CREATE TABLE site_a.app_t__restore LIKE site_a.app_t");
$root->exec("INSERT INTO site_a.app_t__restore VALUES (1,'last good')");
$r = importText("DROP TABLE `app_t`;\nCREATE TABLE `app_t` (`id` int);\n", ['app_t' => $both]);
t('import refused, code 409, names the table', !$r['ok'] && $r['code'] === 409 && strpos($r['msg'], 'app_t__restore') !== false, $r['code'].' '.$r['msg']);
t('nothing changed, the copy is intact', rows($root, 'app_t') === $expected && rows($root, 'app_t__restore') === [['id' => 1, 'v' => 'last good']]);

echo "\n### Copies cannot be made: nothing is touched\n";
$seed();
$long = 'app_'.str_repeat('x', 56);
$root->exec("CREATE TABLE site_a.`$long` (id INT PRIMARY KEY)");
$root->exec("INSERT INTO site_a.`$long` VALUES (1)");
$r = importText("DROP TABLE `$long`;\nCREATE TABLE `$long` (`id` int PRIMARY KEY);\nINSERT INTO `$long` VALUES (2);\nINSERT INTO `$long` VALUES (2);\n", [$long => $both]);
t('a 60-character table name: copy fits, table is back', !$r['ok'] && rows($root, $long) === [['id' => 1]] && copies($root) === [], $r['msg']);

echo "\n### Issue 4: nothing lost silently\n";
$one = ['app_t' => $dataOnly];
$seed();
$r = importText("INSERT IGNORE INTO `app_t` VALUES (1,'ignored'),(3,'new');\n", $one);
t('INSERT IGNORE runs and keeps its IGNORE', $r['ok'] && $r['stats']['executed'] === 1 && count(rows($root, 'app_t')) === 3 && rows($root, 'app_t')[0]['v'] === 'orig', json_encode(rows($root, 'app_t')));
$seed();
$r = importText("INSERT LOW_PRIORITY INTO `app_t` VALUES (3,'p');\nREPLACE LOW_PRIORITY INTO `app_t` VALUES (4,'p');\n", $one);
t('INSERT and REPLACE with a priority run', $r['ok'] && count(rows($root, 'app_t')) === 4, json_encode(rows($root, 'app_t')).' '.$r['msg']);
$seed();
$r = importText("INSERT `app_t` VALUES (3,'no into');\n", $one);
t('an INSERT shape not known: refused, not skipped', !$r['ok'] && $r['code'] === 422, json_encode($r));
$seed();
$r = importText("INSERT INTO `app_t` VALUES (3,'a'); INSERT INTO `app_t` VALUES (4,'b'); INSERT INTO `app_t` VALUES (5,'c');\n", $one);
t('three statements on one line: all run', $r['ok'] && $r['stats']['executed'] === 3 && count(rows($root, 'app_t')) === 5, json_encode($r['stats']));
$seed();
$r = importText("INSERT INTO `app_t` VALUES (3,'a');-- it's a comment\nINSERT INTO `app_t` VALUES (4,'b'); # don't stop\nINSERT INTO `app_t` VALUES (5,'c'); -- another's\n", $one);
t('a comment after the statement ends the line', $r['ok'] && $r['stats']['executed'] === 3 && count(rows($root, 'app_t')) === 5, json_encode($r));
$seed();
$r = importText("INSERT INTO `app_t` VALUES (3,'a'); /* note */\nINSERT INTO `app_t` VALUES (4,'b');\n/* block */ INSERT INTO `app_t` VALUES (5,'c');\n", $one);
t('a block comment does not hide the next statement', $r['ok'] && $r['stats']['executed'] === 3 && count(rows($root, 'app_t')) === 5, json_encode($r));
$seed();
$r = importText("INSERT INTO `app_t` VALUES (3,'a'); DROP TABLE site_b.secret;\n", $one);
t('a forbidden statement later on the line refuses the file', !$r['ok'] && $r['code'] === 422 && count(rows($root, 'app_t')) === 2 && (int) $root->query('SELECT COUNT(*) FROM site_b.secret')->fetchColumn() === 1, json_encode($r));
$seed();
$r = importText("INSERT INTO `app_t` VALUES (3,'a;b''c\\'d'); INSERT INTO `app_t` VALUES (4,'-- x'); /*! DROP TABLE site_b.secret */;\n", $one);
t('strings with ; and quotes on one line; a /*! stays unrun', $r['ok'] && count(rows($root, 'app_t')) === 4 && (int) $root->query('SELECT COUNT(*) FROM site_b.secret')->fetchColumn() === 1, json_encode($r));

echo "\n### A table that does not exist yet\n";
fresh($root);
$r = importText("CREATE TABLE `app_new` (`id` int PRIMARY KEY);\nINSERT INTO `app_new` VALUES (1);\n", ['app_new' => $both]);
t('import creates it', $r['ok'] && rows($root, 'app_new') === [['id' => 1]] && copies($root) === [], $r['msg']);
fresh($root);
$r = importText("CREATE TABLE `app_new` (`id` int PRIMARY KEY);\nINSERT INTO `app_new` VALUES (1);\nINSERT INTO `app_new` VALUES (1);\n", ['app_new' => $both]);
$gone = (int) $root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='site_a' AND TABLE_NAME='app_new'")->fetchColumn();
t('a failed import removes it again', !$r['ok'] && $gone === 0 && copies($root) === [], $r['msg']);

echo "\n### The connection dies in the middle of an import\n";
fresh($root);
$root->exec('CREATE TABLE site_a.app_big (id INT PRIMARY KEY, v TEXT) ENGINE=InnoDB');
$root->exec("INSERT INTO site_a.app_big VALUES (1,'orig'),(2,'orig')");
$big = $S.'/killed.sql';
$fh = fopen($big, 'wb');
fwrite($fh, "DROP TABLE `app_big`;\nCREATE TABLE `app_big` (`id` int PRIMARY KEY, `v` text);\n");
for ($i = 1; $i <= 300000; $i++) {
    fwrite($fh, "INSERT INTO `app_big` VALUES ($i,'x');\n");
}
fclose($fh);
$killer = $S.'/killer.php';
file_put_contents($killer, '<?php $p = new PDO("mysql:unix_socket='.$sock.'", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); for ($i = 0; $i < 600; $i++) { $n = (int) $p->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=\'site_a\' AND TABLE_NAME=\'app_big__restore\'")->fetchColumn(); $rows = $n ? (int) $p->query("SELECT COUNT(*) FROM site_a.app_big__restore")->fetchColumn() : 0; if ($rows === 2) { break; } usleep(50000); } usleep(800000); foreach ($p->query("SELECT ID FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND USER=\'root\'")->fetchAll(PDO::FETCH_ASSOC) as $r) { $p->exec("KILL ".(int) $r["ID"]); }');
$rootId = (int) $root->query('SELECT CONNECTION_ID()')->fetchColumn();
$root = null;
$proc = proc_open(escapeshellarg(PHP_BINARY).' '.escapeshellarg($killer), [], $pipes, $S);
$s = svc();
$m = ['app_big' => $both];
try {
    $s->importSql($big, $m, array_keys($m), false);
    $res = ['ok' => true, 'msg' => '', 'code' => 0];
} catch (\Throwable $e) {
    $res = ['ok' => false, 'msg' => $e->getMessage(), 'code' => $e->getCode()];
}
proc_close($proc);
$root = new PDO("mysql:unix_socket=$sock;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
t('reports which table could not be put back', !$res['ok'] && strpos($res['msg'], 'could not be put back yet: app_big') !== false, substr($res['msg'], 0, 200));
t('the copy still holds the earlier rows', rows($root, 'app_big__restore') === [['id' => 1, 'v' => 'orig'], ['id' => 2, 'v' => 'orig']]);
for ($i = 0; $i < 100 && (int) $root->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND USER='root'")->fetchColumn() > 0; $i++) {
    usleep(100000);
}
$r = importText("INSERT INTO `app_big` VALUES (9,'n');\n", ['app_big' => $dataOnly]);
t('the next import first puts it back, then runs', $r['ok'] && rows($root, 'app_big') === [['id' => 1, 'v' => 'orig'], ['id' => 2, 'v' => 'orig'], ['id' => 9, 'v' => 'n']] && copies($root) === [], json_encode($r));
@unlink($big);


echo "### Export: every row exactly once, whatever the key\n";
$cases = [
    'integer key' => ['CREATE TABLE site_a.app_x (id INT PRIMARY KEY, v VARCHAR(20))', 'id'],
    'composite key' => ['CREATE TABLE site_a.app_x (a INT, b VARCHAR(8), v VARCHAR(20), PRIMARY KEY (a,b))', 'a,b'],
    'string key, case-insensitive' => ['CREATE TABLE site_a.app_x (k VARCHAR(20) COLLATE utf8mb4_unicode_ci PRIMARY KEY, v VARCHAR(20))', 'k'],
    'unique key, no primary' => ['CREATE TABLE site_a.app_x (u INT NOT NULL, v VARCHAR(20), UNIQUE KEY u (u))', 'u'],
    'no key at all' => ['CREATE TABLE site_a.app_x (n INT, v VARCHAR(20))', 'n,v'],
    'nullable unique only' => ['CREATE TABLE site_a.app_x (u INT NULL, v VARCHAR(20), UNIQUE KEY u (u))', 'u,v'],
    'float key' => ['CREATE TABLE site_a.app_x (f DOUBLE PRIMARY KEY, v VARCHAR(20))', 'f'],
    'prefix-length key' => ['CREATE TABLE site_a.app_x (k VARCHAR(40), v VARCHAR(20), PRIMARY KEY (k(4)))', 'k'],
    'binary key' => ['CREATE TABLE site_a.app_x (k VARBINARY(8) PRIMARY KEY, v VARCHAR(20))', 'k']
];
foreach ($cases as $label => [$ddl, $order]) {
    fresh($root);
    $root->exec($ddl);
    $cols = array_column($root->query('SHOW COLUMNS FROM site_a.app_x')->fetchAll(), 'Field');
    $ins = $root->prepare('INSERT IGNORE INTO site_a.app_x ('.implode(',', $cols).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).')');
    $root->beginTransaction();
    for ($i = 1; $i <= 1300; $i++) {
        $vals = [];
        foreach ($cols as $c) {
            $vals[] = $c === 'a' ? (int) ($i / 10) : ($c === 'b' ? 'b'.$i : ($c === 'k' ? ($label === 'binary key' ? pack('N', $i).'z' : 'K'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)) : ($c === 'f' ? $i / 3 : ($c === 'v' ? "v'$i" : $i))));
        }
        $ins->execute($vals);
    }
    $root->commit();
    $before = $root->query("SELECT COUNT(*) c, MD5(GROUP_CONCAT(HEX(v) ORDER BY $order SEPARATOR ',')) m FROM site_a.app_x")->fetch();
    $root->exec('SET SESSION group_concat_max_len=100000000');
    $sumBefore = md5(json_encode($root->query("SELECT * FROM site_a.app_x ORDER BY $order")->fetchAll()));
    $m = ['app_x' => $both];
    svc()->exportSqlByTableModes($tmp, $m, 100);
    preg_match_all('/^\(/m', (string) file_get_contents($tmp), $rowsInFile);
    $r = imp($tmp, $m);
    $sumAfter = md5(json_encode($root->query("SELECT * FROM site_a.app_x ORDER BY $order")->fetchAll()));
    t("$label: {$before['c']} rows, none lost or repeated", $r['ok'] && count($rowsInFile[0]) === (int) $before['c'] && $sumBefore === $sumAfter, count($rowsInFile[0]).' in file, '.$r['msg']);
}

fresh($root);
$root->exec('CREATE TABLE site_a.app_w (id INT PRIMARY KEY, b LONGBLOB)');
$ins = $root->prepare('INSERT INTO site_a.app_w VALUES (?,?)');
for ($i = 1; $i <= 40; $i++) {
    $ins->execute([$i, str_repeat(chr($i), 3 * 1048576)]);
}
$sumBefore = $root->query('SELECT MD5(GROUP_CONCAT(MD5(b) ORDER BY id)) FROM site_a.app_w')->fetchColumn();
$start = memory_get_peak_usage(true);
svc()->exportSqlByTableModes($tmp, ['app_w' => $both], 5000);
$grew = (memory_get_peak_usage(true) - $start) / 1048576;
t('40 rows of 3 MB: the batch shrinks to fit memory', $grew < 40, round($grew).' MB');
$r = imp($tmp, ['app_w' => $both]);
t('and they come back intact', $r['ok'] && $sumBefore === $root->query('SELECT MD5(GROUP_CONCAT(MD5(b) ORDER BY id)) FROM site_a.app_w')->fetchColumn(), $r['msg']);

fresh($root);
$root->exec('CREATE TABLE site_a.app_gen (id INT PRIMARY KEY, g INT GENERATED ALWAYS AS (id*2) STORED, h INT AS (id+1) VIRTUAL)');
$root->exec('INSERT INTO site_a.app_gen (id) VALUES (1),(2)');
svc()->exportSqlByTableModes($tmp, ['app_gen' => $both]);
$text = (string) file_get_contents($tmp);
t('a generated column is left out of the INSERT', strpos($text, 'INSERT INTO `app_gen` (`id`) VALUES') !== false, $text);
$r = imp($tmp, ['app_gen' => $both]);
t('and is worked out again', $r['ok'] && all($root, 'app_gen')[1]['g'] == 4 && all($root, 'app_gen')[1]['h'] == 3, $r['msg']);

$root->exec("CREATE OR REPLACE VIEW site_a.app_view AS SELECT id FROM site_a.app_gen");
t('a view is not offered for backup', !in_array('app_view', array_column(svc()->detectTables(), 'name'), true));

fresh($root);
$root->exec('CREATE TABLE site_a.app_j (id INT PRIMARY KEY, b BLOB, t TEXT)');
$binary = "\x00\xff\xfe'\\;\n\x80";
$ins = $root->prepare('INSERT INTO site_a.app_j VALUES (?,?,?)');
$ins->execute([1, $binary, 'ไทย']);
$ins->execute([2, '', '']);
$jf = $S.'/f2.json';
svc()->exportJson($jf, ['app_j'], 'both');
$decoded = json_decode((string) file_get_contents($jf), true);
t('JSON export with binary data is valid JSON', is_array($decoded) && count($decoded['tables']['app_j']['rows']) === 2, json_last_error_msg());
$r = imp($jf, ['app_j' => $both], false, true);
$rows = all($root, 'app_j');
t('and restores the bytes', $r['ok'] && $rows[0]['b'] === $binary && $rows[0]['t'] === 'ไทย' && $rows[1]['b'] === '', $r['msg']);
file_put_contents($jf.'.gz', gzencode((string) file_get_contents($jf)));
$r = imp($jf.'.gz', ['app_j' => $both], false, true);
t('a gzipped JSON backup restores too', $r['ok'] && all($root, 'app_j')[0]['b'] === $binary, $r['msg']);
file_put_contents($jf, json_encode(['tables' => ['app_j' => ['rows' => [['id' => 9, 'b' => ['x' => 1]]]]]]));
$r = imp($jf, ['app_j' => $dataOnly], false, true);
t('a JSON value that is an object is refused, nothing changes', !$r['ok'] && count(all($root, 'app_j')) === 2, $r['msg']);

echo "\n### Import of a file\n";
fresh($root);
$root->exec('CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50))');
$root->exec("INSERT INTO site_a.app_t VALUES (1,'orig')");
$m = ['app_t' => $both];
svc()->exportSqlByTableModes($tmp, $m);
$dump = (string) file_get_contents($tmp);
$tail = "\nSET FOREIGN_KEY_CHECKS=1;\n";
t('a backup ends with the line the import looks for', substr($dump, -strlen($tail)) === $tail);
file_put_contents($tmp, gzencode($dump));
$root->exec("UPDATE site_a.app_t SET v='changed'");
$r = imp($tmp, $m);
t('a gzip backup restores', $r['ok'] && all($root, 'app_t')[0]['v'] === 'orig', $r['msg']);
foreach (['end of a statement' => strrpos($dump, 'SET FOREIGN_KEY'), 'middle of a row' => strrpos($dump, "'orig")+2] as $where => $cut) {
    file_put_contents($tmp, substr($dump, 0, $cut));
    $root->exec("UPDATE site_a.app_t SET v='kept'");
    $r = imp($tmp, $m);
    t("a backup cut off at the $where is refused, nothing changes", !$r['ok'] && $r['code'] === 422 && all($root, 'app_t')[0]['v'] === 'kept', json_encode($r));
}
file_put_contents($tmp, gzencode(substr($dump, 0, strrpos($dump, 'SET FOREIGN_KEY'))));
$r = imp($tmp, $m);
t('also when it is gzipped', !$r['ok'] && $r['code'] === 422, json_encode($r));
$big = "INSERT INTO `app_t` VALUES (2,'".str_repeat('x', 36 * 1048576)."');\n";
file_put_contents($tmp, $big);
$old = ini_set('memory_limit', '128M');
$r = imp($tmp, $m);
ini_set('memory_limit', (string) $old);
t('a statement too large for memory_limit is refused with a reason', !$r['ok'] && $r['code'] === 422 && strpos($r['msg'], 'larger than') !== false, $r['msg']);
unset($big);
$long = "INSERT INTO `app_t` VALUES\n".implode(",\n", array_map(static function ($i) {
    return "($i,'row $i; it''s ok \\' -- not a comment')";
}, range(10, 30000))).";\n";
file_put_contents($tmp, "-- comment with don't\n# another's\n/* block's own */ ".$long."INSERT INTO `app_t` VALUES (5,'after');\n");
$r = imp($tmp, ['app_t' => $dataOnly]);
t('a 1 MB statement with quotes across reads', $r['ok'] && (int) $root->query('SELECT COUNT(*) FROM site_a.app_t')->fetchColumn() >= 29990, json_encode($r));
$numeric = "INSERT INTO `app_t` (`id`) VALUES ".implode(',', array_map(static function ($i) {
    return "($i)";
}, range(100000, 1100000))).";\n";
file_put_contents($tmp, $numeric);
$r = imp($tmp, ['app_t' => $dataOnly]);
t(round(strlen($numeric) / 1048576).' MB of plain numbers in one statement', $r['ok'], $r['msg']);
unset($numeric);
file_put_contents($tmp, 'INSERT INTO `app_t` (`id`) VALUES (2000001);'.str_repeat(' ', 2 * 1048576)."-- it's a very long comment line ".str_repeat('x', 3 * 1048576)."\nINSERT INTO `app_t` (`id`) VALUES (2000002);\n");
$r = imp($tmp, ['app_t' => $dataOnly]);
t('a line longer than the read size keeps its statements', $r['ok'] && $r['stats']['executed'] === 2, json_encode($r));

echo "\n### Two imports at once\n";
fresh($root);
$root->exec('CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50))');
$root->exec("INSERT INTO site_a.app_t VALUES (1,'orig')");
$other = new PDO("mysql:unix_socket=$sock", 'root', '');
$lock = 'gcms_import_'.md5('site_a|app');
$other->query("SELECT GET_LOCK('$lock', 0)")->fetchAll();
file_put_contents($tmp, "DROP TABLE `app_t`;\nCREATE TABLE `app_t` (`id` int);\n");
$r = imp($tmp, ['app_t' => $both]);
t('the second is refused with 409, nothing changes', !$r['ok'] && $r['code'] === 409 && strpos($r['msg'], 'Another import') !== false && all($root, 'app_t')[0]['v'] === 'orig', json_encode($r));
t('and the page does not disturb the running one', svc()->recoverInterruptedImport() === []);
$other = null;
usleep(200000);
$r = imp($tmp, ['app_t' => $both]);
t('a dead holder frees the lock by itself', $r['ok'], $r['msg']);

echo "\n### A process killed outright, in each way of saving a table\n";
$scenarios = [
    'rename (structure+data)' => ['setup' => ['CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50)) ENGINE=InnoDB AUTO_INCREMENT=50'], 'tables' => ['app_t' => $both], 'overwrite' => '0', 'how' => 'rename'],
    'rename (overwrite)' => ['setup' => ['CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50)) ENGINE=InnoDB'], 'tables' => ['app_t' => $dataOnly], 'overwrite' => '1', 'how' => 'rename'],
    'copy (a table with a foreign key)' => ['setup' => ['CREATE TABLE site_a.app_p (id INT PRIMARY KEY) ENGINE=InnoDB', 'INSERT INTO site_a.app_p VALUES (1)', 'CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50), CONSTRAINT fk_x FOREIGN KEY (id) REFERENCES app_p(id)) ENGINE=InnoDB'], 'tables' => ['app_t' => $both], 'overwrite' => '0', 'how' => 'copy'],
    'copy (MyISAM, data only)' => ['setup' => ['CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50)) ENGINE=MyISAM'], 'tables' => ['app_t' => $dataOnly], 'overwrite' => '0', 'how' => 'copy']
];
foreach ($scenarios as $label => $sc) {
    fresh($root);
    foreach ($sc['setup'] as $sql) {
        $root->exec($sql);
    }
    $root->exec("INSERT INTO site_a.app_t VALUES (1,'orig')");
    $ddl0 = preg_replace('/ AUTO_INCREMENT=\d+/', '', $root->query('SHOW CREATE TABLE site_a.app_t')->fetch()['Create Table']);
    $structure = !empty($sc['tables']['app_t']['structure']);
    $body = $structure ? "DROP TABLE IF EXISTS `app_t`;\nCREATE TABLE `app_t` (`id` int NOT NULL PRIMARY KEY, `other` varchar(50));\n" : '';
    for ($i = 2; $i <= 3000; $i++) {
        $body .= "INSERT INTO `app_t` VALUES ($i,'partial');\n";
    }
    file_put_contents($tmp, $body);
    $out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --crash-child '.escapeshellarg($S).' '.escapeshellarg($tmp).' '.escapeshellarg(json_encode($sc['tables'])).' '.$sc['overwrite'].' beat 2>&1; echo "exit=$?"');
    t("$label: the child really died", strpos((string) $out, 'exit=137') !== false, $out);
    $state = $root->query("SELECT how FROM site_a.app_import_state WHERE tbl='app_t'")->fetchColumn();
    t("$label: it was saved that way", $state === $sc['how'], (string) $state);
    t("$label: copy and state are hidden from the list", array_column(svc()->detectTables(), 'name') === array_values(array_diff(array_column(svc()->detectTables(), 'name'), ['app_t__restore', 'app_import_state'])));
    $recovered = svc()->recoverInterruptedImport();
    t("$label: opening the page puts it back", $recovered === ['app_t'], json_encode($recovered));
    $ddl1 = preg_replace('/ AUTO_INCREMENT=\d+/', '', $root->query('SHOW CREATE TABLE site_a.app_t')->fetch()['Create Table']);
    t("$label: rows and definition as they were", all($root, 'app_t') === [['id' => 1, 'v' => 'orig']] && $ddl0 === $ddl1, json_encode(all($root, 'app_t')).$ddl1);
    t("$label: nothing left over", leftovers($root) === [], json_encode(leftovers($root)));
    $r = imp($tmp, $sc['tables'], $sc['overwrite'] === '1');
    t("$label: the next import works", $r['ok'], $r['msg']);
}
fresh($root);
$root->exec('CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50)) ENGINE=InnoDB');
$root->exec("INSERT INTO site_a.app_t VALUES (1,'orig')");
file_put_contents($tmp, "DROP TABLE IF EXISTS `app_t`;\nCREATE TABLE `app_t` (`id` int NOT NULL PRIMARY KEY, `v` varchar(50));\nINSERT INTO `app_t` VALUES (7,'new');\n");
// Killed by a crash that the next visit meets at the start of an import, not on the page.
$out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --crash-child '.escapeshellarg($S).' '.escapeshellarg($tmp).' '.escapeshellarg(json_encode(['app_t' => $both])).' 0 done 2>&1; echo "exit=$?"');
t('killed right after a finished import: the result stands', strpos((string) $out, 'exit=137') !== false && all($root, 'app_t') === [['id' => 7, 'v' => 'new']] && leftovers($root) === [], $out.json_encode(leftovers($root)));
fresh($root);
$root->exec('CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50))');
$root->exec("INSERT INTO site_a.app_t VALUES (1,'orig')");
$root->exec('CREATE TABLE site_a.app_import_state (`tbl` VARCHAR(64) NOT NULL PRIMARY KEY, `copy_name` VARCHAR(64) NOT NULL, `how` VARCHAR(8) NOT NULL, `ready` TINYINT NOT NULL, `definition` LONGTEXT NOT NULL)');
$root->exec("CREATE TABLE site_a.app_t__restore LIKE site_a.app_t");
$root->exec("INSERT INTO site_a.app_t__restore VALUES (1,'partial copy')");
$root->exec("INSERT INTO site_a.app_import_state VALUES ('app_t','app_t__restore','copy',0,'')");
svc()->recoverInterruptedImport();
t('killed while still copying: the table was never touched, the partial copy goes', all($root, 'app_t') === [['id' => 1, 'v' => 'orig']] && leftovers($root) === [], json_encode(all($root, 'app_t')).json_encode(leftovers($root)));
$root->exec('CREATE TABLE site_a.app_import_state (`tbl` VARCHAR(64) NOT NULL PRIMARY KEY, `copy_name` VARCHAR(64) NOT NULL, `how` VARCHAR(8) NOT NULL, `ready` TINYINT NOT NULL, `definition` LONGTEXT NOT NULL)');
$root->exec("CREATE TABLE site_a.app_t__restore LIKE site_a.app_t");
$root->exec("INSERT INTO site_a.app_import_state VALUES ('app_t','app_t__restore','done',1,'')");
$root->exec("UPDATE site_a.app_t SET v='finished'");
svc()->recoverInterruptedImport();
t('killed while removing the copies: the finished import stands', all($root, 'app_t') === [['id' => 1, 'v' => 'finished']] && leftovers($root) === [], json_encode(leftovers($root)));

echo "\n### Which way each table is saved\n";
$spy = function (array $setup, array $modes, $overwrite, string $sql, callable $expect, string $label) use ($root, $tmp, $S) {
    fresh($root);
    foreach ($setup as $s) {
        $root->exec($s);
    }
    file_put_contents($tmp, $sql);
    $s = svc();
    $seen = [];
    $beat = new ReflectionProperty($s, 'nextBeat');
    $s->setHeartbeat(function () use ($root, &$seen, $beat, $s) {
        $beat->setValue($s, 0);
        $has = $root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='site_a' AND TABLE_NAME='app_import_state'")->fetchColumn();
        $seen['state'] = $has ? $root->query('SELECT how FROM site_a.app_import_state')->fetchAll(PDO::FETCH_COLUMN) : ($seen['state'] ?? []);
    });
    $beat->setValue($s, 0);
    $s->importSql($tmp, $modes, array_keys($modes), $overwrite);
    t($label, $expect($seen['state'] ?? []), json_encode($seen));
};
$inno = ['CREATE TABLE site_a.app_t (id INT PRIMARY KEY) ENGINE=InnoDB'];
$spy($inno, ['app_t' => $dataOnly], false, "INSERT INTO `app_t` VALUES (1);\nINSERT INTO `app_t` VALUES (2);\n", static function ($s) {
    return $s === [];
}, 'InnoDB, data only: the transaction does it, no copy');
$spy($inno, ['app_t' => $both], false, "DROP TABLE `app_t`;\nCREATE TABLE `app_t` (`id` int PRIMARY KEY);\nINSERT INTO `app_t` VALUES (1);\n", static function ($s) {
    return $s === ['rename'];
}, 'dropped first: moved aside');
$spy($inno, ['app_t' => $dataOnly], true, "INSERT INTO `app_t` VALUES (1);\n", static function ($s) {
    return $s === ['rename'];
}, 'overwrite: moved aside');
$spy(['CREATE TABLE site_a.app_t (id INT PRIMARY KEY) ENGINE=InnoDB', 'CREATE TABLE site_a.app_u (id INT PRIMARY KEY) ENGINE=InnoDB'], ['app_t' => $dataOnly, 'app_u' => $both], false, "INSERT INTO `app_t` VALUES (1);\nDROP TABLE `app_u`;\nCREATE TABLE `app_u` (`id` int PRIMARY KEY);\n", static function ($s) {
    sort($s);
    return $s === ['copy', 'rename'];
}, 'another table has a DDL: the transaction will not hold, so a copy');

echo "\n### Hardening\n";
$svcObj = (new ReflectionClass(Svc::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($svcObj, 'prefix'))->setValue($svcObj, '');
$meta = new ReflectionMethod($svcObj, 'parseStatementMeta');
foreach (['DROP TABLE `a b`', 'TRUNCATE TABLE `x.y`', 'INSERT INTO `a-b` VALUES (1)', 'DELETE FROM `q!`', 'CREATE TABLE `a b` (id INT)', 'ALTER TABLE `a b` ADD c INT'] as $sql) {
    $type = $meta->invoke($svcObj, $sql)['type'];
    t("an unusual quoted table name is refused, even with no prefix: $sql", $type === 'forbidden', $type);
}
$type = $meta->invoke($svcObj, 'INSERT INTO `t` (`a b`) VALUES (1)')['type'];
t('an unusual quoted column name still passes', $type === 'data', $type);
$type = $meta->invoke($svcObj, 'SET AUTOCOMMIT=0')['type'];
t('a file cannot change autocommit', $type === 'unknown', $type);
class PocGadget2
{
    public static $fired = 0;

    public function __destruct()
    {
        self::$fired++;
    }
}
$out = Kotchasan\ArrayTool::unserialize('O:10:"PocGadget2":0:{}');
gc_collect_cycles();
t('ArrayTool::unserialize revives no object', PocGadget2::$fired === 0, (string) PocGadget2::$fired);
$out = Kotchasan\ArrayTool::unserialize(serialize(['a' => 1, 'b' => [2]]));
t('and still reads a serialized array', $out === ['a' => 1, 'b' => [2]], json_encode($out));
$out = Kotchasan\ArrayTool::unserialize(serialize(['x' => new PocGadget2()]));
$fired = PocGadget2::$fired;
gc_collect_cycles();
t('an object inside an array stays inert too', PocGadget2::$fired === $fired && is_object($out['x']) && get_class($out['x']) === '__PHP_Incomplete_Class');

$base = ROOT_PATH.DATA_FOLDER;
@mkdir($base.'board', 0777, true);
@mkdir(ROOT_PATH.'settings', 0777, true);
$outside = ROOT_PATH.'settings/decoy.php';
$make = static function () use ($base, $outside) {
    file_put_contents($outside, 'x');
    file_put_contents($base.'board/pic.jpg', 'x');
    file_put_contents($base.'board/.htaccess', 'x');
    file_put_contents($base.'top.jpg', 'x');
};
$make();
t('remove: a plain name inside the folder goes', Gcms\DataFile::remove($base.'board/', 'pic.jpg') && !is_file($base.'board/pic.jpg'));
foreach (['../../../../settings/decoy.php', '../top.jpg', '/etc/hostname', 'pic.jpg/../../../../../settings/decoy.php', '.htaccess', '..', '.', ''] as $name) {
    $make();
    Gcms\DataFile::remove($base.'board/', $name);
    clearstatcache();
    t("remove refuses: '$name'", is_file($outside) && is_file($base.'top.jpg') && is_file($base.'board/.htaccess'));
}
@unlink($base.'board/link.jpg');
symlink($outside, $base.'board/link.jpg');
Gcms\DataFile::remove($base.'board/', 'link.jpg');
clearstatcache();
t('remove refuses a link that points outside', is_file($outside));
@unlink($base.'board/link.jpg');
$make();
t('removeStored: a stored path in this site goes', Gcms\DataFile::removeStored(DATA_FOLDER.'board/pic.jpg') && !is_file($base.'board/pic.jpg'));
foreach (['settings/decoy.php', '../settings/decoy.php', DATA_FOLDER.'../../../settings/decoy.php', 'datas/users/other/x.jpg'] as $stored) {
    $make();
    t("removeStored refuses: '$stored'", !Gcms\DataFile::removeStored($stored) && is_file($outside));
}


echo "\n### Files that try to reach outside this site, on a real server\n";
$attacks = [
    'DROP two tables' => "DROP TABLE app_t, site_b.secret;",
    'DROP other prefix via 2nd name' => "DROP TABLE IF EXISTS app_t, other_prefixed;",
    'INSERT..SELECT other db' => "INSERT INTO app_t SELECT id, v FROM site_b.secret;",
    'INSERT VALUES subquery' => "INSERT INTO app_t VALUES (9,(SELECT v FROM site_b.secret LIMIT 1));",
    'CREATE..SELECT other db' => "DROP TABLE app_t; CREATE TABLE app_t\nSELECT * FROM site_b.secret;",
    'CREATE LIKE other db' => "DROP TABLE app_t; CREATE TABLE app_t LIKE site_b.secret;",
    'ALTER RENAME to other db' => "ALTER TABLE app_t RENAME site_b.stolen;",
    'versioned comment' => "/*!50000 DROP TABLE site_b.secret */;",
    'comment-hidden in CREATE' => "DROP TABLE app_t; CREATE TABLE app_t (id INT, v VARCHAR(50)) /*!50000 SELECT * FROM site_b.secret */;",
    'DELETE multi-table' => "DELETE FROM app_t USING app_t, site_b.secret;",
    'SET GLOBAL' => "SET GLOBAL max_connections=5;",
    'SET NAMES gbk' => "SET NAMES gbk;",
    'non-ascii ws' => "DROP TABLE\xC2\xA0site_b.secret;",
    'backtick qualified' => "DROP TABLE `site_b`.`secret`;",
    'INSERT other db' => "INSERT INTO site_b.secret VALUES (2,'x');",
    'FK to other db' => "CREATE TABLE app_x2 (a INT, FOREIGN KEY (a) REFERENCES site_b.secret(id));",
    'bare other-prefix DROP' => "DROP TABLE other_prefixed;",
];
foreach ($attacks as $label => $sql) {
    fresh($root);
    $root->exec('CREATE TABLE site_a.other_prefixed (id INT PRIMARY KEY)');
    $root->exec('INSERT INTO site_a.other_prefixed VALUES (1)');
    $root->exec('CREATE TABLE site_a.app_t (id INT PRIMARY KEY, v VARCHAR(50))');
    $root->exec("INSERT INTO site_a.app_t VALUES (1,'orig')");
    $globalBefore = $root->query('SELECT @@global.max_connections')->fetchColumn();
    importText(str_replace('; ', ";\n", $sql), ['app_t' => $both]);
    $secret = $root->query('SELECT COUNT(*) FROM site_b.secret')->fetchColumn();
    $other = $root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='site_a' AND TABLE_NAME='other_prefixed'")->fetchColumn();
    $stolen = (int) $root->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='site_a' AND TABLE_NAME='app_t' AND COLUMN_NAME='v'")->fetchColumn() === 1
        ? $root->query("SELECT COUNT(*) FROM site_a.app_t WHERE v='top secret'")->fetchColumn() : 0;
    $moved = $root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='site_b' AND TABLE_NAME<>'secret'")->fetchColumn();
    t("attack: $label", (int) $secret === 1 && (int) $other === 1 && (int) $stolen === 0 && (int) $moved === 0 && $root->query('SELECT @@global.max_connections')->fetchColumn() === $globalBefore, "secret=$secret other=$other stolen=$stolen moved=$moved");
}

echo "\n### The statement check\n";
$checker = (new ReflectionClass(Svc::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($checker, 'prefix'))->setValue($checker, 'app');
$checkMeta = new ReflectionMethod($checker, 'parseStatementMeta');
$classify = static function ($sql) use ($checker, $checkMeta) {
    return $checkMeta->invoke($checker, $sql)['type'];
};
$bad = [
    "INSERT INTO app_t VALUES (1,'a') ON DUPLICATE KEY UPDATE v=(SELECT v FROM site_b.secret)",
    "INSERT INTO app_t VALUES (1,'\\\\'), (2,(SELECT v FROM site_b.secret))",
    "INSERT INTO app_t VALUES (1,\"'\"), (2,(SELECT v FROM site_b.secret))",
    "INSERT INTO app_t VALUES (1,''''), (2,(SELECT v FROM site_b.secret))",
    "INSERT INTO app_t VALUES (1,'a'); DROP TABLE site_b.secret",
    "INSERT INTO app_t (`'`) VALUES (1); DROP TABLE site_b.secret; -- '",
    "INSERT INTO app_t VALUES (load_file('/etc/passwd'))",
    "INSERT INTO app_t VALUES (1,@@datadir)",
    "INSERT INTO app_t VALUES (sleep(5))",
    "INSERT INTO app_t VALUES (1)/*!,(2,(SELECT 1))*/",
    "INSERT INTO app_t VALUES (1)#\n,(2,(SELECT 1))",
    "INSERT INTO app_t VALUES (1)-- x\n,(2,(SELECT 1))",
    "INSERT INTO `site_b`.`secret` VALUES (1)",
    "INSERT INTO site_b . secret VALUES (1)",
    "REPLACE INTO site_b.secret VALUES (1)",
    "TRUNCATE TABLE site_b.secret",
    "DELETE FROM site_b.secret",
    "DELETE FROM app_t WHERE id IN (SELECT id FROM site_b.secret)",
    "DROP TABLE app_t,site_b.secret",
    "DROP TABLE site_b . secret",
    "CREATE TABLE app_z (id INT) ENGINE=MERGE UNION=(site_b.secret)",
    "CREATE TABLE app_z (id INT) ENGINE=FEDERATED CONNECTION='mysql://x/y/z'",
    "CREATE TABLE app_z (id INT) ENGINE=CONNECT",
    "CREATE TABLE app_z (id INT) DATA DIRECTORY='/tmp'",
    "CREATE TABLE app_z (id INT) AS SELECT * FROM site_b.secret",
    "CREATE TABLE app_z (id INT) TABLE site_b.secret",
    "CREATE TABLE app_z (id INT) PARTITION BY HASH(id)",
    "CREATE TABLE app_z (LIKE site_b.secret)",
    "CREATE TABLE app_z (id INT, v INT DEFAULT (SELECT 1))",
    "CREATE TABLE app_z (id INT, v TEXT GENERATED ALWAYS AS (load_file('/etc/passwd')))",
    "CREATE TABLE app_z (id INT, FOREIGN KEY (id) REFERENCES `site_b`.`secret`(id))",
    "CREATE TABLE app_z (id INT, FOREIGN KEY (id) REFERENCES other_t(id))",
    "ALTER TABLE app_t RENAME TO site_b.stolen",
    "ALTER TABLE app_t RENAME other_t",
    "ALTER TABLE app_t EXCHANGE PARTITION p WITH TABLE site_b.secret",
    "ALTER TABLE app_t DISCARD TABLESPACE",
    "ALTER TABLE app_t ENGINE=CONNECT",
    "ALTER TABLE app_t UNION=(site_b.secret)",
    "ALTER TABLE app_t ADD FOREIGN KEY (id) REFERENCES site_b.secret(id)",
    "CREATE\tTABLE\napp_z\x0b(id INT)\x0cSELECT 1",
    "SET GLOBAL general_log=1",
    "SET @@global.general_log=1",
    "SET PASSWORD = PASSWORD('x')",
    "SET NAMES gbk",
    "SET NAMES 'gbk'",
    "SET CHARACTER SET gbk",
    "SET character_set_client=gbk",
    "SET sql_mode='NO_BACKSLASH_ESCAPES'",
    "SET SESSION sql_mode='ANSI_QUOTES'",
    "SET FOREIGN_KEY_CHECKS=0, GLOBAL general_log=1",
    "SET @x=(SELECT v FROM site_b.secret)",
    "GRANT ALL ON *.* TO x",
    "CREATE USER x",
    "SELECT * FROM site_b.secret INTO OUTFILE '/tmp/x'",
    "LOAD DATA INFILE '/etc/passwd' INTO TABLE app_t",
    "CALL evil()",
    "PREPARE s FROM 'DROP TABLE site_b.secret'",
    "/*!50000 DROP TABLE site_b.secret */",
    "DO SLEEP(5)",
    "RENAME TABLE site_b.secret TO app_x",
    "DROP DATABASE site_b",
    "DROP TEMPORARY TABLE app_t",
    "INSERT IGNORE INTO site_b.secret VALUES (1)",
    "INSERT LOW_PRIORITY INTO site_b.secret VALUES (1)",
    "INSERT INTO app_t SET id=(SELECT 1)",
    "UPDATE site_b.secret SET v=1",
    "HANDLER site_b.secret OPEN",
    "-- x\nDROP TABLE site_b.secret",
    "# x\nDROP TABLE site_b.secret, app_t",
];
foreach ($bad as $sql) {
    t('never runs: '.substr(preg_replace('/\s+/', ' ', $sql), 0, 90), in_array($classify($sql), ['forbidden', 'unknown'], true), $classify($sql));
}
$good = [
    "SET NAMES utf8mb4;",
    "SET FOREIGN_KEY_CHECKS=0;",
    "SET time_zone = \"+00:00\";",
    "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";",
    "DROP TABLE IF EXISTS `app_t`;",
    "INSERT INTO `app_t` (`id`,`v`) VALUES (1,'it\\'s; -- x'),(2,NULL),(-3,1.5e3),(4,0xAB),(5,_binary 'x'),(6,b'0'),(7,x'41'),(8,DEFAULT);",
    "INSERT INTO `app_t` VALUES (1,'ไทย /* c */ SELECT');",
    "REPLACE INTO `app_t` VALUES (1);",
    "CREATE TABLE `app_a` (`id` int NOT NULL AUTO_INCREMENT, `like` int, `sel.ect` int, `j` json DEFAULT NULL, `g` int GENERATED ALWAYS AS (`id` * 2) VIRTUAL, `d` decimal(10,2) DEFAULT 0.00, `f` double DEFAULT 1.5, `ts` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `e` enum('a','b;c') DEFAULT 'a', PRIMARY KEY (`id`), KEY `k` (`d`,`f`), FULLTEXT KEY `ft` (`e`), CONSTRAINT `c1` CHECK ((`id` > 0))) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC COMMENT='ไทย; x';",
    "ALTER TABLE `app_t` ADD PRIMARY KEY (`id`), ADD KEY `k` (`v`);",
    "ALTER TABLE `app_t` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;",
    "ALTER TABLE `app_c` ADD CONSTRAINT `fk` FOREIGN KEY (`p`) REFERENCES `app_p` (`id`) ON DELETE CASCADE;",
];
foreach ($good as $sql) {
    t('still runs: '.substr(preg_replace('/\s+/', ' ', $sql), 0, 90), !in_array($classify($sql), ['forbidden', 'unknown'], true), $classify($sql));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
