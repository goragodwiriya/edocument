<?php
/**
 * @filesource modules/edocument/models/download.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Download;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * โมเดลสำหรับดาวน์โหลดเอกสาร
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * @param Request $request
     */
    public function index(Request $request)
    {
        $ret = [];
        // session, referer, member
        if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
            if (preg_match('/download_([0-9]+)/', $request->post('id')->toString(), $match)) {
                // อ่านรายการที่เลือก
                $result = $this->db()->createQuery()
                    ->from('edocument E')
                    ->join('edocument_download D', 'LEFT', array(array('D.document_id', 'E.id'), array('D.member_id', (int) $login['id'])))
                    ->where(array('E.id', (int) $match[1]))
                    ->groupBy('E.id')
                    ->first('E.id', 'E.topic', 'D.downloads', 'E.file', 'E.ext', 'E.size');
                if ($result) {
                    // ไฟล์
                    $file = ROOT_PATH.DATA_FOLDER.'edocument/'.$result->file;
                    if (is_file($file)) {
                        // อัปเดตดาวน์โหลด
                        $this->db()->update($this->getTableName('edocument_download'), array(
                            array('document_id', $result->id),
                            array('member_id', (int) $login['id'])
                        ), array(
                            'downloads' => (int) $result->downloads + 1,
                            'last_update' => time()
                        ));
                        // id สำหรบไฟล์ดาวน์โหลด
                        $id = uniqid();
                        // บันทึกรายละเอียดการดาวน์โหลดลง SESSION
                        $file = array(
                            'file' => $file,
                            'size' => $result->size
                        );
                        if (!empty(self::$cfg->edocument_download_action) && in_array($result->ext, self::$cfg->know_file_typies)) {
                            $file['name'] = '';
                            $file['mime'] = \Kotchasan\Mime::get($result->ext);
                        } else {
                            $file['name'] = preg_replace('/[,;:_\-\(\)\?\&\+\[\]\s]{1,}/', '_', $result->topic).'.'.$result->ext;
                            $file['mime'] = 'application/octet-stream';
                        }
                        $_SESSION[$id] = $file;
                        // คืนค่า
                        $ret['target'] = self::$cfg->edocument_download_action;
                        $ret['url'] = WEB_URL.'modules/edocument/filedownload.php?id='.$id;
                    } else {
                        // ไม่พบไฟล์
                        $ret['alert'] = Language::get('File not found');
                    }
                    $ret['modal'] = 'close';
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }

    /**
     * อ่านเอกสารที่ $id
     * ไม่พบ คืนค่า null
     *
     * @param int $id
     *
     * @return object
     */
    public static function get($id, $login)
    {
        $search = static::createQuery()
            ->from('edocument A')
            ->join('edocument_download E', 'INNER', array(array('E.id', 'A.id'), array('E.member_id', (int) $login['id'])))
            ->where(array('A.id', $id))
            ->first('A.id', 'A.document_no', 'E.downloads', 'A.topic', 'A.ext', 'A.sender_id', 'A.size', 'A.last_update', 'A.department', 'A.detail');
        if ($search) {
            $search->department = explode(',', trim($search->department, ','));
        }

        return $search;
    }
}
