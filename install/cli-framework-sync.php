<?php
/**
 * install/cli-framework-sync.php — ตรวจและบังคับให้ Now/ Kotchasan/ vite.config.js
 * และซอร์สตัวแก้ไขข้อความ js/components/editor/ ของทุกโปรเจ็คลูกเหมือน adminframework 100%
 *
 * js/components/editor/ build เป็น Now/dist/richtext-editor.min.js แต่อยู่นอก Now/ — เดิมไม่ถูกกระจาย
 * โปรเจ็คลูกจึงได้ bundle ใหม่แต่ซอร์สเก่า (build editor ในลูกจะได้ตัวเก่ากลับมา) · รับเฉพาะโปรเจ็คที่มี
 * โฟลเดอร์นี้อยู่แล้ว · ไฟล์ที่แต่ละโปรเจ็คตั้งค่าเอง ($perProject) ไม่ถูกเทียบและไม่ถูกแตะ
 *
 * ไฟล์ระดับบนของ js/components/ และ js/global.js ($sharedCopies) เป็นของแอปที่แต่ละโปรเจ็คแก้เองได้ จึง sync เฉพาะไฟล์ที่
 * โปรเจ็คยังเป็นสำเนารุ่นใดรุ่นหนึ่งของต้นฉบับ (เนื้อหาเคยอยู่ในประวัติ git ของ adminframework) · ไฟล์ที่
 * โปรเจ็คไม่มีไม่เพิ่มให้ · ไฟล์ที่โปรเจ็คแก้เองไม่แตะ รายงานเป็น "ของโปรเจ็ค" (ไม่ข้ามโปรเจ็ค ไม่ทำให้ exit 1)
 *
 * ใช้:  php install/cli-framework-sync.php [--apply] [--force] [โปรเจ็ค ...]
 *   (ไม่ใส่อะไร)  รายงานว่าโปรเจ็คไหนต่างจากต้นฉบับตรงไหน — ไม่แก้ไฟล์
 *   --apply       คัดลอกต้นฉบับทับโปรเจ็คที่ต่าง (ข้ามโปรเจ็คที่มีไฟล์เกินหรือไฟล์ที่แก้ในโปรเจ็ค)
 *   --force       ใช้กับ --apply: ทับและลบแม้มีไฟล์เกินหรือไฟล์ที่แก้ในโปรเจ็ค
 *   โปรเจ็ค       ชื่อโฟลเดอร์ใต้ now.js/ (ไม่ใส่ = ทุกโปรเจ็คที่มีทั้ง Now/ และ Kotchasan/ รวม nowjs
 *                 และ repo ทางการของ Kotchasan ที่ now.js/kotchasan ซึ่งรับเฉพาะ Kotchasan/)
 *
 * ⚠️ เครื่องมือนี้เป็นของ "ฝั่งเรา" รันได้จาก adminframework เท่านั้น
 *
 * Now/ และ Kotchasan/ เป็นของกลาง แก้ที่ adminframework เท่านั้นแล้วกระจายด้วยไฟล์นี้
 * (ไฟล์ที่แก้ในโปรเจ็คอื่น รวม nowjs ถูกรายงานเป็น "แก้ในโปรเจ็ค" ให้นำเข้า adminframework ก่อน)
 * ของที่เป็นของโปรเจ็คเดียวต้องไม่อยู่ในสองโฟลเดอร์นี้:
 *   - bundle ของโปรเจ็ค → js/components/<ชื่อ>/ + build.json แล้ว build ลง js/dist/
 *     (vite.config.js ที่เหมือนกันทุกโปรเจ็คหาเองจาก BUILD_TARGET=<ชื่อ>)
 *   - ชุดทดสอบของ Kotchasan → tests/Kotchasan/ ของโปรเจ็ค
 *
 * ไฟล์ที่ถูกทับหรือลบถูกเก็บไว้ที่ now.js/backup/framework-sync/<เวลา>/<โปรเจ็ค>/ เสมอ
 * (โปรเจ็คที่ไม่มี git กู้คืนได้จากที่นี่)
 *
 * แยกไฟล์ด้วยประวัติ git ของ adminframework เท่านั้น (ประวัติของโปรเจ็คลูกไม่นับ
 * ไม่อย่างนั้นงานที่ commit ไว้ในลูกจะถูกมองเป็นรุ่นเก่าของต้นฉบับแล้วโดนทับ):
 *   - ไฟล์ที่ต่างแต่เนื้อหาเคยอยู่ในประวัติ = รุ่นเก่า ทับได้
 *   - ไฟล์ใน Now/dist ที่ต่าง = ผล build ด้วย vite รุ่นอื่น ทับได้เสมอ (build core ที่ adminframework เท่านั้น)
 *   - ไฟล์ที่ต้นฉบับไม่มีแล้วแต่เคยมี = ต้นฉบับลบแล้ว ลบได้
 *   - ไฟล์ที่ต่าง แก้ทีหลังต้นฉบับ (mtime ใหม่กว่า) และไม่เคยอยู่ในประวัติ = งานที่ยังไม่ได้ backport
 *   - ไฟล์ที่ต้นฉบับไม่เคยมี = ของโปรเจ็คนี้
 * สองแบบหลัง --apply จะข้ามโปรเจ็คนั้นจนกว่าจะย้าย/นำเข้า adminframework แล้ว หรือยืนยันด้วย --force
 *
 * รายงานเพิ่ม (ไม่ข้ามโปรเจ็คตอน --apply และไม่แก้ให้ แต่ทำให้ exit เป็น 1):
 *   - ไม่อยู่ใน git = ไฟล์ของต้นฉบับที่อยู่บนดิสก์ของโปรเจ็คแต่ git ของโปรเจ็คไม่ได้ติดตาม
 *     ดิสก์ครบแต่ใครที่ deploy จาก git จะได้ชุดที่ขาด · ไฟล์ที่ .gitignore ไว้ไม่นับ
 *   - script ที่ build Now.js = script ใน package.json ของโปรเจ็คที่สั่ง vite build ของ Now.js
 *     build core ล้าง Now/dist ทั้งโฟลเดอร์ (emptyOutDir) แล้วโปรเจ็คลูกไม่มีอะไรสร้าง feature bundle คืน
 *     บันเดิลของ Now.js build ที่ adminframework เท่านั้น ลูกมีได้เฉพาะ script ของบันเดิลตัวเอง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$base = dirname(__DIR__);
if (basename($base) !== 'adminframework') {
    fwrite(STDERR, "รันได้จาก adminframework เท่านั้น (ที่นี่คือ $base)\n");
    exit(1);
}
$root = dirname($base);
$items = ['Now', 'Kotchasan', 'vite.config.js', 'js/components/editor'];
// ไฟล์ของแอปที่แต่ละโปรเจ็คมีสำเนาของตัวเอง (โฟลเดอร์ = ไฟล์ระดับบนสุด ไม่รวมโฟลเดอร์ย่อย):
// ไฟล์ที่โปรเจ็คยังเป็นรุ่นใดรุ่นหนึ่งของต้นฉบับ (เคยอยู่ในประวัติ git ของ adminframework ที่ path เดียวกัน)
// ถูกทับด้วยรุ่นปัจจุบัน · ไฟล์ที่โปรเจ็คไม่มีไม่เพิ่มให้ · ไฟล์ที่โปรเจ็คแก้เองไม่แตะ แค่รายงานเป็น "ของโปรเจ็ค"
// js/components/EventCalendar.js กับ SyntaxHighlighterComponent.js เป็นซอร์สของบันเดิลใน Now/dist ด้วย
// js/global.js = formatter และ init function ของตาราง/หน้าที่แอปใช้ร่วมกัน (tro มีของตัวเอง nowjs ไม่มี)
$sharedCopies = ['js/components', 'js/global.js'];
// ส่งให้เฉพาะโปรเจ็คที่มีอยู่แล้ว
$optionalItems = ['js/components/editor'];
// ของแต่ละโปรเจ็ค: ไม่เทียบ ไม่ทับ ไม่ลบ (path ที่ลงท้ายด้วย / = ทั้งโฟลเดอร์)
$perProject = [
    'js/components/editor/php/config.php',
    'js/components/editor/php/filebrowser.php',
    'js/components/editor/php/controllers/',
    'js/components/editor/plugins/filebrowser/filebrowser.css'
];
// repo ทางการของ Kotchasan (แพ็กเกจ Composer) ไม่มี Now/ — รับเฉพาะ Kotchasan/ ทุกครั้งที่ Kotchasan เปลี่ยน
$package = 'kotchasan';

/**
 * สิ่งที่โฟลเดอร์ $name ใต้ now.js/ ต้องได้รับ หรือ null ถ้าไม่ใช่เป้าหมาย
 */
