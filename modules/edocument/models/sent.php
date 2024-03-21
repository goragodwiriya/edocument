<?php
/**
 * @filesource modules/edocument/models/sent.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Sent;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=edocument-sent
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Query ข้อมูลสำหรับส่งให้กับ DataTable
     *
     * @param array $params
     *
     * @return \Kotchasan\Database\QueryBuilder
     */
    public static function toDataTable($params)
    {
        $where = [];
        if ($params['sender'] > 0) {
            $where[] = array('A.sender_id', $params['sender']);
        }
        if ($params['urgency'] > -1) {
            $where[] = array('A.urgency', $params['urgency']);
        }
        return static::createQuery()
            ->select('A.id', 'A.document_no', 'A.urgency', 'A.ext', 'A.topic', 'A.sender_id', 'A.size', 'A.last_update', 'SQL(COUNT(D.`document_id`) `downloads`)')
            ->from('edocument A')
            ->join('edocument_download D', 'LEFT', array('D.document_id', 'A.id'))
            ->where($where)
            ->groupBy('A.id');
    }

    /**
     * รับค่าจาก action (sent.php)
     *
     * @param Request $request
     */
    public function action(Request $request)
    {
        $ret = [];
        // session, referer, member, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
            if (Login::notDemoMode($login)) {
                // รับค่าจากการ POST
                $action = $request->post('action')->toString();
                // ตรวจสอบค่าที่ส่งมา
                if (preg_match_all('/,?([0-9]+),?/', $request->post('id')->filter('0-9,'), $match)) {
                    if ($action === 'delete' && Login::checkPermission($login, 'can_upload_edocument')) {
                        // ลบ
                        $query = $this->db()->createQuery()
                            ->select('file')
                            ->from('edocument')
                            ->where(array(
                                array('id', $match[1]),
                                array('file', '!=', '')
                            ))
                            ->toArray();
                        foreach ($query->execute() as $item) {
                            if (file_exists(ROOT_PATH.DATA_FOLDER.'edocument/'.$item['file'])) {
                                // ลบไฟล์
                                unlink(ROOT_PATH.DATA_FOLDER.'edocument/'.$item['file']);
                            }
                        }
                        // ลบข้อมูล
                        $this->db()->createQuery()
                            ->delete('edocument', array('id', $match[1]))
                            ->execute();
                        $this->db()->createQuery()
                            ->delete('edocument_download', array('document_id', $match[1]))
                            ->execute();
                        // Log
                        \Index\Log\Model::add(0, 'edocument', 'Delete', '{LNG_Delete} {LNG_Sent document} ID : '.implode(', ', $match[1]), $login['id']);
                        // reload
                        $ret['location'] = 'reload';
                    } elseif ($action == 'download') {
                        // อ่านรายการที่เลือก
                        $result = $this->db()->createQuery()
                            ->from('edocument E')
                            ->where(array('E.id', (int) $match[1][0]))
                            ->first('E.topic', 'E.file', 'E.ext', 'E.size');
                        if ($result) {
                            $file = ROOT_PATH.DATA_FOLDER.'edocument/'.$result->file;
                            if (is_file($file)) {
                                // id สำหรับไฟล์ดาวน์โหลด
                                $id = md5(uniqid());
                                // บันทึกรายละเอียดการดาวน์โหลดลง SESSION
                                $_SESSION[$id] = array(
                                    'file' => $file,
                                    'name' => preg_replace('/[,;:_\-\(\)\?\&\+\[\]\s]{1,}/', '_', $result->topic).'.'.$result->ext,
                                    'mime' => \Kotchasan\Mime::get($result->ext),
                                    'size' => $result->size
                                );
                                // คืนค่า
                                $ret['location'] = WEB_URL.'modules/edocument/filedownload.php?id='.$id;
                            } else {
                                // ไม่พบไฟล์
                                $ret['alert'] = Language::get('Sorry, Item not found It&#39;s may be deleted');
                            }
                        }
                    }
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
