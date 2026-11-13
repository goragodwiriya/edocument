-- ---------------------------------------------------------------------------
-- install/database.sqlite.sql — คู่ของ install/database.sql สำหรับ SQLite
--
-- ตารางและข้อมูลตั้งต้นของ "โปรเจ็คนี้" (กติกาการแบ่งไฟล์ดูที่ database.sql)
-- ---------------------------------------------------------------------------

INSERT INTO "{prefix}_category" ("type", "category_id", "topic", "color", "is_active") VALUES
('department', '1', 'บริหาร', NULL, 1),
('department', '2', 'จัดซื้อจัดจ้าง', NULL, 1),
('department', '3', 'บุคคล', NULL, 1);