function targetItems($root, $name, $items, $package)
{
    global $optionalItems;
    if (is_dir("$root/$name/Now") && is_dir("$root/$name/Kotchasan")) {
        return array_values(array_filter($items, function ($item) use ($root, $name, $optionalItems) {
            return !in_array($item, $optionalItems, true) || file_exists("$root/$name/$item");
        }));
    }
    return $name === $package && is_dir("$root/$name/Kotchasan") ? ['Kotchasan'] : null;
}

$options = ['apply' => false, 'force' => false];
// [โปรเจ็ค => รายการที่ sync]
$projects = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $options['apply'] = true;
    } elseif ($arg === '--force') {
        $options['force'] = true;
    } elseif ($arg[0] === '-') {
        fwrite(STDERR, "ไม่รู้จักตัวเลือก $arg\n");
        exit(1);
    } elseif (($targetItems = targetItems($root, $arg, $items, $package)) === null) {
        fwrite(STDERR, "ไม่พบโปรเจ็ค $arg (ต้องมีทั้ง Now/ และ Kotchasan/ หรือเป็น $package/ ที่มี Kotchasan/)\n");
        exit(1);
    } else {
        $projects[$arg] = $targetItems;
    }
}
if (empty($projects)) {
    foreach (scandir($root) as $name) {
        // สำเนาที่ตัวจัดการไฟล์สร้าง ("x (สำเนา)") มีช่องว่างในชื่อเสมอ
        if ($name[0] === '.' || $name === 'backup' || basename($base) === $name || preg_match('/\s/u', $name)) {
            continue;
        }
        if (($targetItems = targetItems($root, $name, $items, $package)) !== null) {
            $projects[$name] = $targetItems;
        }
    }
}

