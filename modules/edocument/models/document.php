<?php
/**
 * @filesource modules/edocument/models/document.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Document;

use Gcms\Api as ApiController;
use Kotchasan\Date;
use Kotchasan\Language;

/**
 * เอกสารหนึ่งฉบับ ผู้รับของเอกสาร และกติกาว่าใครทำอะไรกับเอกสารได้
 *
 * ผู้รับของเอกสารคือแถวใน edocument_download ที่สร้างตอนส่ง (เหมือนระบบเดิม)
 * แถวละหนึ่งคน downloads = 0 คือยังไม่ได้รับ department_id = แผนกที่ทำให้ได้รับ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ชนิดไฟล์ที่เปิดในเบราว์เซอร์ได้ เมื่อตั้งค่า "เมื่อคลิกดาวน์โหลด" = เปิดไฟล์
     * (know_file_typies ของระบบเดิม)
     */
    const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'];

    /**
     * โฟลเดอร์เก็บไฟล์ (เหมือนระบบเดิม)
     *
     * @return string
     */
    public static function dir()
    {
        return ROOT_PATH.DATA_FOLDER.'edocument/';
    }

    /**
     * อ่านเอกสาร
     *
     * @param int $id
     *
     * @return object|null department เป็น array ของ category_id
     */
    public static function get($id)
    {
        $item = \Kotchasan\DB::create()->first('edocument', [['id', (int) $id]]);
        if ($item) {
            $item->department = self::parseList($item->department);
        }

        return $item;
    }

    /**
     * เอกสารใหม่ (ค่าเริ่มต้น ความเร่งด่วน = ปกติ)
     *
     * @param object $login
     *
     * @return object
     */
    public static function newDocument($login)
    {
        return (object) [
            'id' => 0,
            'document_no' => '',
            'sender_id' => (int) $login->id,
            'department' => [],
            'urgency' => 2,
            'topic' => '',
            'detail' => '',
            'file' => '',
            'ext' => '',
            'size' => 0,
            'last_update' => 0
        ];
    }

    /**
     * ",1,2," -> ['1', '2']
     *
     * @param string|null $value
     *
     * @return array
     */
    public static function parseList($value)
    {
        $result = [];
        foreach (explode(',', trim((string) $value, ',')) as $item) {
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * ผู้จัดการงานสารบรรณ (can_handle_all_edocument)
     *
     * @param object $login
     *
     * @return bool
     */
    public static function canHandleAll($login)
    {
        return ApiController::hasPermission($login, 'can_handle_all_edocument');
    }

    /**
     * ผู้ส่งหรือผู้จัดการ แก้ไข/ลบ/ดูผู้รับได้
     *
     * @param object $login
     * @param object $document
     *
     * @return bool
     */
    public static function canManage($login, $document)
    {
        return self::canHandleAll($login) || (int) $document->sender_id === (int) $login->id;
    }

    /**
     * แถวผู้รับของสมาชิกคนนี้ (null = ไม่ได้เป็นผู้รับ)
     *
     * @param int $document_id
     * @param int $member_id
     *
     * @return object|null
     */
    public static function recipient($document_id, $member_id)
    {
        return \Kotchasan\DB::create()->first('edocument_download', [
            ['document_id', (int) $document_id],
            ['member_id', (int) $member_id]
        ]);
    }

    /**
     * ผู้รับ ผู้ส่ง หรือผู้จัดการ เปิดและดาวน์โหลดได้
     *
     * @param object $login
     * @param object $document
     *
     * @return bool
     */
    public static function canRead($login, $document)
    {
        return self::canManage($login, $document) || self::recipient($document->id, $login->id) !== null;
    }

    /**
     * ความเร่งด่วน [{value, text}]
     *
     * @return array
     */
    public static function urgencyOptions()
    {
        return \Gcms\Controller::arrayToOptions(Language::get('URGENCIES', []));
    }

    /**
     * ไอคอนชนิดไฟล์ (images/ext ของโปรเจ็ค)
     *
     * @param string $ext
     *
     * @return string
     */
    public static function extIcon($ext)
    {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string) $ext));

        return WEB_URL.'images/ext/'.(is_file(ROOT_PATH.'images/ext/'.$ext.'.png') ? $ext : 'file').'.png';
    }

    /**
     * ขนาดไฟล์ไม่เกินที่ตั้งไว้ และไม่เกินที่เซิร์ฟเวอร์รับได้
     *
     * @return int ไบต์
     */
    public static function uploadSize()
    {
        $max = \Kotchasan\Http\UploadedFile::getUploadSize(true);
        $size = (int) \Edocument\Settings\Controller::value('edocument_upload_size');

        return $size > 0 && $size < $max ? $size : $max;
    }

    /**
     * ชื่อไฟล์ตอนดาวน์โหลด = เรื่อง.นามสกุล (ตัดอักขระพิเศษเหมือนระบบเดิม)
     *
     * @param object $document
     *
     * @return string
     */
    public static function filename($document)
    {
        return preg_replace('/[,;:_\-\(\)\?\&\+\[\]\s]{1,}/', '_', $document->topic).'.'.$document->ext;
    }

    /**
     * เปิดไฟล์ในเบราว์เซอร์หรือไม่ (ตั้งค่า + ชนิดไฟล์)
     *
     * @param object $document
     *
     * @return bool
     */
    public static function opensInline($document)
    {
        return (int) \Edocument\Settings\Controller::value('edocument_download_action') === 1
            && in_array(strtolower($document->ext), self::INLINE, true);
    }

    /**
     * ผู้รับที่เลือกรายบุคคล (แถวที่ department_id ว่าง) เป็นคู่ {key, value} ให้ช่อง tags
     *
     * @param int $document_id
     *
     * @return array
     */
    public static function individualReceivers($document_id)
    {
        $query = static::createQuery()
            ->select('U.id', 'U.name', 'U.username')
            ->from('edocument_download D')
            ->join('user U', [['U.id', 'D.member_id']], 'INNER')
            ->where([['D.document_id', (int) $document_id]])
            // ระบบเดิมเขียน '' ให้ผู้รับรายบุคคล แถวที่นำเข้าเองอาจเป็น NULL
            // (เงื่อนไข null ของ QueryBuilder ต่อด้วย AND เสมอ จึงใช้ OR กับ null ไม่ได้)
            ->where("COALESCE(D.`department_id`, '') = ''")
            ->orderBy('U.name');
        $result = [];
        foreach ($query->fetchAll() as $item) {
            $result[] = ['key' => (string) $item->id, 'value' => self::memberLabel($item)];
        }

        return $result;
    }

    /**
     * ข้อความของสมาชิกในรายการผู้รับ "ชื่อ (ชื่อผู้ใช้)"
     *
     * @param object $user name, username
     *
     * @return string
     */
    public static function memberLabel($user)
    {
        return (string) $user->name.(empty($user->username) ? '' : ' ('.$user->username.')');
    }

    /**
     * สมาชิกที่ใช้งานอยู่ในแผนกที่เลือก (ยกเว้นผู้ส่ง) [member_id => แผนก]
     *
     * @param array $departments category_id ของแผนก
     * @param int $exclude_id ผู้ส่ง
     *
     * @return array
     */
    public static function departmentMembers(array $departments, $exclude_id)
    {
        if (empty($departments)) {
            return [];
        }
        $query = static::createQuery()
            ->select('U.id', 'D.value')
            ->from('user U')
            ->join('user_meta D', [['D.member_id', 'U.id'], ['D.name', 'department']], 'INNER')
            ->where([
                ['U.active', 1],
                ['U.id', '!=', (int) $exclude_id],
                ['D.value', array_values($departments)]
            ]);
        $result = [];
        foreach ($query->fetchAll() as $item) {
            $result[(int) $item->id] = (string) $item->value;
        }

        return $result;
    }

    /**
     * สมาชิกที่เลือกได้จริง (มีอยู่และใช้งานอยู่ ยกเว้นผู้ส่ง)
     *
     * @param array $ids
     * @param int $exclude_id
     *
     * @return array [member_id, ...]
     */
    public static function activeMembers(array $ids, $exclude_id)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $result = [];
        foreach (\Kotchasan\DB::create()->select('user', [['id', $ids], ['active', 1], ['id', '!=', (int) $exclude_id]], [], ['id']) as $item) {
            $result[] = (int) $item->id;
        }

        return $result;
    }

    /**
     * บันทึกรายชื่อผู้รับใหม่ทั้งชุด ผู้รับเดิมที่ยังอยู่คงจำนวน/วันที่ดาวน์โหลดไว้ (เหมือนระบบเดิม)
     *
     * @param int $document_id
     * @param array $receivers [member_id => department_id ('' = รายบุคคล)]
     *
     * @return void
     */
    public static function saveReceivers($document_id, array $receivers)
    {
        $db = \Kotchasan\DB::create();
        $rows = [];
        foreach ($receivers as $member_id => $department_id) {
            $rows[(int) $member_id] = [
                'document_id' => (int) $document_id,
                'member_id' => (int) $member_id,
                'department_id' => (string) $department_id,
                'downloads' => 0,
                'last_update' => 0
            ];
        }
        foreach ($db->select('edocument_download', [['document_id', (int) $document_id]]) as $item) {
            if (isset($rows[(int) $item->member_id])) {
                $rows[(int) $item->member_id]['downloads'] = (int) $item->downloads;
                $rows[(int) $item->member_id]['last_update'] = (int) $item->last_update;
            }
        }
        $db->delete('edocument_download', [['document_id', (int) $document_id]], 0);
        foreach ($rows as $row) {
            $db->insert('edocument_download', $row);
        }
    }

    /**
     * ลบเอกสาร ไฟล์ และรายชื่อผู้รับ
     *
     * @param array $ids
     *
     * @return void
     */
    public static function remove(array $ids)
    {
        if (empty($ids)) {
            return;
        }
        $db = \Kotchasan\DB::create();
        foreach ($db->select('edocument', [['id', $ids]], [], ['file']) as $item) {
            if ($item->file !== '' && is_file(self::dir().$item->file)) {
                @unlink(self::dir().$item->file);
            }
        }
        $db->delete('edocument', [['id', $ids]], 0);
        $db->delete('edocument_download', [['document_id', $ids]], 0);
    }

    /**
     * นับการดาวน์โหลดของผู้รับ (ดาวน์โหลด = ลงชื่อรับเอกสาร)
     * เฉพาะผู้ที่อยู่ในรายชื่อผู้รับ ผู้ส่ง/ผู้จัดการที่ไม่ได้เป็นผู้รับไม่นับ เหมือนระบบเดิม
     *
     * @param int $document_id
     * @param int $member_id
     *
     * @return bool นับแล้ว
     */
    public static function recordDownload($document_id, $member_id)
    {
        $recipient = self::recipient($document_id, $member_id);
        if (!$recipient) {
            return false;
        }
        \Kotchasan\DB::create()->update('edocument_download', [
            ['document_id', (int) $document_id],
            ['member_id', (int) $member_id]
        ], [
            'downloads' => (int) $recipient->downloads + 1,
            'last_update' => time()
        ]);

        return true;
    }

    /**
     * แจ้งผู้รับทางอีเมล LINE และ Telegram (ช่องทางที่ตั้งค่าไว้) เหมือนระบบเดิม
     * ระบบเดิมแจ้งเฉพาะคนในแผนกเมื่อเลือกแผนก ผู้ที่เลือกรายบุคคลเพิ่มจึงไม่ได้รับแจ้ง
     * ที่นี่แจ้งผู้รับทุกคนของเอกสาร
     *
     * @param array $member_ids ผู้รับ
     * @param array $document document_no, topic, last_update
     *
     * @return string ข้อความผลการส่ง
     */
    public static function notify(array $member_ids, array $document)
    {
        $msg = Language::trans(implode("\n", [
            '{LNG_E-Document}',
            '{LNG_Document No.}: '.$document['document_no'],
            '{LNG_Document title}: '.$document['topic'],
            '{LNG_Date}: '.Date::format($document['last_update']),
            '',
            '{LNG_A new document has been sent to you. Please check back.}',
            'URL: '.WEB_URL.'edocument'
        ]));
        $emails = [];
        $lines = [];
        $telegrams = [];
        if (!empty(self::$cfg->telegram_chat_id)) {
            $telegrams[self::$cfg->telegram_chat_id] = self::$cfg->telegram_chat_id;
        }
        $member_ids = array_values(array_filter(array_map('intval', $member_ids)));
        if (!empty($member_ids)) {
            $query = static::createQuery()
                ->select('name', 'username', 'line_uid', 'telegram_id')
                ->from('user')
                ->where([['id', $member_ids], ['active', 1]]);
            foreach ($query->fetchAll() as $item) {
                if (!empty($item->username) && filter_var($item->username, FILTER_VALIDATE_EMAIL)) {
                    $emails[$item->username] = $item->name.'<'.$item->username.'>';
                }
                if (!empty($item->line_uid)) {
                    $lines[$item->line_uid] = $item->line_uid;
                }
                if (!empty($item->telegram_id)) {
                    $telegrams[$item->telegram_id] = $item->telegram_id;
                }
            }
        }
        $errors = [];
        if (!empty($emails) && !empty(self::$cfg->noreply_email)) {
            $subject = '['.\Kotchasan\Text::plainText(self::$cfg->web_title).'] '.Language::get('There are new documents sent to you.');
            $err = \Kotchasan\Email::send(implode(',', $emails), self::$cfg->noreply_email, $subject, nl2br($msg));
            if ($err->error()) {
                $errors[] = strip_tags($err->getErrorMessage());
            }
        }
        if (!empty($telegrams) && !empty(self::$cfg->telegram_bot_token)) {
            $err = \Gcms\Telegram::sendTo(array_values($telegrams), $msg);
            if ($err != '') {
                $errors[] = $err;
            }
        }
        if (!empty($lines) && !empty(self::$cfg->line_channel_access_token)) {
            $err = \Gcms\Line::sendTo(array_values($lines), $msg);
            if ($err != '') {
                $errors[] = $err;
            }
        }

        return empty($errors) ? 'Your message was sent successfully' : implode("\n", array_unique($errors));
    }
}
