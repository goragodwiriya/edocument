<?php
/**
 * modules/edocument/tests/run.php
 *
 * ชุดทดสอบ end-to-end ของโมดูล edocument (งานสารบรรณ)
 *   1. สร้างฐานข้อมูลทดสอบด้วยสคีมาและข้อมูลของ "ระบบเดิม" (fixtures/legacy_*.sql)
 *   2. คัดลอกโปรเจ็คไปที่โฟลเดอร์ชั่วคราว แล้วรัน install/upgrade2.php ของจริง
 *      ผ่าน install/cli-upgrade.php สองรอบ (รอบสองต้องไม่แก้ตารางของโมดูลอีก)
 *   3. เทียบตารางที่ปรับรุ่นแล้วกับตารางจาก modules/edocument/install/database.sql (ติดตั้งใหม่)
 *   4. เรียก Controller ทุกตัวตรง ๆ (test_*.php ในโฟลเดอร์นี้)
 *
 * วิธีใช้ (จากรากโปรเจ็ค)   php modules/edocument/tests/run.php [ชื่อชุด ...]
 *   EDOC_KEEP=1 php modules/edocument/tests/run.php none   เตรียมฐาน+สำเนาโปรเจ็คไว้ทดสอบหน้าจอ
 *
 * ตัวแปรแวดล้อม
 *   EDOC_TEST_DB   ชื่อฐานข้อมูลทดสอบ (ค่าปริยาย edocument_module_test)
 *   EDOC_KEEP      ไม่ลบฐานข้อมูลและสำเนาโปรเจ็คเมื่อจบ
 *   EDOC_VERBOSE   แสดงผลของทุกชุด
 *
 * ชุดทดสอบสร้างและลบฐานข้อมูลของตัวเอง ไม่แตะฐานข้อมูลจริงและไฟล์ตั้งค่าของโปรเจ็ค
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$projectRoot = realpath(__DIR__.'/../../..');
$testsDir = __DIR__;
$testDb = getenv('EDOC_TEST_DB') ?: 'edocument_module_test';
$dbConfig = include $projectRoot.'/settings/database.php';
$mysql = $dbConfig['mysql'];
if ($testDb === $mysql['dbname']) {
    fwrite(STDERR, "ชื่อฐานทดสอบซ้ำกับฐานข้อมูลจริง กรุณากำหนด EDOC_TEST_DB ให้ต่างออกไป\n");
    exit(2);
}
$pdo = new PDO('mysql:host='.$mysql['hostname'].';port='.$mysql['port'].';charset=utf8mb4', $mysql['username'], $mysql['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/**
 * รันไฟล์ SQL (แบบ phpMyAdmin dump) ทีละคำสั่ง
 */
$runSql = function ($file, $prefix) use ($pdo) {
    $sql = str_replace('{prefix}', $prefix, file_get_contents($file));
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
        if ($statement !== '' && stripos($statement, 'SET ') !== 0) {
            $pdo->exec($statement);
        }
    }
};

echo "1) สร้างฐานข้อมูลทดสอบ $testDb จากสคีมาของระบบเดิม\n";
$pdo->exec("DROP DATABASE IF EXISTS `$testDb`");
// ระบบเดิมเป็น utf8 (utf8mb3) ตั้งใจให้ตรงของจริง เพื่อทดสอบการแปลง charset ด้วย
$pdo->exec("CREATE DATABASE `$testDb` DEFAULT CHARACTER SET utf8");
$pdo->exec("USE `$testDb`");
$runSql($testsDir.'/fixtures/legacy_schema.sql', $mysql['prefix']);
$runSql($testsDir.'/fixtures/legacy_data.sql', $mysql['prefix']);

echo "2) เตรียมสำเนาโปรเจ็คและรัน install/upgrade2.php ของจริง\n";
$sandbox = sys_get_temp_dir().'/edocument-tests-'.getmypid();
exec('rm -rf '.escapeshellarg($sandbox));
mkdir($sandbox, 0777, true);
exec('tar -cf - --exclude=./.git --exclude=./node_modules --exclude=./datas --exclude=./settings -C '.escapeshellarg($projectRoot).' . | tar -xf - -C '.escapeshellarg($sandbox));
foreach (['settings', 'datas/cache', 'datas/logs', 'datas/edocument'] as $dir) {
    mkdir($sandbox.'/'.$dir, 0777, true);
}
$sandboxDb = $dbConfig;
$sandboxDb['mysql']['dbname'] = $testDb;
file_put_contents($sandbox.'/settings/database.php', '<'."?php\n/* database.php */\nreturn ".var_export($sandboxDb, true).';');
copy($testsDir.'/fixtures/legacy_config.php', $sandbox.'/settings/config.php');
// ไฟล์เอกสารของระบบเดิม
file_put_contents($sandbox.'/datas/edocument/1545666283.pdf', "%PDF-1.4 legacy 1545666283\n");
file_put_contents($sandbox.'/datas/edocument/1545662500.jpg', 'JPEGDATA');
file_put_contents($sandbox.'/datas/edocument/1700000000.pdf', "%PDF-1.4 legacy 1700000000\n");
$upgrade = function () use ($sandbox) {
    $output = [];
    exec('cd '.escapeshellarg($sandbox).' && php install/cli-upgrade.php admin secret123 --confirm-backup 2>&1', $output);

    return $output;
};
$first = $upgrade();
$firstText = implode("\n", $first);
$upgradeOk = strpos($firstText, '[FAIL]') === false && strpos($firstText, 'ไม่สำเร็จ') === false
    && preg_match('/edocument อัปเกรดสำเร็จ/u', $firstText) && preg_match('/edocument_download อัปเกรดสำเร็จ/u', $firstText);
