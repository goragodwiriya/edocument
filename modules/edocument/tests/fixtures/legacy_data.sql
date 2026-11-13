-- ข้อมูลตัวอย่างของระบบเดิม (E-Document 6.x) เพิ่มจากที่ legacy_schema.sql มีให้
-- รหัสผ่านทุกคน = secret123 แบบ sha1(password_key.password.salt) ของระบบเดิม
--
--   1 แอดมิน                                 (status 1)
--   2 ผู้ส่ง สอง      แผนก 1  can_upload_edocument
--   3 หัวหน้า สาม     แผนก 2  can_upload_edocument + can_handle_all_edocument
--   4 สมาชิก สี่      แผนก 1
--   5 สมาชิก ห้า      แผนก 2
--   6 สมาชิก หก       แผนก 2  ระงับการใช้งาน
--   7 สมาชิก เจ็ด     ไม่มีแผนก
--
-- เอกสาร 1 (ผู้ส่ง 2, เลือกรายบุคคล)       ผู้รับ 1 (รับแล้ว 3 ครั้ง), 4
-- เอกสาร 2 (ผู้ส่ง 1, แผนก 1,2,3 + รายบุคคล) ผู้รับ 2 (รับแล้ว), 4, 3, 5 (รับแล้ว 2 ครั้ง), 7
-- เอกสาร 3 (ผู้ส่ง 3, แผนก 2 + รายบุคคล, ด่วนมาก) ผู้รับ 5, 2

INSERT INTO `{prefix}_user` (`id`, `username`, `salt`, `password`, `token`, `status`, `permission`, `name`, `create_date`, `active`, `social`, `line_uid`, `telegram_id`, `activatecode`) VALUES
(1, 'admin', 's1', 'b8c199b40c4a181b95beb1dcd2e20d5647e79017', NULL, 1, '', 'แอดมิน', '2024-01-01 08:00:00', 1, 0, NULL, NULL, ''),
(2, 'sender@example.com', 's2', '7a16c6b6e880be987a3fe61f160d0199d9d74901', NULL, 2, ',can_upload_edocument,', 'ผู้ส่ง สอง', '2024-01-01 08:00:00', 1, 0, NULL, NULL, ''),
(3, 'boss@example.com', 's2', '7a16c6b6e880be987a3fe61f160d0199d9d74901', NULL, 2, ',can_upload_edocument,can_handle_all_edocument,', 'หัวหน้า สาม', '2024-01-01 08:00:00', 1, 0, NULL, NULL, ''),
(4, 'member4@example.com', 's2', '7a16c6b6e880be987a3fe61f160d0199d9d74901', NULL, 0, '', 'สมาชิก สี่', '2024-01-01 08:00:00', 1, 0, NULL, NULL, ''),
(5, 'member5@example.com', 's2', '7a16c6b6e880be987a3fe61f160d0199d9d74901', NULL, 0, '', 'สมาชิก ห้า', '2024-01-01 08:00:00', 1, 0, NULL, NULL, ''),
(6, 'member6@example.com', 's2', '7a16c6b6e880be987a3fe61f160d0199d9d74901', NULL, 0, '', 'สมาชิก หก', '2024-01-01 08:00:00', 0, 0, NULL, NULL, ''),
(7, 'member7@example.com', 's2', '7a16c6b6e880be987a3fe61f160d0199d9d74901', NULL, 0, '', 'สมาชิก เจ็ด', '2024-01-01 08:00:00', 1, 0, NULL, NULL, '');

INSERT INTO `{prefix}_user_meta` (`value`, `name`, `member_id`) VALUES
('1', 'department', 2),
('2', 'department', 3),
('1', 'department', 4),
('2', 'department', 5),
('2', 'department', 6);

INSERT INTO `{prefix}_edocument` (`id`, `sender_id`, `department`, `urgency`, `last_update`, `document_no`, `detail`, `topic`, `ext`, `size`, `file`) VALUES
(3, 3, ',2,', 0, 1700000000, 'DOC-0007', 'เรื่องด่วนมาก\nบรรทัดที่สอง', 'ประกาศ ด่วนมาก', 'pdf', 27, '1700000000.pdf');

INSERT INTO `{prefix}_edocument_download` (`document_id`, `member_id`, `downloads`, `last_update`, `department_id`) VALUES
(1, 1, 3, 1632053000, ''),
(1, 4, 0, 0, ''),
(2, 2, 1, 1545665000, '1'),
(2, 4, 0, 0, '1'),
(2, 3, 0, 0, '2'),
(2, 5, 2, 1545666000, '2'),
(2, 7, 0, 0, ''),
(3, 5, 0, 0, '2'),
(3, 2, 0, 0, '');

-- running number ของระบบเดิม เก็บด้วยชื่อค่ากำหนด (type = edocument_format_no)
INSERT INTO `{prefix}_number` (`type`, `prefix`, `auto_increment`, `last_update`) VALUES
('edocument_format_no', 'DOC-', 9, '2024-07-24');

INSERT INTO `{prefix}_logs` (`id`, `src_id`, `module`, `action`, `create_date`, `reason`, `member_id`, `topic`, `datas`) VALUES
(1, 2, 'edocument', 'Save', '2021-09-19 18:22:12', NULL, 2, '{LNG_Send Document} ID : 1', NULL);
