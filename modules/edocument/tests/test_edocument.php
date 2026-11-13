<?php
/**
 * ชุดทดสอบโมดูล edocument — รันผ่าน modules/edocument/tests/run.php
 * ข้อมูลตั้งต้นคือ fixtures/legacy_*.sql ที่ผ่านตัวปรับรุ่นจริงแล้ว (ผู้ใช้ 1-7 และเอกสาร 1-3 อธิบายไว้ใน legacy_data.sql)
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require getenv('EDOC_BOOT');

$ids = function ($res) {
    return array_map('intval', array_column($res['data']['data'] ?? [], 'id'));
};
$rowsOf = function ($res, $key = 'id') {
    return array_column($res['data']['data'] ?? [], null, $key);
};
$menuUrls = function ($memberId) {
    $login = \Index\Auth\Model::getUserByToken(\Index\Auth\Model::generateTokens($memberId)['access_token']);
    $urls = [];
    $menus = \Index\Menus\Controller::getMenus($login);
    array_walk_recursive($menus, function ($v, $k) use (&$urls) {
        if ($k === 'url') {
            $urls[] = $v;
        }
    });

    return $urls;
};
$loginOf = function ($memberId) {
    return \Index\Auth\Model::getUserByToken(\Index\Auth\Model::generateTokens($memberId)['access_token']);
};
$downloads = function ($documentId) {
    $result = [];
    foreach (edoc_rows('SELECT member_id, downloads, department_id FROM {prefix}_edocument_download WHERE document_id = ? ORDER BY member_id', [$documentId]) as $row) {
        $result[(int) $row['member_id']] = [(int) $row['downloads'], (string) $row['department_id']];
    }

    return $result;
};

echo "edocument: ข้อมูลเดิมหลังปรับรุ่น\n";
ok('เอกสารเดิมครบ', array_map('intval', array_column(edoc_rows('SELECT id FROM {prefix}_edocument ORDER BY id'), 'id')), [1, 2, 3]);
ok('รายชื่อผู้รับเดิมครบ', (int) edoc_rows('SELECT COUNT(*) c FROM {prefix}_edocument_download')[0]['c'], 9);
ok('ข้อความภาษาไทยไม่เพี้ยน', edoc_rows('SELECT topic FROM {prefix}_edocument WHERE id = 3')[0]['topic'], 'ประกาศ ด่วนมาก');
ok('running number ย้ายเป็นรูปแบบ %04d นับต่อจากเดิม', edoc_rows("SELECT type, prefix, auto_increment FROM {prefix}_number WHERE prefix = 'DOC-'"), [['type' => '%04d', 'prefix' => 'DOC-', 'auto_increment' => 9]]);

echo "edocument: สิทธิ์ เมนู การ์ดหน้าแรก\n";
ok('สิทธิ์ชื่อเดิม', array_column(\Edocument\Init\Controller::initPermission([]), 'value'), ['can_handle_all_edocument', 'can_upload_edocument']);
$urls = $menuUrls(2);
ok('ผู้ส่ง: มีเอกสารขาเข้าและขาออก', [in_array('/edocument', $urls, true), in_array('/edocument-sent', $urls, true)], [true, true]);
ok('ผู้ส่ง: ไม่มีตั้งค่าโมดูล', in_array('/edocument-settings', $urls, true), false);
$urls = $menuUrls(4);
ok('สมาชิก: มีแค่เอกสารขาเข้า', [in_array('/edocument', $urls, true), in_array('/edocument-sent', $urls, true)], [true, false]);
ok('แอดมิน: มีตั้งค่าโมดูล', in_array('/edocument-settings', $menuUrls(1), true));
$cards = \Edocument\Init\Controller::initDashboardCards([], null, $loginOf(4));
ok('การ์ดหน้าแรก: เอกสารใหม่ของสมาชิก 4 = 2', [$cards[0]['url'] ?? null, $cards[0]['value'] ?? null], ['/edocument', 2]);
$cards = \Edocument\Init\Controller::initDashboardCards([], null, $loginOf(2));
ok('การ์ดของผู้ส่ง 2: เอกสารใหม่ 1 (เอกสาร 3)', [count($cards), $cards[0]['value'] ?? null], [1, 1]);

echo "edocument: เอกสารขาเข้า\n";
$res = edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(4, 'GET', ['sort' => 'id asc']));
$rows = $rowsOf($res);
ok('สมาชิก 4 เห็นเอกสารที่อยู่ในรายชื่อผู้รับ', $ids($res), [1, 2]);
ok('ยังไม่ได้รับทั้งสองฉบับ', array_column($rows, 'is_new', 'id'), [1 => true, 2 => true]);
ok('ชื่อผู้ส่ง', [$rows[1]['sender'] ?? null, $rows[2]['sender'] ?? null], ['ผู้ส่ง สอง', 'แอดมิน']);
ok('ไอคอนชนิดไฟล์', basename((string) ($rows[2]['ext_icon'] ?? '')), 'jpg.png');
$res = edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(5, 'GET', ['sort' => 'id asc']));
ok('สมาชิก 5 เห็นเอกสาร 2 3', $ids($res), [2, 3]);
ok('เอกสารที่ดาวน์โหลดแล้ว = รับแล้ว', array_column($rowsOf($res), 'is_new', 'id'), [2 => false, 3 => true]);
$senders = array_column($res['data']['filters']['sender'] ?? [], 'value');
sort($senders);
ok('ตัวกรองผู้ส่ง = ผู้ที่เคยส่งเอกสาร', $senders, ['1', '2', '3']);
ok('ตัวกรองความเร่งด่วน', array_column($res['data']['filters']['urgency'] ?? [], 'text'), ['ด่วนมาก', 'ด่วน', 'ปกติ']);
ok('กรองความเร่งด่วน 0 (ด่วนมาก)', $ids(edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(5, 'GET', ['urgency' => '0']))), [3]);
ok('ความเร่งด่วนว่าง = ทั้งหมด', count($ids(edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(5, 'GET', ['urgency' => ''])))), 2);
ok('กรองผู้ส่ง', $ids(edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(5, 'GET', ['sender' => 3]))), [3]);
ok('ค้นจากเรื่อง', $ids(edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(5, 'GET', ['search' => 'Canon']))), [2]);
ok('ค้นจากเลขที่', $ids(edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(5, 'GET', ['search' => 'DOC-0007']))), [3]);
$res = edoc_call(new \Edocument\Inbox\Controller(), 'action', edoc_request(4, 'POST', ['action' => 'detail', 'id' => 1]));
ok('รายละเอียดเปิด modal', edoc_action($res, 'modal')['template'] ?? null, 'edocument/view.html');
ok('รายละเอียด: เลขที่ เรื่อง ผู้ส่ง สถานะใหม่', [edoc_data($res)['document_no'] ?? null, edoc_data($res)['sender'] ?? null, edoc_data($res)['is_new'] ?? null], ['DOC-0009', 'ผู้ส่ง สอง', true]);
$res = edoc_call(new \Edocument\Inbox\Controller(), 'action', edoc_request(7, 'POST', ['action' => 'detail', 'id' => 1]));
ok('ผู้ที่ไม่ได้อยู่ในรายชื่อผู้รับเปิดไม่ได้', $res['__status'], 404);

echo "edocument: ดาวน์โหลด = ลงชื่อรับ\n";
$res = edoc_call(new \Edocument\View\Controller(), 'download', edoc_request(4, 'POST', ['id' => 1]));
$download = edoc_action($res, 'download');
ok('ดาวน์โหลดได้ ชื่อไฟล์ = เรื่อง.นามสกุล', [$res['success'] ?? false, $download['filename'] ?? null], [true, 'คำศัพท์ชื่อป้ายห้องในโรงเรียนเป็นภาษาอังกฤษแนบ.pdf']);
ok('ปิด modal และโหลดตารางขาเข้าใหม่', [edoc_action($res, 'modal')['action'] ?? null, edoc_action($res, 'redirect')['target'] ?? null], ['close', 'edocumentInbox']);
edoc_call(new \Edocument\View\Controller(), 'download', edoc_request(4, 'POST', ['id' => 1]));
ok('นับจำนวนครั้งในแถวผู้รับเดิม', $downloads(1)[4][0] ?? null, 2);
ok('บันทึกเวลาที่รับ', (int) edoc_rows('SELECT last_update FROM {prefix}_edocument_download WHERE document_id = 1 AND member_id = 4')[0]['last_update'] > 0);
$res = edoc_call(new \Edocument\View\Controller(), 'download', edoc_request(7, 'POST', ['id' => 1]));
ok('ผู้ที่ไม่ได้อยู่ในรายชื่อผู้รับดาวน์โหลดไม่ได้', $res['__status'], 404);
edoc_call(new \Edocument\View\Controller(), 'download', edoc_request(2, 'POST', ['id' => 1]));
ok('ผู้ส่งดาวน์โหลดได้แต่ไม่นับเป็นผู้รับ', isset($downloads(1)[2]), false);
$file = edoc_subprocess('\Edocument\View\Controller', 'file', 4, ['id' => 1]);
ok('ส่งไฟล์จริงให้ผู้รับ', $file, "%PDF-1.4 legacy 1545666283\n");
$file = edoc_subprocess('\Edocument\View\Controller', 'file', 7, ['id' => 1]);
ok('ไม่ส่งไฟล์ให้ผู้ที่ไม่ได้อยู่ในรายชื่อผู้รับ', strpos($file, 'PDF') === false && strpos($file, '"success":false') !== false, true);
$res = edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(4, 'GET', ['sort' => 'id asc']));
ok('หลังดาวน์โหลด เอกสาร 1 = รับแล้ว', array_column($rowsOf($res), 'is_new', 'id'), [1 => false, 2 => true]);
ok('การ์ดหน้าแรกลดลงเหลือ 1', \Edocument\Inbox\Model::countNew($loginOf(4)), 1);

echo "edocument: เอกสารขาออก\n";
$res = edoc_call(new \Edocument\Outbox\Controller(), 'index', edoc_request(2, 'GET', ['sort' => 'id asc', 'sender' => 1]));
ok('ผู้ส่งทั่วไปเห็นเฉพาะเอกสารของตัวเอง (ส่ง sender อื่นมาก็ไม่ได้)', $ids($res), [1]);
ok('ผู้ส่งทั่วไปไม่มีตัวกรองผู้ส่ง', $res['data']['filters']['sender'] ?? null, false);
$res = edoc_call(new \Edocument\Outbox\Controller(), 'index', edoc_request(3, 'GET', ['sort' => 'id asc']));
ok('ผู้จัดการเห็นทุกฉบับ', $ids($res), [1, 2, 3]);
ok('จำนวนผู้รับต่อฉบับ', array_column($rowsOf($res), 'recipients', 'id'), [1 => 2, 2 => 5, 3 => 2]);
ok('ผู้จัดการกรองผู้ส่งได้', $ids(edoc_call(new \Edocument\Outbox\Controller(), 'index', edoc_request(3, 'GET', ['sender' => 1]))), [2]);
ok('สมาชิกที่ไม่มีสิทธิ์ส่งเปิดไม่ได้', edoc_call(new \Edocument\Outbox\Controller(), 'index', edoc_request(4, 'GET'))['__status'], 403);
$res = edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(2, 'POST', ['action' => 'download', 'id' => 1]));
ok('ดาวน์โหลดจากขาออกเป็นไฟล์แนบเสมอ', strpos(edoc_action($res, 'download')['url'] ?? '', 'attachment=1') !== false);
ok('ดาวน์โหลดเอกสารของคนอื่นจากขาออกไม่ได้', edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(2, 'POST', ['action' => 'download', 'id' => 3]))['__status'], 404);
$res = edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(3, 'POST', ['action' => 'edit', 'id' => 1]));
ok('ผู้จัดการไปหน้าแก้ไขของคนอื่นได้', $res['data']['actions'][0]['url'] ?? ($res['data']['url'] ?? json_encode($res)), '/edocument-write?id=1');
ok('ผู้ส่งแก้ไขเอกสารของคนอื่นไม่ได้', edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(2, 'POST', ['action' => 'edit', 'id' => 3]))['__status'], 403);

echo "edocument: ผู้รับ-ประวัติการดาวน์โหลด\n";
$res = edoc_call(new \Edocument\Downloads\Controller(), 'index', edoc_request(1, 'GET', ['id' => 2, 'sort' => 'name asc']));
$rows = $rowsOf($res, 'member_id');
ok('แสดงผู้รับทุกคน เรียงตามชื่อ', array_keys($rows), [2, 4, 5, 7, 3]);
ok('แผนกของผู้รับ (หมวดของระบบเดิม) รายบุคคล = ว่าง', array_column($rows, 'department', 'member_id'), [2 => 'แผนก 1', 4 => 'แผนก 1', 5 => 'แผนก 2', 7 => '', 3 => 'แผนก 2']);
ok('จำนวนดาวน์โหลดและวันที่ (ยังไม่รับ = ว่าง)', [$rows[5]['downloads'], $rows[4]['date']], [2, null]);
ok('หัวหน้าหน้าคือเอกสารที่เลือก', $res['data']['options']['document']['document_no'] ?? null, 'DOC-0008');
ok('ค้นจากชื่อ', array_keys($rowsOf(edoc_call(new \Edocument\Downloads\Controller(), 'index', edoc_request(1, 'GET', ['id' => 2, 'search' => 'ห้า'])), 'member_id')), [5]);
ok('ค้นจากอีเมล', array_keys($rowsOf(edoc_call(new \Edocument\Downloads\Controller(), 'index', edoc_request(1, 'GET', ['id' => 2, 'search' => 'member7'])), 'member_id')), [7]);
ok('ผู้ส่งดูผู้รับของเอกสารคนอื่นไม่ได้', edoc_call(new \Edocument\Downloads\Controller(), 'index', edoc_request(2, 'GET', ['id' => 2]))['__status'], 403);
ok('ผู้จัดการดูได้', edoc_call(new \Edocument\Downloads\Controller(), 'index', edoc_request(3, 'GET', ['id' => 2]))['__status'], 200);

echo "edocument: ค้นสมาชิก (ช่องผู้รับ)\n";
$res = edoc_call(new \Edocument\Autocomplete\Controller(), 'users', edoc_request(2, 'GET', ['q' => 'สมาชิก']));
ok('สมาชิกที่ใช้งานอยู่ ไม่รวมตัวเองและคนที่ถูกระงับ', array_column($res['data'] ?? [], 'value'), ['4', '5', '7']);
ok('ข้อความ = ชื่อ (ชื่อผู้ใช้)', $res['data'][0]['text'] ?? null, 'สมาชิก สี่ (member4@example.com)');
ok('ค้นจากชื่อผู้ใช้', array_column(edoc_call(new \Edocument\Autocomplete\Controller(), 'users', edoc_request(2, 'GET', ['q' => 'boss@']))['data'] ?? [], 'value'), ['3']);
ok('คำค้นว่าง = ไม่มีผล', edoc_call(new \Edocument\Autocomplete\Controller(), 'users', edoc_request(2, 'GET', ['q' => '']))['data'] ?? null, []);
ok('ไม่มีสิทธิ์ส่งค้นไม่ได้', edoc_call(new \Edocument\Autocomplete\Controller(), 'users', edoc_request(4, 'GET', ['q' => 'สมาชิก']))['__status'], 403);

echo "edocument: ฟอร์มส่งเอกสาร\n";
$res = edoc_call(new \Edocument\Document\Controller(), 'get', edoc_request(2, 'GET', ['id' => 0]));
$data = edoc_data($res);
ok('เอกสารใหม่: ความเร่งด่วนเริ่มต้น = ปกติ', array_column(array_filter($data['urgencies'] ?? [], fn($u) => $u['checked']), 'text'), ['ปกติ']);
ok('เอกสารใหม่: แผนกให้เลือก', array_column($data['departments'] ?? [], 'text'), ['แผนก 1', 'แผนก 2', 'แผนก 3']);
ok('เอกสารใหม่: ติ๊กแจ้งผู้รับไว้ก่อน ไม่มีอีเมลผู้ส่งจึงไม่แสดงตัวเลือก', [$data['send_mail'] ?? null, $data['can_send_mail'] ?? null], [true, false]);
ok('ชนิดไฟล์ที่รับ', $data['accept'] ?? null, '.doc,.ppt,.pptx,.docx,.rar,.zip,.jpg,.pdf');
$res = edoc_call(new \Edocument\Document\Controller(), 'get', edoc_request(3, 'GET', ['id' => 2]));
$data = edoc_data($res);
ok('แก้ไข: แผนกที่เลือกไว้', array_column(array_filter($data['departments'] ?? [], fn($d) => $d['checked']), 'value'), [1, 2, 3]);
ok('แก้ไข: ผู้รับรายบุคคลเป็นคู่ key/value', $data['receiver'] ?? null, [['key' => '7', 'value' => 'สมาชิก เจ็ด (member7@example.com)']]);
ok('แก้ไข: ไฟล์เดิม', $data['file_name'] ?? null, 'CanonPixmaMP280_MP287_PrinterDriver.jpg');
ok('ผู้ส่งเปิดแก้ไขเอกสารของคนอื่นไม่ได้', edoc_call(new \Edocument\Document\Controller(), 'get', edoc_request(2, 'GET', ['id' => 2]))['__status'], 403);
ok('สมาชิกที่ไม่มีสิทธิ์ส่งเปิดฟอร์มไม่ได้', edoc_call(new \Edocument\Document\Controller(), 'get', edoc_request(4, 'GET', ['id' => 0]))['__status'], 403);

$res = edoc_call(new \Edocument\Document\Controller(), 'save', edoc_request(2, 'POST', ['id' => 0, 'urgency' => 1, 'topic' => '', 'detail' => '']));
ok('ตรวจช่อง: ไฟล์ รายละเอียด ผู้รับ', array_keys(array_intersect_key($res['errors'] ?? $res['data']['errors'] ?? [], array_flip(['file', 'detail', 'department']))), ['detail', 'department', 'file']);
$pdf = tempnam(sys_get_temp_dir(), 'edocpdf');
file_put_contents($pdf, "%PDF-1.4 new upload\n");
$exe = tempnam(sys_get_temp_dir(), 'edocexe');
file_put_contents($exe, 'MZ');
$res = edoc_call(new \Edocument\Document\Controller(), 'save', edoc_request(2, 'POST', ['id' => 0, 'detail' => 'x', 'receiver' => [4]], ['file' => ['path' => $exe, 'name' => 'virus.exe']]));
ok('ชนิดไฟล์ที่ไม่อนุญาต', isset(($res['errors'] ?? $res['data']['errors'] ?? [])['file']));
$res = edoc_call(new \Edocument\Document\Controller(), 'save', edoc_request(2, 'POST', ['id' => 0, 'document_no' => 'DOC-0008', 'detail' => 'x', 'receiver' => [4]], ['file' => ['path' => $pdf, 'name' => 'a.pdf']]));
ok('เลขที่ซ้ำ', isset(($res['errors'] ?? $res['data']['errors'] ?? [])['document_no']));

// ส่งใหม่: แผนก 2 (3, 5 — 6 ถูกระงับ) + รายบุคคล 7, 6 (ระงับ), 2 (ตัวเอง)
$res = edoc_call(new \Edocument\Document\Controller(), 'save', edoc_request(2, 'POST', [
    'id' => 0, 'urgency' => 1, 'topic' => '', 'detail' => "แจ้งเวียน\nบรรทัด 2", 'department' => ['2', '99'], 'receiver' => [7, 6, 2], 'send_mail' => 1
], ['file' => ['path' => $pdf, 'name' => 'คำสั่ง ที่ 1_2569.pdf']]));
ok('ส่งสำเร็จ กลับไปเอกสารขาออก', [$res['success'] ?? false, $res['data']['actions'][0]['url'] ?? ($res['data']['url'] ?? null)], [true, '/edocument-sent']);
$new = edoc_rows('SELECT * FROM {prefix}_edocument ORDER BY id DESC LIMIT 1')[0];
$newId = (int) $new['id'];
ok('ออกเลขที่อัตโนมัติต่อจากเลขเดิม', $new['document_no'], 'DOC-0010');
ok('เรื่อง = ชื่อไฟล์ (ตัด _ ; : , เหมือนระบบเดิม)', $new['topic'], 'คำสั่ง ที่ 1_2569');
ok('แผนกที่ไม่มีอยู่จริงไม่ถูกบันทึก', $new['department'], ',2,');
ok('ผู้ส่ง ความเร่งด่วน ชนิด ขนาด', [(int) $new['sender_id'], (int) $new['urgency'], $new['ext'], (int) $new['size']], [2, 1, 'pdf', 20]);
ok('ไฟล์อยู่ใน datas/edocument', is_file(ROOT_PATH.DATA_FOLDER.'edocument/'.$new['file']));
ok('ผู้รับ = คนในแผนก (ไม่รวมคนถูกระงับ) + รายบุคคล (ไม่รวมตัวเอง)', $downloads($newId), [3 => [0, '2'], 5 => [0, '2'], 7 => [0, '']]);
ok('บันทึกประวัติการทำรายการ', (int) edoc_rows("SELECT COUNT(*) c FROM {prefix}_logs WHERE module = 'edocument' AND src_id = ?", [$newId])[0]['c'], 1);
ok('ไม่มีอีเมลผู้ส่ง ไม่ส่งแจ้งเตือน', $res['message'] ?? null, 'Saved successfully');
ok('ผู้รับเห็นเอกสารใหม่', in_array($newId, $ids(edoc_call(new \Edocument\Inbox\Controller(), 'index', edoc_request(7, 'GET'))), true));
ok('เลขที่ถัดไป', \Index\Number\Model::get(0, '%04d', 'edocument', 'document_no', 'DOC-'), 'DOC-0011');

// แก้ไข: ผู้รับเดิมที่ยังอยู่คงจำนวนดาวน์โหลด · เอาคนออก/เพิ่มคน · ไม่อัปไฟล์ใหม่ไฟล์เดิมยังอยู่
edoc_call(new \Edocument\View\Controller(), 'download', edoc_request(5, 'POST', ['id' => $newId]));
$res = edoc_call(new \Edocument\Document\Controller(), 'save', edoc_request(2, 'POST', [
    'id' => $newId, 'document_no' => $new['document_no'], 'urgency' => 2, 'topic' => 'เรื่อง: แก้ไข', 'detail' => 'แก้ไข', 'department' => ['2'], 'receiver' => [4]
]));
ok('แก้ไขสำเร็จ', $res['success'] ?? false, true);
ok('ผู้รับเดิมคงจำนวนดาวน์โหลด คนที่เอาออกหายไป คนใหม่เริ่มที่ 0', $downloads($newId), [3 => [0, '2'], 4 => [0, ''], 5 => [1, '2']]);
$edited = edoc_rows('SELECT * FROM {prefix}_edocument WHERE id = ?', [$newId])[0];
ok('ไม่อัปไฟล์ใหม่ ไฟล์เดิมยังอยู่', [$edited['file'], is_file(ROOT_PATH.DATA_FOLDER.'edocument/'.$edited['file'])], [$new['file'], true]);
ok('เรื่องตัดอักขระพิเศษ', $edited['topic'], 'เรื่อง_ แก้ไข');
$pdf2 = tempnam(sys_get_temp_dir(), 'edocpdf');
file_put_contents($pdf2, "%PDF-1.4 replaced\n");
$res = edoc_call(new \Edocument\Document\Controller(), 'save', edoc_request(2, 'POST', [
    'id' => $newId, 'document_no' => $new['document_no'], 'topic' => 'ไฟล์ใหม่', 'detail' => 'แก้ไข', 'receiver' => [4]
], ['file' => ['path' => $pdf2, 'name' => 'b.pdf']]));
$replaced = edoc_rows('SELECT file FROM {prefix}_edocument WHERE id = ?', [$newId])[0]['file'];
ok('อัปไฟล์ใหม่แทนที่ ลบไฟล์เดิม', [$replaced !== $new['file'], is_file(ROOT_PATH.DATA_FOLDER.'edocument/'.$new['file']), is_file(ROOT_PATH.DATA_FOLDER.'edocument/'.$replaced)], [true, false, true]);
ok('ไม่เลือกแผนกแล้ว เหลือเฉพาะรายบุคคล', $downloads($newId), [4 => [0, '']]);
ok('ผู้ส่งแก้ไขเอกสารของคนอื่นไม่ได้', edoc_call(new \Edocument\Document\Controller(), 'save', edoc_request(2, 'POST', ['id' => 3, 'detail' => 'x', 'receiver' => [4]]))['__status'], 403);

echo "edocument: ตั้งค่าโมดูล\n";
$res = edoc_call(new \Edocument\Settings\Controller(), 'get', edoc_request(1, 'GET'));
$data = edoc_data($res);
ok('ค่าที่ตั้งไว้ของระบบเดิม', [$data['edocument_prefix'] ?? null, $data['edocument_format_no'] ?? null, $data['edocument_file_typies'] ?? null, $data['edocument_upload_size'] ?? null], ['DOC-', '%04d', 'doc,ppt,pptx,docx,rar,zip,jpg,pdf', 2097152]);
ok('ตัวเลือกเมื่อคลิกดาวน์โหลด', array_column($res['data']['options']['edocument_download_action'] ?? [], 'text'), ['ดาวน์โหลดไฟล์', 'เปิดไฟล์ (ที่รู้จัก)']);
ok('ผู้ที่ไม่มีสิทธิ์ตั้งค่าเปิดไม่ได้', edoc_call(new \Edocument\Settings\Controller(), 'get', edoc_request(2, 'GET'))['__status'], 403);
$res = edoc_call(new \Edocument\Settings\Controller(), 'save', edoc_request(1, 'POST', ['edocument_file_typies' => 'pdf,toolong', 'edocument_prefix' => 'X', 'edocument_format_no' => '%04d', 'edocument_upload_size' => 1048576, 'edocument_download_action' => 1]));
ok('ชนิดไฟล์ยาวเกิน 4 ตัวไม่ผ่าน', isset(($res['errors'] ?? $res['data']['errors'] ?? [])['edocument_file_typies']));
$res = edoc_call(new \Edocument\Settings\Controller(), 'save', edoc_request(1, 'POST', ['edocument_file_typies' => 'PDF, jpg,pdf', 'edocument_prefix' => 'ที่ ศธ%Y%M-', 'edocument_format_no' => '%04d', 'edocument_upload_size' => 1048576, 'edocument_download_action' => 1]));
$saved = include ROOT_PATH.'settings/config.php';
ok('บันทึกลง settings/config.php', [$res['success'] ?? false, $saved['edocument_file_typies'] ?? null, $saved['edocument_prefix'] ?? null, $saved['edocument_upload_size'] ?? null, $saved['edocument_download_action'] ?? null], [true, ['pdf', 'jpg'], 'ที่ ศธ%Y%M-', 1048576, 1]);
ok('password_key เดิมไม่หาย', $saved['password_key'] ?? null, '6af081c27b044');
// โหลดค่ากำหนดใหม่ในโปรเซสนี้ แล้วตรวจ "เปิดไฟล์ (ที่รู้จัก)"
\Kotchasan\Config::create()->edocument_download_action = 1;
$res = edoc_call(new \Edocument\View\Controller(), 'download', edoc_request(5, 'POST', ['id' => 3]));
ok('ตั้งให้เปิดไฟล์: pdf เปิดในแท็บใหม่', [edoc_action($res, 'redirect')['target'] ?? null, edoc_action($res, 'download')], ['_blank', null]);
$res = edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(3, 'POST', ['action' => 'download', 'id' => 3]));
ok('ขาออกยังดาวน์โหลดเป็นไฟล์แนบ', strpos(edoc_action($res, 'download')['url'] ?? '', 'attachment=1') !== false);

echo "edocument: ลบเอกสาร\n";
$res = edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(2, 'POST', ['action' => 'delete', 'ids' => [2, 3]]));
ok('ผู้ส่งลบเอกสารของคนอื่นไม่ได้', [$res['__status'], (int) edoc_rows('SELECT COUNT(*) c FROM {prefix}_edocument WHERE id IN (2, 3)')[0]['c']], [400, 2]);
$res = edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(2, 'POST', ['action' => 'delete', 'ids' => [1, $newId]]));
ok('ลบเอกสารของตัวเอง', [$res['success'] ?? false, (int) edoc_rows('SELECT COUNT(*) c FROM {prefix}_edocument WHERE id IN (1, ?)', [$newId])[0]['c']], [true, 0]);
ok('ลบรายชื่อผู้รับ', (int) edoc_rows('SELECT COUNT(*) c FROM {prefix}_edocument_download WHERE document_id IN (1, ?)', [$newId])[0]['c'], 0);
ok('ลบไฟล์', [is_file(ROOT_PATH.DATA_FOLDER.'edocument/1545666283.pdf'), is_file(ROOT_PATH.DATA_FOLDER.'edocument/'.$replaced)], [false, false]);
$res = edoc_call(new \Edocument\Outbox\Controller(), 'action', edoc_request(3, 'POST', ['action' => 'delete', 'id' => 3]));
ok('ผู้จัดการลบเอกสารของคนอื่นได้', (int) edoc_rows('SELECT COUNT(*) c FROM {prefix}_edocument WHERE id = 3')[0]['c'], 0);

summary();
