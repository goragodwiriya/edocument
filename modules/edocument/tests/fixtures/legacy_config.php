<?php
/* config.php ของระบบเดิม (E-Document 6.9.0) — edocument_prefix เปลี่ยนเป็น DOC- ให้เลขที่เอกสารตรวจได้แน่นอน */
return array (
  'version' => '6.9.0',
  'web_title' => 'E-Document',
  'web_description' => 'ระบบสารบรรณอิเล็กทรอนิกส์',
  'timezone' => 'Asia/Bangkok',
  'member_status' =>
  array (
    0 => 'สมาชิก',
    1 => 'ผู้ดูแลระบบ',
    2 => 'เจ้าหน้าที่',
  ),
  'color_status' =>
  array (
    0 => '#259B24',
    1 => '#FF0000',
    2 => '#0E0EDA',
  ),
  'default_icon' => 'icon-tools',
  'edocument_format_no' => '%04d',
  'edocument_prefix' => 'DOC-',
  'edocument_file_typies' =>
  array (
    0 => 'doc',
    1 => 'ppt',
    2 => 'pptx',
    3 => 'docx',
    4 => 'rar',
    5 => 'zip',
    6 => 'jpg',
    7 => 'pdf',
  ),
  'edocument_upload_size' => 2097152,
  'edocument_download_action' => 0,
  'password_key' => '6af081c27b044',
  'reversion' => 1794146754,
);
