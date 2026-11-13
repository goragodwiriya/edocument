<?php
/**
 * install/cli-layout-templates.php — ตัด sidebar/topbar/footer ออกจาก template ของหน้า
 * ให้เหลือแค่เนื้อหา สำหรับโปรเจ็คที่ย้ายไปใช้ layout ของ RouterManager
 *
 * ก่อนมี layout ทุกหน้าห่อตัวเองด้วยโครงเดียวกัน
 *   <aside data-component="sidebar"></aside>
 *   <div class="main-content"><div>
 *     <header data-component="topbar"></header>
 *     <main class="content"> …เนื้อหา… </main>
 *   </div><footer class="footer">…</footer></div>
 * ตอนนี้โครงนั้นอยู่ใน templates/layouts/app.html ที่เดียว (router.layout ใน js/main.js)
 * ถ้าหน้ายังห่อตัวเองอยู่ จะได้ sidebar ซ้อนสองชั้น
 *
 * สคริปต์นี้เก็บ "เนื้อหาของ <main class="content">" แล้วทิ้งโครงรอบนอก
 *   - attribute อื่นของ <main> (เช่น data-script) ย้ายไปที่ element รากของหน้า — ต้องมีรากเดียว
 *   - <form> ที่ครอบ topbar + main (หน้าแบบฟอร์มบางหน้า) กลายเป็นรากของหน้า ครอบเนื้อหาไว้เหมือนเดิม
 *   - คอมเมนต์ก่อนโครง (คำอธิบายหน้า) เก็บไว้บนสุด
 *   - </div> ที่ปิดผิดลำดับท้ายเนื้อหา (โครงเดิมปิด tag สลับกัน) ถูกตัดทิ้ง
 *   - ถอยย่อหน้าของเนื้อหาให้ชิดซ้าย
 * อะไรที่ไม่ใช่โครงที่รู้จัก (element อื่นรอบ <main>, class อื่นบน <main>, หลายรากแต่มี
 * attribute ต้องย้าย) ไม่เดา — รายงานเป็น "แก้เอง" และไม่แตะไฟล์นั้น
 *
 * ใช้:  php install/cli-layout-templates.php <app>/templates [โฟลเดอร์ template อื่นของแอปเดียวกัน ...]
 *            [--routes=<ไฟล์หรือ glob>,...] [--write] [--quiet]
 *
 *   ไม่ใส่ --write = ดูอย่างเดียว (บอกว่าแต่ละไฟล์จะเป็นอย่างไร)
 *   โฟลเดอร์แรกคือ templates ของแอป (โฟลเดอร์แม่ของมันคือตัวแอป) · ข้าม <โฟลเดอร์แรก>/layouts/ เสมอ
 *   โฟลเดอร์ถัดไป = template ของแอปเดียวกันที่อยู่นอก templates/ เช่น admin ของตระกูล GCMS
 *     php install/cli-layout-templates.php admin/templates modules/<m>/template widgets/<w> --routes=modules/<m>/admin.js
 *   ไฟล์ที่ลงทะเบียน route: <app>/js/*.js กับ <app>/modules/<m>/admin.js เสมอ บวกที่ระบุใน --routes
 *   (แอปย่อยที่โหลด admin.js ของโมดูลจากโฟลเดอร์แม่ ต้องบอกเอง ไม่อย่างนั้นตรวจ route ไม่ครบ)
 *
 * exit code : 0 = ทุกไฟล์ที่มีโครงแปลงได้, 1 = มีไฟล์ที่ต้องแก้เอง หรือ route ที่ต้องดู
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$dirs = [];
$routeGlobs = [];
$write = false;
$quiet = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--write') {
        $write = true;
    } elseif ($arg === '--quiet') {
        $quiet = true;
    } elseif (strpos($arg, '--routes=') === 0) {
        $routeGlobs = array_merge($routeGlobs, array_filter(explode(',', substr($arg, 9))));
    } elseif ($arg[0] !== '-' && is_dir($arg)) {
        $dirs[] = rtrim($arg, '/');
    } else {
        fwrite(STDERR, "ไม่รู้จัก $arg (หรือไม่ใช่โฟลเดอร์)\n");
        exit(2);
    }
}
if (empty($dirs)) {
    fwrite(STDERR, "ใช้: php install/cli-layout-templates.php <app>/templates [โฟลเดอร์อื่น ...] [--routes=...] [--write] [--quiet]\n");
    exit(2);
}
$templatesDir = $dirs[0];

// ชื่อไฟล์ที่แสดง = โฟลเดอร์ที่ส่งมา + path ภายใน
$files = [];
foreach ($dirs as $index => $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $item) {
        $path = $item->getPathname();
        $rel = substr($path, strlen($dir) + 1);
        if ($item->isFile() && substr($path, -5) === '.html' && !($index === 0 && strpos($rel, 'layouts/') === 0)) {
            $files[$dir.'/'.$rel] = $path;
        }
    }
}
ksort($files);

// route ที่ layout จะเปลี่ยน — ต้องดูก่อนเขียน ตอนที่ยังรู้ว่า template ไหนเคยห่อตัวเอง
// แปลงครบไปแล้ว (ไม่มีหน้าไหนห่อตัวเองเหลือ) แยกหน้าแอปกับหน้า guest จาก template ไม่ได้อีก จึงไม่ตรวจ
$pending = false;
foreach ($files as $path) {
    if (strpos(file_get_contents($path), 'data-component="sidebar"') !== false) {
        $pending = true;
        break;
    }
}
$routeWarnings = $pending ? checkRoutes(dirname($templatesDir), $templatesDir, $routeGlobs) : [];

$count = ['converted' => 0, 'manual' => 0, 'plain' => 0];
foreach ($files as $rel => $path) {
    $html = file_get_contents($path);
    if (strpos($html, 'data-component="sidebar"') === false) {
        $count['plain']++;
        continue;
    }
    try {
        list($out, $notes) = convertTemplate($html);
        $count['converted']++;
        if ($write) {
            file_put_contents($path, $out);
        }
        if (!$quiet) {
            echo ($write ? '[เขียน] ' : '[แปลงได้] ').$rel.(empty($notes) ? '' : '  — '.implode(' · ', $notes))."\n";
        }
    } catch (RuntimeException $e) {
        $count['manual']++;
        echo '[แก้เอง] '.$rel.'  — '.$e->getMessage()."\n";
    }
}

if (!empty($routeWarnings)) {
    echo str_repeat('-', 60)."\n";
    echo "route ที่ต้องดู (อ่านด้วย regex อาจตกหล่นรูปแบบแปลก ๆ):\n";
    foreach ($routeWarnings as $warning) {
        echo '  '.$warning."\n";
    }
}

echo str_repeat('-', 60)."\n";
echo ($write ? 'เขียนแล้ว ' : 'แปลงได้ ').$count['converted'].' · แก้เอง '.$count['manual'].' · ไม่มีโครง (ไม่แตะ) '.$count['plain']
    .' · route ที่ต้องดู '.count($routeWarnings)."\n";
if (!$write && $count['converted'] > 0) {
    echo "ยังไม่ได้เขียนไฟล์ — ใส่ --write เพื่อเขียน\n";
}
exit($count['manual'] > 0 || !empty($routeWarnings) ? 1 : 0);

/**
 * route ของแอปที่ layout จะเปลี่ยนเมื่อหน้าไม่ห่อตัวเองแล้ว
 *   - template ไม่เคยมีโครง (หน้าร้าน หน้าสมัครงาน หน้าพิมพ์) แต่ route ไม่ได้ตั้ง layout
 *     → จะถูกวางใน layout หลักแล้วได้ sidebar ที่ไม่เคยมี ต้องตั้ง layout: false
 *   - template มีโครง แต่ route ตั้ง layout: false → จะเสีย sidebar ไป
 * อ่าน route สามรูปแบบที่ใช้กันอยู่:
 *   '/path': {template: …}   ·   RouterManager.register('/path', {template: …})
 *   ['/path', 'x.html', …] (ลงทะเบียนเป็นชุดด้วย forEach)   ·   '/path': ['x.html', …]
 * template เขียนเป็น 'x.html', WEB_URL + 'x.html' หรือ `${WEB_URL}x.html` ได้ (ที่มี :param ข้าม)
 * หาไฟล์จาก templates/ แล้วโฟลเดอร์แอป แล้วโฟลเดอร์แม่ของแอป (WEB_URL ของแอปย่อย)
 *
 * @param string   $appRoot      โฟลเดอร์ของแอป (แม่ของโฟลเดอร์ templates)
 * @param string   $templatesDir
 * @param string[] $extraGlobs   ไฟล์ route เพิ่มจาก --routes
 *
 * @return string[] ข้อความเตือน
 */