/**
 * $rel เป็นไฟล์ที่แต่ละโปรเจ็คตั้งค่าเอง ($perProject) หรือไม่
 */
function perProjectFile($rel)
{
    global $perProject;
    foreach ($perProject as $own) {
        if ($rel === $own || (substr($own, -1) === '/' && strpos($rel, $own) === 0)) {
            return true;
        }
    }
    return false;
}

/**
 * ไฟล์ทั้งหมดใต้ $path เป็น [ชื่อสัมพัทธ์ => path จริง]
 * $path เป็นไฟล์เดี่ยวก็ได้ (คืนชื่อนั้นตัวเดียว)
 */
function listFiles($path, $prefix)
{
    if (is_file($path)) {
        return [$prefix => $path];
    }
    $files = [];
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                function ($file) {
                    return !in_array($file->getFilename(), ['node_modules', '.git'], true);
                }
            )
        );
        foreach ($iterator as $file) {
            $rel = $prefix.'/'.substr($file->getPathname(), strlen($path) + 1);
            if (!perProjectFile($rel)) {
                $files[$rel] = $file->getPathname();
            }
        }
    }
    ksort($files);
    return $files;
}

/**
 * เนื้อหาไฟล์นี้เคยเป็นของต้นฉบับที่ path เดียวกันหรือไม่ (ค้นในประวัติ git ของ $repos)
 * ใช้แยกไฟล์รุ่นเก่า/ไฟล์ที่ต้นฉบับลบไปแล้ว ออกจากงานที่ทำในโปรเจ็คนั้นเอง
 */
