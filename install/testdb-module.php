<?php
/**
 * install/testdb-module.php — ข้อมูลทดสอบของงานสารบรรณ (edocument) สำหรับ install/cli-testdb.php
 *
 * ชุดทั่วไป (F2/F4/F5/F6): ใส่เอกสารและรายชื่อผู้รับ เพื่อให้การนับแถวก่อน/หลังปรับรุ่น
 * ครอบตาราง edocument* ด้วย
 *
 * ชุด F3: ตาราง edocument* หน้าตาแบบ E-Document 6.9 (ตัวที่อัปเกรดมาจริง) — MyISAM + utf8mb3,
 * PRIMARY KEY เติมด้วย ALTER ไม่มี AUTO_INCREMENT และเลขที่เอกสารที่ number.type
 * ยังเป็น 'edocument_format_no' — ตัวปรับรุ่นต้องพาไปถึงสคีมาปัจจุบันโดยไม่ทำข้อมูลหาย
 * (ชุดทดสอบเต็มของโมดูลอยู่ที่ modules/edocument/tests/run.php)
 */
if (!defined('ROOT_PATH')) {
    exit;
}

function testdbSeedModule($db, $prefix, array &$notes, $fixture = '')
{
    if ($fixture === 'f3') {
        testdbEdocumentLegacyState($db, $prefix, $notes);

        return;
    }
    $doc = (int) $db->insert($prefix.'_edocument', [
        'sender_id' => 1, 'department' => ',1,', 'urgency' => 2, 'last_update' => time(), 'document_no' => 'TEST-0001',
        'detail' => 'รายละเอียด', 'topic' => 'เอกสารทดสอบ', 'ext' => 'pdf', 'size' => 1234, 'file' => '1700000000.pdf'
    ]);
    $db->insert($prefix.'_edocument_download', ['document_id' => $doc, 'member_id' => 2, 'downloads' => 0, 'last_update' => 0, 'department_id' => '1']);
    $db->insert($prefix.'_edocument_download', ['document_id' => $doc, 'member_id' => 3, 'downloads' => 2, 'last_update' => time(), 'department_id' => '']);
    $notes[] = 'edocument: เอกสาร 1 ผู้รับ 2';
}

/**
 * ตาราง edocument* แบบ E-Document 6.9 พร้อมข้อมูล
 *
 * @param Db     $db
 * @param string $prefix
 * @param array  $notes
 */
function testdbEdocumentLegacyState($db, $prefix, array &$notes)
{
    foreach (['edocument', 'edocument_download'] as $table) {
        $db->query("DROP TABLE IF EXISTS `{$prefix}_$table`");
    }
    // คำสั่งชุดเดียวกับ install/database.sql ของ E-Document 6.9 (phpMyAdmin dump)
    $db->query("CREATE TABLE `{$prefix}_edocument` (`id` int(11) NOT NULL, `sender_id` int(11) NOT NULL, `department` text DEFAULT NULL,
        `receiver` text DEFAULT NULL, `urgency` tinyint(1) NOT NULL DEFAULT 2, `last_update` int(11) NOT NULL, `document_no` varchar(20) NOT NULL,
        `detail` text NOT NULL, `topic` varchar(255) NOT NULL, `ext` varchar(4) NOT NULL, `size` double NOT NULL, `file` varchar(15) NOT NULL
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    $db->query("CREATE TABLE `{$prefix}_edocument_download` (`document_id` int(11) NOT NULL, `member_id` int(11) NOT NULL,
        `downloads` int(11) NOT NULL, `last_update` int(11) NOT NULL, `department_id` varchar(10) DEFAULT NULL
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
    // ตัวติดตั้งรุ่นเก่าเติม PRIMARY KEY/ดัชนีด้วย ALTER — จำลองไซต์ที่ไม่ได้รัน ALTER ... AUTO_INCREMENT ท้ายไฟล์
    $db->query("ALTER TABLE `{$prefix}_edocument` ADD PRIMARY KEY (`id`)");
    $db->query("ALTER TABLE `{$prefix}_edocument_download` ADD KEY `document_id` (`document_id`), ADD KEY `member_id` (`member_id`)");
    $db->query("INSERT INTO `{$prefix}_edocument` (`id`, `sender_id`, `department`, `urgency`, `last_update`, `document_no`, `detail`, `topic`, `ext`, `size`, `file`) VALUES
        (1, 2, ',,', 2, 1632052932, 'DOC-0009', 'ส่งให้แอดมิน', 'คำศัพท์ชื่อป้ายห้องในโรงเรียนเป็นภาษาอังกฤษแนบ', 'pdf', 457639, '1545666283.pdf'),
        (2, 1, ',1,2,3,', 2, 1545664264, 'DOC-0008', 'ทดสอบ', 'CanonPixmaMP280-MP287-PrinterDriver', 'jpg', 18795, '1545662500.jpg')");
    $db->query("INSERT INTO `{$prefix}_edocument_download` VALUES (1, 1, 3, 1632053000, ''), (2, 1, 0, 0, '1')");
    // running number ของระบบเดิมเก็บด้วยชื่อค่ากำหนด
    if ($db->tableExists($prefix.'_number')) {
        $db->query("DELETE FROM `{$prefix}_number` WHERE `type` IN ('edocument_format_no', '%04d') AND `prefix` = 'DOC-'");
        $db->query("INSERT INTO `{$prefix}_number` (`type`, `prefix`, `auto_increment`) VALUES ('edocument_format_no', 'DOC-', 9)");
    }
    $notes[] = 'edocument: ตารางรุ่น 6.9 (MyISAM/utf8 ไม่มี AUTO_INCREMENT) เอกสาร 2 ผู้รับ 2 + running number แบบเดิม';
}