function checkRoutes($appRoot, $templatesDir, array $extraGlobs = [])
{
    $sources = array_merge(glob($appRoot.'/js/*.js') ?: [], glob($appRoot.'/modules/*/admin.js') ?: []);
    foreach ($extraGlobs as $pattern) {
        $sources = array_merge($sources, glob($pattern) ?: []);
    }
    $value = '(?:WEB_URL\s*\+\s*)?[\'"`](?:\$\{WEB_URL\})?([^\'"`]+)[\'"`]';
    $routes = [];
    foreach (array_unique($sources) as $file) {
        if (!is_file($file)) {
            continue;
        }
        $src = file_get_contents($file);
        // object ของ route — หาวงเล็บปิดของมันด้วยการนับ
        if (preg_match_all('~(?:[\'"](/[^\'"]*)[\'"]\s*:\s*\{|RouterManager\.register\(\s*[\'"](/[^\'"]*)[\'"]\s*,\s*\{)~', $src, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $path = $m[1][0] !== '' ? $m[1][0] : $m[2][0];
                $start = $m[0][1] + strlen($m[0][0]);
                $depth = 1;
                for ($i = $start; $i < strlen($src) && $depth > 0; $i++) {
                    if ($src[$i] === '{') {
                        $depth++;
                    } elseif ($src[$i] === '}') {
                        $depth--;
                    }
                }
                $body = substr($src, $start, $i - $start);
                if (preg_match('~template\s*:\s*'.$value.'~', $body, $t)) {
                    $layout = preg_match('~layout\s*:\s*(false|null|[\'"][^\'"]*[\'"])~', $body, $l) ? $l[1] : null;
                    $routes[] = [$path, $t[1], $layout, $file];
                }
            }
        }
        if (preg_match_all('~(?:\[\s*[\'"](/[^\'"]*)[\'"]\s*,\s*|[\'"](/[^\'"]*)[\'"]\s*:\s*\[\s*)[\'"]([^\'"]+\.html)[\'"]~', $src, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $routes[] = [$m[1] !== '' ? $m[1] : $m[2], $m[3], null, $file];
            }
        }
    }

    $warnings = [];
    foreach ($routes as list($path, $template, $layout, $file)) {
        if (strpos($template, ':') !== false || strpos($template, '<') !== false || strpos($template, '${') !== false) {
            continue;
        }
        $tplFile = null;
        foreach ([$templatesDir, $appRoot, dirname($appRoot)] as $base) {
            if (is_file($base.'/'.ltrim($template, '/'))) {
                $tplFile = $base.'/'.ltrim($template, '/');
                break;
            }
        }
        if ($tplFile === null) {
            continue;
        }
        $wrapped = strpos(file_get_contents($tplFile), 'data-component="sidebar"') !== false;
        $noLayout = $layout === 'false' || $layout === 'null';
        if (!$wrapped && $layout === null) {
            $warnings[] = $path.' ('.$template.', '.$file.') — template ไม่มีโครงแต่ไม่ได้ตั้ง layout: false → จะได้ sidebar';
        } elseif ($wrapped && $noLayout) {
            $warnings[] = $path.' ('.$template.', '.$file.') — ตั้ง layout: '.$layout.' แต่ template มีโครง → จะเสีย sidebar';
        }
    }
    return array_values(array_unique($warnings));
}

