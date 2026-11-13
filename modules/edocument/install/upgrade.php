<?php
/**
 * modules/edocument/install/upgrade.php — พาฐานเดิมมาถึงสคีมาของโมดูล edocument
 *
 * install/upgrade_core.php เรียกไฟล์นี้ให้เอง ตัวแปรที่ใช้ได้คือชุดเดียวกับที่
 * upgrade_core ใช้ : $db, $db_config, $prefix, $content, $config
 *
 * นิยามตารางอยู่ที่ modules/edocument/install/database.sql ที่เดียว
 * กฎเดียวกับ upgrade_core : ทุกเงื่อนไขถามว่า "ต้องแก้ไหม" ไม่ใช่ "ตอนนี้เป็นอะไร"
 */
if (!defined('ROOT_PATH')) {
    exit;
}

// [ตาราง => [[คอลัมน์ TEXT => ยอมรับ NULL], ดัชนีที่ต้องมี, คอลัมน์ AUTO_INCREMENT]]
foreach ([
    'edocument' => [['department' => true, 'receiver' => true, 'detail' => false], [], 'id'],
    'edocument_download' => [[], ['document_id' => '`document_id`', 'member_id' => '`member_id`'], null]
] as $_table => $_spec) {
    $_name = $prefix.'_'.$_table;
    if (ensureTable($db, $prefix, $_name)) {
        $content[] = '<li class="correct">'.$_table.': สร้างตารางใหม่</li>';
        continue;
    }
    if (convertToInnoDB($db, $_name)) {
        $content[] = '<li class="correct">'.$_table.': แปลงเป็น InnoDB</li>';
    }
    // แปลง charset ก่อนปรับชนิดคอลัมน์ เพราะ CONVERT TO utf8mb4 ดัน TEXT ขึ้นเป็น MEDIUMTEXT
    if (convertToUtf8mb4($db, $_name)) {
        $content[] = '<li class="correct">'.$_table.': แปลงเป็น utf8mb4</li>';
    }
    foreach ($_spec[0] as $_column => $_nullable) {
        if (ensureColumn($db, $_name, $_column, 'text', $_nullable)) {
            $content[] = '<li class="correct">'.$_table.': ปรับคอลัมน์ '.$_column.'</li>';
        }
    }
    // ตัวติดตั้งรุ่นเก่าเติม PRIMARY KEY และ AUTO_INCREMENT ด้วย ALTER ท้าย database.sql
    if ($_spec[2] !== null && ensureAutoIncrement($db, $_name, $_spec[2], 'int(11)')) {
        $content[] = '<li class="correct">'.$_table.': ปรับ '.$_spec[2].' เป็น AUTO_INCREMENT</li>';
    }
    foreach (ensureIndexes($db, $_name, $_spec[1]) as $_index) {
        $content[] = '<li class="correct">'.$_table.': เพิ่มดัชนี '.$_index.'</li>';
    }
    $content[] = '<li class="correct">'.$_table.' อัปเกรดสำเร็จ</li>';
}

// ค่ากำหนดของโมดูล — ไม่ทับค่าที่ไซต์ตั้งไว้แล้ว
// ⚠️ ชุดเดียวกับ Edocument\Settings\Controller::DEFAULTS (ตัวติดตั้งไม่ได้โหลด autoloader จึงอ้างคลาสไม่ได้)
foreach ([
    'edocument_prefix' => '',
    'edocument_format_no' => '%04d',
    'edocument_file_typies' => ['doc', 'ppt', 'pptx', 'docx', 'rar', 'zip', 'jpg', 'pdf'],
    'edocument_upload_size' => 2097152,
    'edocument_download_action' => 0
] as $_key => $_value) {
    if (!isset($config[$_key])) {
        $config[$_key] = $_value;
    }
}

// เลขที่เอกสารอัตโนมัติ: ระบบเดิมเก็บ running number ด้วยชื่อค่ากำหนด (type = 'edocument_format_no')
// แกนรุ่นนี้ (Index\Number\Model) ใช้ตัวรูปแบบเป็น type ย้ายแถวเดิมมาเพื่อให้นับต่อจากเลขเดิม
$_table_number = $prefix.'_number';
if ($db->tableExists($_table_number)) {
    $_format = (string) $config['edocument_format_no'];
    if ($_format === '') {
        $_format = (string) $config['edocument_prefix'] !== '' ? (string) $config['edocument_prefix'] : '%04d';
    }
    foreach ($db->customQuery("SELECT `prefix`, `auto_increment` FROM `$_table_number` WHERE `type` = 'edocument_format_no'", true) as $_row) {
        $_exists = $db->customQuery("SELECT `auto_increment` FROM `$_table_number` WHERE `type` = :t AND `prefix` = :p", true, [':t' => $_format, ':p' => $_row['prefix']]);
        if (empty($_exists)) {
            $db->customQuery("UPDATE `$_table_number` SET `type` = :t WHERE `type` = 'edocument_format_no' AND `prefix` = :p", false, [':t' => $_format, ':p' => $_row['prefix']]);
        } else {
            // มีแถวของรูปแบบนี้อยู่แล้ว เก็บเลขที่มากกว่าไว้
            $db->customQuery("UPDATE `$_table_number` SET `auto_increment` = GREATEST(`auto_increment`, :n) WHERE `type` = :t AND `prefix` = :p", false, [':n' => (int) $_row['auto_increment'], ':t' => $_format, ':p' => $_row['prefix']]);
            $db->customQuery("DELETE FROM `$_table_number` WHERE `type` = 'edocument_format_no' AND `prefix` = :p", false, [':p' => $_row['prefix']]);
        }
        $content[] = '<li class="correct">number: ย้ายเลขที่เอกสารของงานสารบรรณ</li>';
    }
}
