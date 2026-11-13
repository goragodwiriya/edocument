/**
 * modules/edocument/admin.js
 *
 * route ของโมดูลงานสารบรรณ + ตัวจัดรูปเซลล์ + สคริปต์ของฟอร์มส่งเอกสาร
 */
EventManager.on('router:initialized', () => {
  // เอกสารขาเข้า (สมาชิกทุกคน) — module=edocument ของระบบเดิม
  RouterManager.register('/edocument', {
    template: 'edocument/inbox.html',
    title: '{LNG_Received document}',
    requireAuth: true
  });

  // เอกสารขาออก (can_upload_edocument) — module=edocument-sent
  RouterManager.register('/edocument-sent', {
    template: 'edocument/outbox.html',
    title: '{LNG_Sent document}',
    requireAuth: true
  });

  // ส่ง/แก้ไขเอกสาร — module=edocument-write
  RouterManager.register('/edocument-write', {
    template: 'edocument/document.html',
    title: '{LNG_Send Document}',
    menuPath: '/edocument-sent',
    requireAuth: true
  });

  // ผู้รับ-ประวัติการดาวน์โหลด — module=edocument-report
  RouterManager.register('/edocument-downloads', {
    template: 'edocument/downloads.html',
    title: '{LNG_Recipient}-{LNG_Download history}',
    menuPath: '/edocument-sent',
    requireAuth: true
  });

  // ตั้งค่าโมดูล — module=edocument-settings
  RouterManager.register('/edocument-settings', {
    template: 'edocument/settings.html',
    title: '{LNG_Module Settings} {LNG_E-Document}',
    requireAuth: true
  });
});

/**
 * ไอคอนชนิดไฟล์ (คอลัมน์ ext)
 */
function formatEdocumentExt(cell, rawValue, rowData) {
  const ext = Utils.string.escape(rawValue || '');
  cell.innerHTML = rowData?.ext_icon ? `<img class="edocument-ext" src="${Utils.string.escape(rowData.ext_icon)}" alt="${ext}" title="${ext}">` : '';
}

/**
 * ความเร่งด่วน 0 ด่วนมาก 1 ด่วน 2 ปกติ (คีย์ภาษา URGENCIES) ด่วนและด่วนมากเน้นสีเหมือนระบบเดิม
 */
function formatEdocumentUrgency(cell, rawValue, rowData, attributes) {
  const urgency = Number(rawValue);
  // TableManager ส่งตัวเลือกเป็น {ค่า: ข้อความ} (ตัวเลือกจากตัวกรองของคอลัมน์) หรือ [{value, text}]
  const options = attributes?.lookupOptions || {};
  const label = Array.isArray(options)
    ? options.find((option) => Number(option.value) === urgency)?.text ?? ''
    : options[urgency] ?? '';
  cell.innerHTML = `<span class="edocument-urgency urgency-${urgency}">${Utils.string.escape(label)}</span>`;
}

/**
 * สถานะการรับเอกสาร: ใหม่ (ยังไม่ดาวน์โหลด) / รับแล้ว
 */
function formatEdocumentStatus(cell, rawValue) {
  cell.innerHTML = rawValue
    ? `<span class="icon-email edocument-new notext" title="${Utils.string.escape(Now.translate('New'))}"></span>`
    : `<span class="icon-email-read edocument-received notext" title="${Utils.string.escape(Now.translate('Received'))}"></span>`;
}

/**
 * ฟอร์มส่งเอกสาร (เหมือน initEdocumentWrite + edocumentFileChanged ของระบบเดิม)
 * - เลือกแผนกแล้วซ่อนช่องรายชื่อสมาชิก (ผู้รับทั้งแผนก)
 * - เลือกไฟล์แล้วใช้ชื่อไฟล์เป็นเรื่อง เมื่อเป็นเอกสารใหม่หรือยังไม่ได้กรอกเรื่อง
 */
function initEdocumentDocument(element) {
  const receiverField = element.querySelector('#edocument-receiver-field');
  const fileInput = element.querySelector('#edocument_file');
  const topic = element.querySelector('#edocument_topic');
  const idInput = element.querySelector('input[name="id"]');

  const toggleReceiver = () => {
    const checked = element.querySelector('input[name="department[]"]:checked') !== null;
    if (receiverField) receiverField.classList.toggle('hidden', checked);
  };
  const onDepartmentChange = (event) => {
    if (event.target?.name === 'department[]') toggleReceiver();
  };
  const onFileChange = () => {
    const name = fileInput?.files?.[0]?.name || '';
    const match = /(.*)\.([a-z0-9]+)$/i.exec(name);
    if (match && topic && (Number(idInput?.value || 0) === 0 || topic.value === '')) {
      topic.value = match[1];
    }
  };

  element.addEventListener('change', onDepartmentChange);
  fileInput?.addEventListener('change', onFileChange);
  toggleReceiver();

  return () => {
    element.removeEventListener('change', onDepartmentChange);
    fileInput?.removeEventListener('change', onFileChange);
  };
}