/**
 * แยก markup เป็น tag/คอมเมนต์ตามลำดับ — ค่าใน attribute มี < > ได้ (data-template ของตาราง)
 * เนื้อหาของ <script>/<style> ข้ามทั้งก้อน
 *
 * @param string $html
 *
 * @return array [['kind' => open|close|comment, 'name', 'attrs', 'start', 'end', 'raw'], ...]
 */
function tokenize($html)
{
    $tokens = [];
    $pattern = '~<!--.*?-->|<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s=>/]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?)*)\s*(/?)>~s';
    $offset = 0;
    while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $raw = $m[0][0];
        $start = $m[0][1];
        $end = $start + strlen($raw);
        if (strpos($raw, '<!--') === 0) {
            $tokens[] = ['kind' => 'comment', 'name' => '', 'attrs' => '', 'start' => $start, 'end' => $end, 'raw' => $raw];
        } else {
            $name = strtolower($m[2][0]);
            $kind = $m[1][0] === '/' ? 'close' : 'open';
            $tokens[] = ['kind' => $kind, 'name' => $name, 'attrs' => $m[3][0], 'start' => $start, 'end' => $end, 'raw' => $raw,
                'selfClosing' => $m[4][0] === '/'];
            if ($kind === 'open' && ($name === 'script' || $name === 'style')) {
                $close = stripos($html, '</'.$name, $end);
                if ($close !== false) {
                    $end = $close;
                }
            }
        }
        $offset = $end;
    }
    return $tokens;
}