function knownInHistory($repos, $rel, $file)
{
    $hash = trim((string) shell_exec('git hash-object '.escapeshellarg($file).' 2>/dev/null'));
    if ($hash === '') {
        return false;
    }
    foreach ($repos as $repo) {
        $found = shell_exec('git -C '.escapeshellarg($repo).' log --all --format=%h -1 --find-object='.$hash.' -- '.escapeshellarg($rel).' 2>/dev/null');
        if (trim((string) $found) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * ไฟล์ของ $sharedCopies ที่ต้นฉบับและ $target มีทั้งคู่แต่ไม่ตรงกัน
 * คืน ['differ' => สำเนารุ่นเก่าของต้นฉบับ (ทับได้), 'own' => ไฟล์ที่โปรเจ็คแก้เอง (ไม่แตะ)]
 */
function compareShared($base, $target, $sharedCopies, $repos)
{
    $result = ['differ' => [], 'own' => []];
    foreach ($sharedCopies as $entry) {
        $files = is_file("$base/$entry") ? [$entry] : array_map(function ($file) use ($entry) {
            return "$entry/".basename($file);
        }, array_filter(glob("$base/$entry/*") ?: [], 'is_file'));
        foreach ($files as $rel) {
            $file = "$base/$rel";
            $copy = "$target/$rel";
            if (!is_file($copy) || (filesize($file) === filesize($copy) && md5_file($file) === md5_file($copy))) {
                continue;
            }
            $result[knownInHistory($repos, $rel, $copy) ? 'differ' : 'own'][] = $rel;
        }
    }
    sort($result['differ']);
    sort($result['own']);
    return $result;
}

/**
 * ไฟล์ใต้ $items ที่อยู่บนดิสก์ของ $target แต่ git ของ $target ไม่ได้ติดตามและไม่ได้ .gitignore ไว้
 * คืน [ชื่อสัมพัทธ์ => true] · $target ที่ไม่ได้เป็นรากของ git repo ของตัวเองคืนรายการว่าง
 */
function untrackedFiles($target, $items)
{
    $git = 'git -C '.escapeshellarg($target);
    $top = trim((string) shell_exec("$git rev-parse --show-toplevel 2>/dev/null"));
    if ($top === '' || realpath($top) !== realpath($target)) {
        return [];
    }
    $output = (string) shell_exec("$git ls-files --others --exclude-standard -z -- ".implode(' ', array_map('escapeshellarg', $items)).' 2>/dev/null');
    $files = [];
    foreach (explode("\0", $output) as $rel) {
        if ($rel !== '') {
            $files[$rel] = true;
        }
    }
    return $files;
}

/**
 * script ใน package.json ของ $target ที่ build บันเดิลของ Now.js คืน [ชื่อ script => [เป้าหมาย, ...]]
 * นับทุก vite build ในคำสั่ง (ต่อกันด้วย && || ;) · vite build ที่ไม่ระบุ BUILD_TARGET คือ core
 * เป้าหมายที่มี js/components/<ชื่อ>/build.json เป็นบันเดิลของโปรเจ็ค (ลง js/dist) ไม่นับ
 */
function frameworkBuildScripts($target)
{
    $package = is_file("$target/package.json") ? json_decode(file_get_contents("$target/package.json"), true) : null;
    $found = [];
    foreach ((array) ($package['scripts'] ?? []) as $name => $command) {
        foreach (preg_split('/&&|\|\||;/', (string) $command) as $part) {
            if (!preg_match('/\bvite\s+build\b/', $part)) {
                continue;
            }
            $buildTarget = preg_match('/\bBUILD_TARGET=["\']?([\w.-]+)/', $part, $match) ? $match[1] : 'core';
            if (!is_file("$target/js/components/$buildTarget/build.json")) {
                $found[$name][] = $buildTarget;
            }
        }
    }
    return $found;
}

/**
 * เทียบโปรเจ็คกับต้นฉบับ คืน ['missing', 'differ', 'local', 'extra', 'removed', 'untracked'] (แต่ละช่องเป็นรายการไฟล์)
 * local     = ไฟล์ที่ต่าง แก้ทีหลังต้นฉบับ และไม่เคยมีในประวัติต้นฉบับ (น่าจะเป็นงานของโปรเจ็คนี้ อยู่ใน differ ด้วย)
 * extra     = ไฟล์ที่ต้นฉบับไม่มีและไม่เคยมี (ของโปรเจ็คนี้)
 * removed   = ไฟล์ที่ต้นฉบับเคยมีแล้วลบไป (ลบได้)
 * untracked = ไฟล์ของต้นฉบับที่อยู่บนดิสก์ของโปรเจ็คแต่ git ของโปรเจ็คไม่ได้ติดตาม (deploy จาก git จะขาด)
 */
function compareProject($base, $target, $items, $repos)
{
    $result = ['missing' => [], 'differ' => [], 'local' => [], 'extra' => [], 'removed' => [], 'untracked' => []];
    $untracked = untrackedFiles($target, $items);
    foreach ($items as $item) {
        $source = listFiles("$base/$item", $item);
        $copy = listFiles("$target/$item", $item);
        foreach (array_intersect_key($copy, $source, $untracked) as $rel => $file) {
            $result['untracked'][] = $rel;
        }
        foreach ($source as $rel => $file) {
            if (!isset($copy[$rel])) {
                $result['missing'][] = $rel;
            } elseif (filesize($file) !== filesize($copy[$rel]) || md5_file($file) !== md5_file($copy[$rel])) {
                $result['differ'][] = $rel;
                // Now/dist เป็นผล build: npm run build ในโปรเจ็คลูกได้ไฟล์ต่างจากต้นฉบับเพราะรุ่นของ
                // vite/rolldown ต่างกัน ไม่ใช่งานของโปรเจ็ค ซอร์สตัวจริงอยู่ใน Now/js ซึ่งตรวจแยกอยู่แล้ว
                if (strpos($rel, 'Now/dist/') !== 0 && filemtime($copy[$rel]) > filemtime($file) && !knownInHistory($repos, $rel, $copy[$rel])) {
                    $result['local'][] = $rel;
                }
            }
        }
        foreach (array_diff_key($copy, $source) as $rel => $file) {
            $result[knownInHistory($repos, $rel, $file) ? 'removed' : 'extra'][] = $rel;
        }
    }
    return $result;
}

/**
 * พิมพ์รายการไฟล์ไม่เกิน 15 บรรทัดต่อหัวข้อ
 */
function printFiles($label, $files)
{
    foreach (array_slice($files, 0, 15) as $rel) {
        echo "        $label  $rel\n";
    }
    if (count($files) > 15) {
        echo '        '.$label.'  ... อีก '.(count($files) - 15)." ไฟล์\n";
    }
}

/**
 * ย้ายไฟล์เดิมไปที่สำรองก่อนทับหรือลบ
 */
function backupFile($file, $backup)
{
    if (!is_dir(dirname($backup))) {
        mkdir(dirname($backup), 0777, true);
    }
    copy($file, $backup);
}

// ประวัติที่ใช้ตัดสินว่าไฟล์ที่ต่าง/เกินเคยเป็นของต้นฉบับหรือไม่
$repos = is_dir("$base/.git") ? [$base] : [];
// ชื่อโฟลเดอร์สำรองเป็นเวลาท้องถิ่นตาม settings/config.php (CLI ไม่ได้โหลด Kotchasan จึงไม่ถูกตั้งให้)
$config = is_file("$base/settings/config.php") ? include "$base/settings/config.php" : [];
date_default_timezone_set(empty($config['timezone']) ? 'Asia/Bangkok' : $config['timezone']);
$stamp = date('Ymd-His');
/**
 * พิมพ์รายงานเพิ่ม (ไม่อยู่ใน git, script ที่ build Now.js) พร้อมวิธีแก้
 */
function printWarnings($diff)
{
    printFiles('ของโปรเจ็ค', $diff['own']);
    printFiles('ไม่อยู่ใน git', $diff['untracked']);
    foreach ($diff['scripts'] as $name => $buildTargets) {
        echo "        build Now.js  \"$name\" (".implode(', ', $buildTargets).")\n";
    }
    if (!empty($diff['untracked'])) {
        echo "        → git ของโปรเจ็คนี้ไม่มีไฟล์เหล่านี้ ใครที่ deploy จาก git จะได้ชุดที่ขาด: git add แล้ว commit ในโปรเจ็คนั้น\n";
    }
    if (!empty($diff['scripts'])) {
        echo "        → บันเดิลของ Now.js build ที่ adminframework เท่านั้น (build core ล้าง Now/dist): เอา script เหล่านี้ออกจาก\n";
        echo "          package.json ของโปรเจ็ค ให้ \"build\" เรียกเฉพาะบันเดิลของโปรเจ็คเอง (js/components/<ชื่อ>/build.json)\n";
    }
}

$failed = 0;
foreach ($projects as $project => $projectItems) {
    $target = "$root/$project";
    $diff = compareProject($base, $target, $projectItems, $repos);
    // repo ทางการของ Kotchasan ไม่ได้รับ vite.config.js จึงไม่มี build ของ Now.js ให้ตรวจ
    $diff['scripts'] = in_array('vite.config.js', $projectItems, true) ? frameworkBuildScripts($target) : [];
    // ไฟล์ของแอป (repo ของ Kotchasan ไม่มี) — สำเนารุ่นเก่าทับเหมือนไฟล์ที่ต่าง ไฟล์ที่โปรเจ็คแก้เองรายงานเฉย ๆ
    $shared = in_array('Now', $projectItems, true) ? compareShared($base, $target, $sharedCopies, $repos) : ['differ' => [], 'own' => []];
    $diff['differ'] = array_merge($diff['differ'], $shared['differ']);
    $diff['own'] = $shared['own'];
    $changed = !empty($diff['missing']) || !empty($diff['differ']) || !empty($diff['extra']) || !empty($diff['removed']);
    $warned = !empty($diff['untracked']) || !empty($diff['scripts']);
    if (!$changed && !$warned) {
        echo "[ok]    $project".(empty($diff['own']) ? '' : ' — ของโปรเจ็ค '.count($diff['own']))."\n";
        printFiles('ของโปรเจ็ค', $diff['own']);
        continue;
    }
    $summary = [];
    foreach (['missing' => 'ขาด', 'differ' => 'ต่าง', 'local' => 'แก้ในโปรเจ็ค', 'removed' => 'ต้นฉบับลบแล้ว', 'extra' => 'เกิน', 'untracked' => 'ไม่อยู่ใน git', 'scripts' => 'script ที่ build Now.js', 'own' => 'ของโปรเจ็ค'] as $key => $label) {
        if (!empty($diff[$key])) {
            $summary[] = $label.' '.count($diff[$key]);
        }
    }
    if (!$changed) {
        $failed++;
        echo "[เตือน] $project — ".implode(' · ', $summary)."\n";
        printWarnings($diff);
        continue;
    }
    $blocked = !empty($diff['extra']) || !empty($diff['local']);
    if ($options['apply'] && (!$blocked || $options['force'])) {
        $backup = "$root/backup/framework-sync/$stamp/$project";
        foreach (array_merge($diff['missing'], $diff['differ']) as $rel) {
            if (is_file("$target/$rel")) {
                backupFile("$target/$rel", "$backup/$rel");
            } elseif (!is_dir(dirname("$target/$rel"))) {
                mkdir(dirname("$target/$rel"), 0777, true);
            }
            copy("$base/$rel", "$target/$rel");
            // mtime เท่าต้นฉบับ รอบหน้าจึงแยกได้ว่าไฟล์ไหนถูกแก้ในโปรเจ็คนี้ทีหลัง
            touch("$target/$rel", filemtime("$base/$rel"));
        }
        foreach (array_merge($diff['removed'], $diff['extra']) as $rel) {
            backupFile("$target/$rel", "$backup/$rel");
            unlink("$target/$rel");
            // ลบโฟลเดอร์ที่ว่างแล้วไล่ขึ้นไป แต่ไม่ลบ Now/ Kotchasan/ เอง
            for ($dir = dirname("$target/$rel"); !in_array(substr($dir, strlen($target) + 1), $projectItems, true) && count(scandir($dir)) === 2; $dir = dirname($dir)) {
                rmdir($dir);
            }
        }
        echo "[แก้]   $project — ".implode(' · ', $summary)." (สำรองไว้ที่ backup/framework-sync/$stamp/$project)\n";
        if ($warned) {
            $failed++;
        }
        printWarnings($diff);
        continue;
    }
    $failed++;
    echo ($options['apply'] ? '[ข้าม]  ' : '[ต่าง]  ')."$project — ".implode(' · ', $summary)."\n";
    printFiles('ขาด', $diff['missing']);
    printFiles('ต่าง', array_diff($diff['differ'], $diff['local']));
    printFiles('แก้ในโปรเจ็ค', $diff['local']);
    printFiles('ต้นฉบับลบแล้ว', $diff['removed']);
    printFiles('เกิน', $diff['extra']);
    if (!empty($diff['extra'])) {
        echo "        → ไฟล์เกินเป็นของโปรเจ็คนี้: ย้าย bundle ไป js/components/<ชื่อ>/ ชุดทดสอบไป tests/Kotchasan/\n";
    }
    if (!empty($diff['local'])) {
        echo "        → ไฟล์ที่แก้ในโปรเจ็ค: นำเข้า adminframework ก่อน หรือยืนยันทับด้วย --apply --force\n";
    }
    printWarnings($diff);
}

exit($failed > 0 ? 1 : 0);