echo $upgradeOk ? "   ตัวปรับรุ่นทำงานสำเร็จ\n" : "   ตัวปรับรุ่นล้มเหลว\n$firstText\n";
if (getenv('EDOC_VERBOSE')) {
    echo '   '.implode("\n   ", preg_grep('/edocument|number/u', $first)), "\n";
}
// รอบสองต้องผ่านและไม่แก้ตารางของโมดูลอีก (เหลือเพียงบรรทัด "อัปเกรดสำเร็จ")
$again = $upgrade();
$changes = array_filter($again, function ($line) {
    return preg_match('/(edocument[a-z_]*: |number: ย้าย)/u', $line);
});
$idempotent = strpos(implode("\n", $again), '[FAIL]') === false && empty($changes);
echo $idempotent ? "   รันตัวปรับรุ่นซ้ำ ไม่มีการแก้ซ้ำ\n" : "   รันตัวปรับรุ่นซ้ำแล้วยังแก้ตารางอีก\n".implode("\n", $changes)."\n";

echo "3) เทียบตารางที่ปรับรุ่นกับตารางที่ติดตั้งใหม่\n";
$runSql($projectRoot.'/modules/edocument/install/database.sql', 'fresh');
$schemaOk = true;
foreach (['edocument', 'edocument_download'] as $table) {
    $normalize = function ($name) use ($pdo) {
        $create = $pdo->query("SHOW CREATE TABLE `$name`")->fetch(PDO::FETCH_NUM)[1];
        $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);

        return str_replace("`$name`", '`T`', $create);
    };
    $upgraded = $normalize($mysql['prefix'].'_'.$table);
    $fresh = $normalize('fresh_'.$table);
    if ($upgraded === $fresh) {
        echo "   $table ตรงกับติดตั้งใหม่\n";
    } else {
        $schemaOk = false;
        echo "   $table ไม่ตรงกับติดตั้งใหม่\n--- ปรับรุ่น\n$upgraded\n--- ติดตั้งใหม่\n$fresh\n";
    }
    $pdo->exec("DROP TABLE `fresh_$table`");
}

echo "4) ทดสอบโมดูล\n";
$pass = ($upgradeOk ? 1 : 0) + ($idempotent ? 1 : 0) + ($schemaOk ? 1 : 0);
$fail = 3 - $pass;
foreach (glob($testsDir.'/test_*.php') ?: [] as $file) {
    $name = basename($file);
    // ระบุชื่อชุดได้ (php run.php test_edocument) · ชื่อที่ไม่มีอยู่ เช่น none = เตรียมฐานอย่างเดียว (ใช้กับ EDOC_KEEP)
    if (count($argv) > 1 && !in_array(basename($file, '.php'), array_slice($argv, 1), true)) {
        continue;
    }
    $output = [];
    exec('EDOC_ROOT='.escapeshellarg($sandbox).' EDOC_BOOT='.escapeshellarg($sandbox.'/modules/edocument/tests/bootstrap.php')
        .' php '.escapeshellarg($sandbox.'/modules/edocument/tests/'.$name).' 2>&1', $output);
    $text = implode("\n", $output);
    if (preg_match('/ผ่าน (\d+) \/ ล้มเหลว (\d+)/u', $text, $m)) {
        $pass += (int) $m[1];
        $fail += (int) $m[2];
        printf("   %-24s ผ่าน %3d / ล้มเหลว %d\n", $name, $m[1], $m[2]);
        if (getenv('EDOC_VERBOSE') || (int) $m[2] > 0) {
            echo $text, "\n";
        }
    } else {
        $fail++;
        printf("   %-24s รันไม่ได้\n%s\n", $name, $text);
    }
}

if (getenv('EDOC_KEEP')) {
    echo "เก็บสำเนาโปรเจ็คไว้ที่ $sandbox และฐาน $testDb\n";
} else {
    exec('rm -rf '.escapeshellarg($sandbox));
    $pdo->exec("DROP DATABASE IF EXISTS `$testDb`");
}
echo str_repeat('=', 50), "\n";
printf("รวม: ผ่าน %d / ล้มเหลว %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