/**
 * @param string $attrs ส่วน attribute ของ tag เปิด
 *
 * @return array ชื่อ => ข้อความเต็มของ attribute ตามลำดับเดิม
 */
function parseAttributes($attrs)
{
    $result = [];
    preg_match_all('~([^\s=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~s', $attrs, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $result[strtolower($m[1])] = $m[0];
    }
    return $result;
}

/**
 * @param array $token tag เปิด
 *
 * @return string[] class ของ tag
 */
function classesOf($token)
{
    $attrs = parseAttributes($token['attrs']);
    if (!isset($attrs['class']) || !preg_match('~=\s*["\']?([^"\']*)~', $attrs['class'], $m)) {
        return [];
    }
    return preg_split('~\s+~', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
}

/**
 * @param array  $token tag เปิด
 * @param string $name  ชื่อ attribute
 *
 * @return string ค่าของ attribute (ไม่มี = '')
 */
function attributeValue($token, $name)
{
    $attrs = parseAttributes($token['attrs']);
    if (!isset($attrs[$name]) || !preg_match('~=\s*["\']?([^"\']*)~', $attrs[$name], $m)) {
        return '';
    }
    return $m[1];
}

/**
 * @param string $html
 * @param int    $offset
 *
 * @return int คอลัมน์ของตำแหน่งนั้นในบรรทัดของมัน
 */
function columnOf($html, $offset)
{
    $lineStart = strrpos(substr($html, 0, $offset), "\n");
    return $offset - ($lineStart === false ? 0 : $lineStart + 1);
}

/**
 * ถอยย่อหน้าทุกบรรทัดเท่ากับบรรทัดที่ย่อน้อยที่สุด (บรรทัดว่างไม่นับ)
 *
 * @param string $text
 *
 * @return string
 */
function dedent($text)
{
    $lines = explode("\n", $text);
    $min = null;
    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $min = $min === null ? $indent : min($min, $indent);
        }
    }
    foreach ($lines as $i => $line) {
        $lines[$i] = trim($line) === '' ? '' : substr($line, (int) $min);
    }
    return implode("\n", $lines);
}

/**
 * ตัดบรรทัดว่างหัวท้ายแล้วถอยย่อหน้า
 *
 * @param string $text
 *
 * @return string
 */
function block($text)
{
    return dedent(rtrim(preg_replace('~^(?:[ \t]*\n)+~', '', $text)));
}

