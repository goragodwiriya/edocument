-- ---------------------------------------------------------------------------
-- modules/edocument/install/database.sql — ตารางที่โมดูล edocument (งานสารบรรณ) เป็นเจ้าของ
--
-- **ประกาศที่นี่ที่เดียว** ห้ามประกาศซ้ำใน install/database.sql ของโปรเจ็ค
--
-- คอลัมน์และดัชนีทุกตัวเหมือนระบบเดิม (E-Document 6.x) ไซต์ที่ย้ายมาจึงใช้ตารางเดิมได้ทันที
--   edocument.department  แผนกผู้รับที่เลือก เก็บเป็น ",1,2," (category_id ของ type department)
--   edocument.receiver    ระบบเดิมไม่เคยเขียนค่า (NULL) คงไว้เพื่อไม่ให้ข้อมูลเดิมหาย
--   edocument.urgency     0 ด่วนมาก 1 ด่วน 2 ปกติ (คีย์ภาษา URGENCIES)
--   edocument.last_update unix timestamp ของการส่ง/แก้ไขล่าสุด
--   edocument.file        ชื่อไฟล์ใน DATA_FOLDER/edocument/ (<timestamp>.<ext>)
--   edocument_download    รายชื่อผู้รับ หนึ่งแถวต่อเอกสารต่อสมาชิก สร้างตอนส่งเอกสาร
--                         downloads = จำนวนครั้งที่ดาวน์โหลด (0 = ยังไม่ได้รับ)
--                         department_id = แผนกที่ทำให้ได้รับ ('' = เลือกรายบุคคล)
-- ---------------------------------------------------------------------------

CREATE TABLE `{prefix}_edocument` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `department` text DEFAULT NULL,
  `receiver` text DEFAULT NULL,
  `urgency` tinyint(1) NOT NULL DEFAULT 2,
  `last_update` int(11) NOT NULL,
  `document_no` varchar(20) NOT NULL,
  `detail` text NOT NULL,
  `topic` varchar(255) NOT NULL,
  `ext` varchar(4) NOT NULL,
  `size` double NOT NULL,
  `file` varchar(15) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `{prefix}_edocument_download` (
  `document_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `downloads` int(11) NOT NULL,
  `last_update` int(11) NOT NULL,
  `department_id` varchar(10) DEFAULT NULL,
  KEY `document_id` (`document_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- เอกสารตัวอย่าง — ลงเฉพาะการติดตั้งใหม่ ตัวปรับรุ่น (ensureTable) ไม่รัน INSERT
-- ไซต์ที่ปรับรุ่นจึงไม่ได้แถวเหล่านี้
--
-- ไม่มีไฟล์แนบ (file ว่าง) เพราะ SQL ส่งไฟล์ไปด้วยไม่ได้ เหมือนตัวอย่างของระบบเดิม
-- เปิดดูรายการ รายละเอียด และผู้รับได้ กดดาวน์โหลดจะแจ้งว่าไม่พบไฟล์
-- แก้ไขเอกสารแล้วอัปโหลดไฟล์ใหม่ก็ใช้งานได้ตามปกติ
--
-- ผู้ส่งและผู้รับคือ member_id = 1 ผู้ดูแลสูงสุดที่ตัวติดตั้งสร้างหลังรันไฟล์ SQL
-- (เป็นสมาชิกคนเดียวของระบบที่ติดตั้งใหม่ เอกสารขาเข้าและการ์ดหน้าแรกจึงมีตัวเลขให้เห็น)
-- แผนก 1-3 คือแผนกตั้งต้นใน install/database.sql ของโปรเจ็ค
-- วันที่นับถอยหลังจากวันติดตั้ง · เลขที่ SAMPLE-xxxx อยู่นอกรูปแบบเลขจริง จึงไม่ชนกับเลขที่ระบบออกให้
-- ---------------------------------------------------------------------------
INSERT INTO `{prefix}_edocument` (`id`, `sender_id`, `department`, `urgency`, `last_update`, `document_no`, `detail`, `topic`, `ext`, `size`, `file`) VALUES
(1, 1, ',1,2,3,', 2, UNIX_TIMESTAMP() - 86400 * 30, 'SAMPLE-0001', 'แจ้งวันหยุดประจำปีของหน่วยงาน กรุณาวางแผนการปฏิบัติงานล่วงหน้า', 'ประกาศวันหยุดประจำปี', 'pdf', 184320, ''),
(2, 1, ',1,', 1, UNIX_TIMESTAMP() - 86400 * 14, 'SAMPLE-0002', 'ประชุมวันจันทร์แรกของเดือน เวลา 09.00 น. ณ ห้องประชุม 1 พร้อมวาระการประชุมแนบท้าย', 'ขอเชิญประชุมคณะกรรมการบริหารประจำเดือน', 'docx', 45056, ''),
(3, 1, ',2,', 0, UNIX_TIMESTAMP() - 86400 * 5, 'SAMPLE-0003', 'ส่งเอกสารประกอบการจัดซื้อจัดจ้างไตรมาสสุดท้ายภายในวันศุกร์นี้', 'แจ้งกำหนดส่งเอกสารจัดซื้อจัดจ้าง', 'pdf', 266240, ''),
(4, 1, ',3,', 2, UNIX_TIMESTAMP() - 86400 * 3, 'SAMPLE-0004', 'แต่งตั้งคณะทำงานพัฒนาบุคลากรประจำปี รายชื่อตามเอกสารแนบ', 'คำสั่งแต่งตั้งคณะทำงานพัฒนาบุคลากร', 'pdf', 98304, ''),
(5, 1, ',,', 1, UNIX_TIMESTAMP() - 86400 * 1, 'SAMPLE-0005', 'กรอกแบบฟอร์มและแนบกำหนดการเดินทาง ส่งก่อนเดินทางอย่างน้อย 3 วันทำการ', 'แบบฟอร์มขออนุมัติเดินทางไปราชการ', 'docx', 30720, '');

-- ผู้รับ: รับแล้ว 2 ฉบับ ยังไม่ได้รับ 3 ฉบับ (department_id '' = เลือกรายบุคคล)
INSERT INTO `{prefix}_edocument_download` (`document_id`, `member_id`, `downloads`, `last_update`, `department_id`) VALUES
(1, 1, 1, UNIX_TIMESTAMP() - 86400 * 29, '1'),
(2, 1, 2, UNIX_TIMESTAMP() - 86400 * 13, '1'),
(3, 1, 0, 0, '2'),
(4, 1, 0, 0, '3'),
(5, 1, 0, 0, '');
