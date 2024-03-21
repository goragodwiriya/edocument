<?php
/**
 * @filesource modules/edocument/models/write.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Write;

use Gcms\Login;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;
use Kotchasan\Number;

/**
 * module=edocument-write
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านข้อมูลรายการที่เลือก
     * ถ้า $id = 0 หมายถึงรายการใหม่
     *
     * @param int   $id    ID
     * @param array $login
     *
     * @return object|null คืนค่าข้อมูล object ไม่พบคืนค่า null
     */
    public static function get($id, $login)
    {
        if (empty($id)) {
            // ใหม่
            return (object) array(
                'id' => 0,
                'document_no' => '',
                'sender_id' => (int) $login['id'],
                // ID ผู้รับเริ่มต้น
                'receiver' => [],
                // แผนกผู้รับเริ่มต้น
                'department' => [],
                'urgency' => 2,
                'topic' => '',
                'detail' => ''
            );
        } else {
            // แก้ไข อ่านรายการที่เลือก
            $result = static::createQuery()
                ->from('edocument E')
                ->join('edocument_download D', 'LEFT', array(array('D.document_id', 'E.id'), array('D.department_id', '')))
                ->join('user U', 'LEFT', array('U.id', 'D.member_id'))
                ->where(array('E.id', $id))
                ->groupBy('E.id')
                ->first('E.*', "SQL(GROUP_CONCAT(CONCAT(U.`id`,':',U.`username`) SEPARATOR ',') AS `receiver`)");
            if ($result) {
                $result->department = explode(',', trim($result->department, ','));
                $receiver = [];
                if ($result->receiver != '') {
                    foreach (explode(',', $result->receiver) as $item) {
                        $ds = explode(':', $item);
                        $receiver[$ds[0]] = isset($ds[1]) ? $ds[1] : $ds[0];
                    }
                }
                $result->receiver = $receiver;
            }
            return $result;
        }
    }

    /**
     * บันทึกข้อมูลที่ส่งมาจากฟอร์ม (write.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, member, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::notDemoMode($login)) {
                try {
                    // ค่าที่ส่งมา
                    $save = array(
                        'document_no' => $request->post('document_no')->topic(),
                        // ผู้รับตามแผนก
                        'department' => $request->post('department', [])->topic(),
                        'urgency' => $request->post('urgency')->toInt(),
                        'topic' => $request->post('topic')->topic(),
                        'detail' => $request->post('detail')->textarea()
                    );
                    // ตรวจสอบรายการที่เลือก
                    $index = self::get($request->post('id')->toInt(), $login);
                    if ($index && ($index->id == 0 || $login['id'] == $index->sender_id || Login::checkPermission($login, 'can_upload_edocument'))) {
                        // Database
                        $db = $this->db();
                        // Table
                        $table_edocument = $this->getTableName('edocument');
                        if ($index->id == 0) {
                            $save['id'] = $db->getNextId($table_edocument);
                        } else {
                            $save['id'] = $index->id;
                        }
                        if ($save['document_no'] == '') {
                            // ไม่ได้กรอกเลขที่เอกสาร
                            $save['document_no'] = \Index\Number\Model::get($save['id'], 'edocument_format_no', $table_edocument, 'document_no', self::$cfg->edocument_prefix);
                        } else {
                            // ตรวจสอบเลขที่เอกสารซ้ำ
                            $search = $db->first($table_edocument, array('document_no', $save['document_no']));
                            if ($search && ($index->id == 0 || $index->id != $search->id)) {
                                $ret['ret_document_no'] = Language::replace('This :name already exist', array(':name' => 'Document No.'));
                            }
                        }
                        if ($save['detail'] == '') {
                            // ไม่ได้กรอก detail
                            $ret['ret_detail'] = 'Please fill in';
                        }
                        // รายชื่อผู้รับ
                        $receiver = [];
                        // รายชื่อผู้รับ (ตามแผนกที่เลือก)
                        if (!empty($save['department'])) {
                            // query สมาชิกตามแผนกที่เลือก
                            $where = array(
                                array('U.active', 1),
                                array('U.id', '!=', $login['id']),
                                array('D.value', $save['department'])
                            );
                            $query = $this->db()->createQuery()
                                ->select('U.id', 'D.value department')
                                ->from('user U')
                                ->join('user_meta D', 'LEFT', array(array('D.member_id', 'U.id'), array('D.name', 'department')))
                                ->where($where);
                            foreach ($query->execute() as $item) {
                                $receiver[$item->id] = $item->department;
                            }
                        }
                        // รายชื่อผู้รับ (รายบุคคล)
                        foreach ($request->post('receiver', [])->toInt() as $receiver_id) {
                            $receiver[$receiver_id] = '';
                        }
                        if (empty($receiver)) {
                            // ไม่ได้ระบุผู้รับ
                            $ret['ret_department'] = Language::get('The recipient was not found. or no recipients found in the selected department.');
                        }
                        if (empty($ret)) {
                            $mktime = time();
                            // อัปโหลดไฟล์
                            $dir = ROOT_PATH.DATA_FOLDER.'edocument/';
                            foreach ($request->getUploadedFiles() as $item => $file) {
                                /* @var $file \Kotchasan\Http\UploadedFile */
                                if ($file->hasUploadFile()) {
                                    if (!File::makeDirectory($dir)) {
                                        // ไดเรคทอรี่ไม่สามารถสร้างได้
                                        $ret['ret_'.$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.'edocument/');
                                    } elseif (!$file->validFileExt(self::$cfg->edocument_file_typies)) {
                                        // ชนิดของไฟล์ไม่ถูกต้อง
                                        $ret['ret_'.$item] = Language::get('The type of file is invalid');
                                    } elseif ($file->getSize() > self::$cfg->edocument_upload_size) {
                                        // ขนาดของไฟล์ใหญ่เกินไป
                                        $ret['ret_'.$item] = Language::get('The file size larger than the limit');
                                    } else {
                                        $save['ext'] = $file->getClientFileExt();
                                        $file_name = str_replace('.'.$save['ext'], '', $file->getClientFilename());
                                        if ($file_name == '' && $save['topic'] == '') {
                                            $ret['ret_topic'] = 'Please fill in';
                                        } else {
                                            // อัปโหลด
                                            $save['file'] = $mktime.'.'.$save['ext'];
                                            while (file_exists($dir.$save['file'])) {
                                                ++$mktime;
                                                $save['file'] = $mktime.'.'.$save['ext'];
                                            }
                                            try {
                                                $file->moveTo($dir.$save['file']);
                                                $save['size'] = $file->getSize();
                                                if ($save['topic'] == '') {
                                                    $save['topic'] = $file_name;
                                                }
                                                if (!empty($index->file) && $save['file'] != $index->file) {
                                                    @unlink($dir.$index->file);
                                                }
                                            } catch (\Exception $exc) {
                                                // ไม่สามารถอัปโหลดได้
                                                $ret['ret_'.$item] = Language::get($exc->getMessage());
                                            }
                                        }
                                    }
                                } elseif ($file->hasError()) {
                                    // ข้อผิดพลาดการอัปโหลด
                                    $ret['ret_'.$item] = Language::get($file->getErrorMessage());
                                } elseif ($index->id == 0) {
                                    // ใหม่ ต้องมีไฟล์
                                    $ret['ret_'.$item] = 'Please browse file';
                                }
                            }
                        }
                        if (empty($ret)) {
                            $save['last_update'] = $mktime;
                            $department = $save['department'];
                            $save['department'] = ','.implode(',', $department).',';
                            $save['topic'] = preg_replace('/[,;:_]{1,}/', '_', $save['topic']);
                            if ($index->id == 0) {
                                // ใหม่
                                $save['sender_id'] = $login['id'];
                                $db->insert($table_edocument, $save);
                            } else {
                                // แก้ไข
                                $db->update($table_edocument, $save['id'], $save);
                            }
                            // แอเรย์เก็บรายชื่อผู้รับ
                            $receivers = [];
                            // รายชื่อผู้รับที่เลือก
                            foreach ($receiver as $receiver_id => $department_id) {
                                $receivers[$receiver_id] = array(
                                    'document_id' => $save['id'],
                                    'member_id' => $receiver_id,
                                    'department_id' => $department_id,
                                    'downloads' => 0,
                                    'last_update' => 0
                                );
                            }
                            // ตาราง
                            $table_download = $this->getTableName('edocument_download');
                            // query ผู้รับเดิม
                            foreach ($this->db()->select($table_download, array('document_id', $save['id'])) as $item) {
                                if (isset($receivers[$item['member_id']])) {
                                    $receivers[$item['member_id']]['member_id'] = $item['member_id'];
                                    $receivers[$item['member_id']]['downloads'] = $item['downloads'];
                                    $receivers[$item['member_id']]['last_update'] = $item['last_update'];
                                }
                            }
                            // ลบ ผู้รับเดิม
                            $this->db()->delete($table_download, array('document_id', $save['id']), 0);
                            // บันทึกผู้รับใหม่ลงในตาราง
                            foreach ($receivers as $item) {
                                $this->db()->insert($table_download, $item);
                            }
                            // log
                            \Index\Log\Model::add($save['id'], 'edocument', 'Save', '{LNG_Send Document} ID : '.$save['id'], $login['id']);
                            // คืนค่า
                            if ($request->post('send_mail')->toInt() == 1) {
                                // ส่งอีเมล
                                $ret['alert'] = \Edocument\Email\Model::send(array_keys($receivers), $department, $save);
                            } else {
                                // ไม่ต้องส่งอีเมล
                                $ret['alert'] = Language::get('Saved successfully');
                            }
                            $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'edocument-sent'));
                        }
                    }
                } catch (\Kotchasan\InputItemException $e) {
                    $ret['alert'] = $e->getMessage();
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }
}