/**
 * แปลง template หนึ่งไฟล์
 *
 * @param string $html
 *
 * @return array [markup ใหม่, หมายเหตุ[]]
 *
 * @throws RuntimeException เมื่อโครงไม่ใช่แบบที่รู้จัก
 */
function convertTemplate($html)
{
    $eol = strpos($html, "\r\n") !== false ? "\r\n" : "\n";
    $html = str_replace("\r\n", "\n", $html);
    $tokens = tokenize($html);
    $notes = [];

    // <main class="content"> กับ </main> ของมัน
    $mainOpen = null;
    $mainClose = null;
    $depth = 0;
    foreach ($tokens as $i => $t) {
        if ($t['name'] !== 'main') {
            continue;
        }
        if ($mainOpen === null) {
            if ($t['kind'] === 'open' && in_array('content', classesOf($t), true)) {
                $mainOpen = $i;
            }
            continue;
        }
        if ($t['kind'] === 'open') {
            $depth++;
        } elseif ($depth === 0) {
            $mainClose = $i;
            break;
        } else {
            $depth--;
        }
    }
    if ($mainOpen === null || $mainClose === null) {
        throw new RuntimeException('ไม่พบ <main class="content"> … </main>');
    }

    // โครงก่อน <main>: คอมเมนต์ sidebar div.main-content <div> topbar และ <form> ที่ครอบได้หนึ่งอัน
    $comments = [];
    $form = null;
    $wrapperAttrs = [];
    $wrapperClasses = [];
    $cursor = 0;
    for ($i = 0; $i < $mainOpen; $i++) {
        $t = $tokens[$i];
        if (trim(substr($html, $cursor, $t['start'] - $cursor)) !== '') {
            throw new RuntimeException('มีข้อความก่อน <main>: '.mb_substr(trim(substr($html, $cursor, $t['start'] - $cursor)), 0, 40));
        }
        $cursor = $t['end'];
        $classes = $t['kind'] === 'open' ? classesOf($t) : [];
        $component = $t['kind'] === 'open' ? attributeValue($t, 'data-component') : '';
        if ($t['kind'] === 'comment') {
            $comments[] = $t['raw'];
        } elseif ($t['name'] === 'aside' && ($t['kind'] === 'close' || $component === 'sidebar')) {
            continue;
        } elseif ($t['name'] === 'header' && ($t['kind'] === 'close' || $component === 'topbar')) {
            continue;
        } elseif ($t['name'] === 'div' && $t['kind'] === 'open') {
            // div ของโครง — attribute อื่น (เช่น data-script บน div ที่ครอบ topbar + main) ย้ายไปรากของหน้า
            // เหมือน attribute ของ <main>
            $attrs = parseAttributes($t['attrs']);
            unset($attrs['class']);
            $wrapperAttrs = array_merge($wrapperAttrs, $attrs);
            $wrapperClasses = array_merge($wrapperClasses, array_diff($classes, ['main-content']));
        } elseif ($t['name'] === 'form' && $t['kind'] === 'open' && $form === null) {
            // ย่อหน้าของ tag เปิด (หลายบรรทัดได้) วัดจากคอลัมน์ของ <form
            $lineStart = strrpos(substr($html, 0, $t['start']), "\n");
            $column = $t['start'] - ($lineStart === false ? 0 : $lineStart + 1);
            $form = preg_replace('~\n {'.$column.'}~', "\n", $t['raw']);
        } else {
            throw new RuntimeException('มี element อื่นในโครงก่อน <main>: '.mb_substr($t['raw'], 0, 60));
        }
    }
    if (trim(substr($html, $cursor, $tokens[$mainOpen]['start'] - $cursor)) !== '') {
        throw new RuntimeException('มีข้อความก่อน <main>');
    }

    // โครงหลัง </main>: </div> </form> และ <footer> ทั้งก้อน
    // element อื่นต่อท้ายโครง (<template> ที่สคริปต์ clone, <style>, คอมเมนต์) ย้ายไปท้ายเนื้อหาของหน้า
    $formClosed = false;
    $trailing = [];
    $cursor = $tokens[$mainClose]['end'];
    $count = count($tokens);
    for ($i = $mainClose + 1; $i < $count; $i++) {
        $t = $tokens[$i];
        if (trim(substr($html, $cursor, $t['start'] - $cursor)) !== '') {
            throw new RuntimeException('มีข้อความหลัง </main>: '.mb_substr(trim(substr($html, $cursor, $t['start'] - $cursor)), 0, 40));
        }
        $cursor = $t['end'];
        if ($t['kind'] === 'close' && $t['name'] === 'div') {
            continue;
        } elseif ($t['kind'] === 'close' && $t['name'] === 'form' && $form !== null && !$formClosed) {
            $formClosed = true;
        } elseif ($t['kind'] === 'open' && $t['name'] === 'footer') {
            // ข้ามถึง </footer>
            $i++;
            while ($i < $count && !($tokens[$i]['kind'] === 'close' && $tokens[$i]['name'] === 'footer')) {
                $i++;
            }
            if ($i === $count) {
                throw new RuntimeException('<footer> ไม่มี </footer>');
            }
            $cursor = $tokens[$i]['end'];
        } elseif ($t['kind'] === 'comment') {
            $trailing[] = [$t['raw'], columnOf($html, $t['start'])];
        } elseif ($t['kind'] === 'open') {
            // ทั้ง element จนถึง tag ปิดคู่ของมัน
            $end = $t['end'];
            if (!in_array($t['name'], ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'], true) && !$t['selfClosing']) {
                $depth = 0;
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]['name'] !== $t['name']) {
                        continue;
                    }
                    if ($tokens[$j]['kind'] === 'open') {
                        $depth++;
                    } elseif ($depth === 0) {
                        break;
                    } else {
                        $depth--;
                    }
                }
                if ($j === $count) {
                    throw new RuntimeException('<'.$t['name'].'> หลัง </main> ไม่มี tag ปิด');
                }
                $end = $tokens[$j]['end'];
                $i = $j;
            }
            $trailing[] = [substr($html, $t['start'], $end - $t['start']), columnOf($html, $t['start'])];
            $cursor = $end;
            $notes[] = 'ย้าย <'.$t['name'].'> ที่ต่อท้ายโครงไปท้ายเนื้อหา';
        } else {
            // tag ปิดที่ไม่มีคู่ในโครง เบราว์เซอร์ข้ามอยู่แล้ว
            $notes[] = 'ตัด </'.$t['name'].'> ที่ไม่มีคู่ท้ายไฟล์';
        }
    }
    if (trim(substr($html, $cursor)) !== '') {
        throw new RuntimeException('มีข้อความท้ายไฟล์: '.mb_substr(trim(substr($html, $cursor)), 0, 40));
    }
    if ($form !== null && !$formClosed) {
        throw new RuntimeException('<form> ที่ครอบ <main> ไม่มี </form>');
    }

    // เนื้อหา — ไล่ tag แบบที่เบราว์เซอร์ parse เพื่อให้ DOM หลังแปลงเท่าเดิม
    //   - tag ปิดที่ไม่มีคู่เปิดในเนื้อหา = ไปปิดโครงข้างนอก (โครงเดิมปิดสลับลำดับ) เบราว์เซอร์ปิดทุกอย่าง
    //     ในเนื้อหาตรงนั้น → ตัดทิ้งได้ถ้าหลังจากนั้นมีแต่ tag ปิด ถ้ามีเนื้อหาตามมา เนื้อหานั้นเคยอยู่นอก
    //     <main> → แก้เอง
    //   - tag ปิดของ element ที่ลึกกว่าบนสุด = ปิดข้ามชั้น parse เหมือนกันทั้งก่อนและหลัง → คงไว้
    //   - element ที่ค้างเปิดถึง </main> เคยถูก </main> ปิดให้ → เติม tag ปิดท้ายเนื้อหา
    $innerStart = $tokens[$mainOpen]['end'];
    $innerEnd = $tokens[$mainClose]['start'];
    $stack = [];
    $roots = [];
    $edits = [];
    $voids = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
    $optionalEnd = ['option', 'optgroup', 'li', 'p', 'dt', 'dd', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot', 'colgroup', 'caption', 'rb', 'rt', 'rp'];
    for ($i = $mainOpen + 1; $i < $mainClose; $i++) {
        $t = $tokens[$i];
        if ($t['kind'] === 'comment') {
            continue;
        }
        if ($t['kind'] === 'open') {
            if (empty($stack)) {
                $roots[] = $t;
            }
            if (!in_array($t['name'], $voids, true) && !$t['selfClosing']) {
                $lineStart = strrpos(substr($html, 0, $t['start']), "\n");
                $stack[] = ['name' => $t['name'], 'column' => $t['start'] - ($lineStart === false ? 0 : $lineStart + 1)];
            }
            continue;
        }
        $names = array_column($stack, 'name');
        if (in_array($t['name'], $names, true)) {
            if (end($names) !== $t['name'] && !in_array(end($names), $optionalEnd, true) && !in_array('ปิดข้ามชั้นในเนื้อหา (คงไว้)', $notes, true)) {
                $notes[] = 'ปิดข้ามชั้นในเนื้อหา (คงไว้)';
            }
            while (array_pop($stack)['name'] !== $t['name']);
            continue;
        }
        // ไม่มีคู่ทั้งในเนื้อหาและในโครงข้างนอก — เบราว์เซอร์ข้ามทั้งก่อนและหลังแปลง
        if ($t['name'] !== 'div' && !($t['name'] === 'form' && $form !== null)) {
            if (!in_array('tag ปิดที่ไม่มีคู่ (เบราว์เซอร์ข้าม — คงไว้)', $notes, true)) {
                $notes[] = 'tag ปิดที่ไม่มีคู่ (เบราว์เซอร์ข้าม — คงไว้)';
            }
            continue;
        }
        $after = substr($html, $t['end'], $innerEnd - $t['end']);
        if (trim(preg_replace('~<!--.*?-->|</[a-zA-Z][a-zA-Z0-9:-]*\s*>~s', '', $after)) !== '') {
            throw new RuntimeException('</'.$t['name'].'> ปิดโครงนอก <main> แล้วยังมีเนื้อหาตามมา (เปิดค้าง: '.implode(' > ', $names).')');
        }
        $edits[] = [$t['start'], $t['end'] - $t['start'], ''];
        $notes[] = 'ตัด </'.$t['name'].'> ที่ไปปิดโครงนอก <main>';
    }
    $unclosed = array_reverse(array_filter($stack, function ($item) use ($optionalEnd) {
        return !in_array($item['name'], $optionalEnd, true);
    }));
    if (!empty($unclosed)) {
        $notes[] = 'ปิด '.implode(' ', array_map(function ($item) {
            return '<'.$item['name'].'>';
        }, $unclosed)).' ที่ค้างถึง </main>';
    }

    // attribute และ class อื่นของ <main> ย้ายไปที่รากของหน้า (class ต่อท้าย class เดิมของราก)
    $mainAttrs = parseAttributes($tokens[$mainOpen]['attrs']);
    unset($mainAttrs['class']);
    foreach ($wrapperAttrs as $name => $attr) {
        if (isset($mainAttrs[$name])) {
            throw new RuntimeException('ทั้ง <main> และ div ของโครงมี '.$name.' — ย้ายเอง');
        }
        $mainAttrs[$name] = $attr;
    }
    $extraClasses = array_values(array_unique(array_merge($wrapperClasses, array_diff(classesOf($tokens[$mainOpen]), ['content']))));
    $wrapper = null;
    if ((!empty($mainAttrs) || !empty($extraClasses)) && count($roots) !== 1) {
        // หลายราก — ครอบด้วย div ที่เรียงลูกแบบ .content (flex column) และรับ gap จาก .content
        // ที่ครอบมันอยู่ ระยะห่างระหว่างรากจึงเท่าเดิมไม่ว่าแอปตั้ง gap ไว้เท่าไร
        $wrapper = '<div class="'.implode(' ', array_merge(['flex', 'column'], $extraClasses)).'" style="gap: inherit"'
            .(empty($mainAttrs) ? '' : ' '.implode(' ', $mainAttrs)).'>';
        $notes[] = 'หลายราก — ครอบด้วย '.$wrapper;
    } elseif (!empty($mainAttrs) || !empty($extraClasses)) {
        $moving = array_merge(array_values($mainAttrs), empty($extraClasses) ? [] : ['class "'.implode(' ', $extraClasses).'"']);
        $root = $roots[0];
        $rootAttrs = parseAttributes($root['attrs']);
        foreach (array_keys($mainAttrs) as $name) {
            if (isset($rootAttrs[$name])) {
                throw new RuntimeException('รากของหน้ามี '.$name.' อยู่แล้ว ย้าย '.$mainAttrs[$name].' จาก <main> ไม่ได้');
            }
        }
        $insert = array_values($mainAttrs);
        if (!empty($extraClasses)) {
            if (preg_match('~\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~', $root['raw'], $c, PREG_OFFSET_CAPTURE)) {
                $current = trim($c[1][0].($c[2][0] ?? '').($c[3][0] ?? ''));
                $edits[] = [$root['start'] + $c[0][1], strlen($c[0][0]), ' class="'.trim($current.' '.implode(' ', $extraClasses)).'"'];
            } else {
                $insert[] = 'class="'.implode(' ', $extraClasses).'"';
            }
        }
        if (!empty($insert)) {
            $edits[] = [$root['end'] - ($root['selfClosing'] ? 2 : 1), 0, ' '.implode(' ', $insert)];
        }
        $notes[] = 'ย้าย '.implode(' ', $moving).' จาก <main> ไปที่ <'.$root['name'].'>';
    }

    // แก้จากท้ายไปหัว offset ที่เหลือจึงไม่เลื่อน
    $inner = substr($html, $innerStart, $innerEnd - $innerStart);
    usort($edits, function ($a, $b) {
        return $b[0] - $a[0];
    });
    foreach ($edits as list($at, $length, $text)) {
        $inner = substr_replace($inner, $text, $at - $innerStart, $length);
    }

    // tag ปิดที่เติม ย่อหน้าตามบรรทัดของ tag เปิด หักส่วนที่ถอยออกทั้งก้อน
    $indent = null;
    foreach (explode("\n", $inner) as $line) {
        if (trim($line) !== '') {
            $width = strlen($line) - strlen(ltrim($line, ' '));
            $indent = $indent === null ? $width : min($indent, $width);
        }
    }
    $content = block($inner);
    foreach ($unclosed as $item) {
        $content .= "\n".str_repeat(' ', max(0, $item['column'] - (int) $indent)).'</'.$item['name'].'>';
    }
    if ($wrapper !== null) {
        $content = $wrapper."\n".preg_replace('~^(?=.)~m', '  ', $content)."\n</div>";
    }
    $out = '';
    foreach ($comments as $comment) {
        $out .= block($comment)."\n";
    }
    if ($form !== null) {
        $notes[] = '<form> ที่ครอบ <main> เป็นรากของหน้า';
        $out .= $form."\n".preg_replace('~^(?=.)~m', '  ', $content)."\n</form>\n";
    } else {
        $out .= $content."\n";
    }
    foreach ($trailing as list($piece, $column)) {
        $out .= block(str_repeat(' ', $column).$piece)."\n";
    }

    // ตรวจที่ tag เท่านั้น — คอมเมนต์อธิบายหน้าพูดถึง <main> ได้
    foreach (tokenize($out) as $t) {
        if ($t['kind'] === 'open' && ($t['name'] === 'main' || in_array(attributeValue($t, 'data-component'), ['sidebar', 'topbar'], true))) {
            throw new RuntimeException('ผลลัพธ์ยังมี '.mb_substr($t['raw'], 0, 60));
        }
    }
    return [str_replace("\n", $eol, $out), $notes];
}
