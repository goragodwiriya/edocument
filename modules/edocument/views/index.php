<?php
/**
 * @filesource modules/edocument/views/index.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Index;

use Kotchasan\DataTable;
use Kotchasan\Date;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=edocument
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Gcms\View
{
    /**
     * @var object
     */
    private $sender;
    /**
     * @var array
     */
    private $urgencies;

    /**
     * แสดงรายการเอกสารรับ
     *
     * @param Request $request
     * @param array $login
     *
     * @return string
     */
    public function render(Request $request, $login)
    {
        // ค่าที่ส่งมา
        $params = array(
            'urgency' => $request->request('urgency', -1)->toInt(),
            'sender' => $request->request('sender')->toInt(),
            'member_id' => $login['id']
        );
        $urgencies = Language::get('URGENCIES');
        $this->urgencies = array_map(array('Edocument\View\View', 'urgencyStyle'), array_keys($urgencies), array_values($urgencies));
        // รายชื่อผู้ส่ง
        $this->sender = \Edocument\Sender\Model::init();
        // URL สำหรับส่งให้ตาราง
        $uri = $request->createUriWithGlobals(WEB_URL.'index.php');
        // ตาราง
        $table = new DataTable(array(
            /* Uri */
            'uri' => $uri,
            /* Model */
            'model' => \Edocument\Index\Model::toDataTable($params),
            /* รายการต่อหน้า */
            'perPage' => $request->cookie('edocumentIndexPerPage', 30)->toInt(),
            /* เรียงลำดับ */
            'sort' => 'new,last_update DESC',
            /* ฟังก์ชั่นจัดรูปแบบการแสดงผลแถวของตาราง */
            'onRow' => array($this, 'onRow'),
            /* คอลัมน์ที่ไม่ต้องแสดงผล */
            'hideColumns' => array('id'),
            /* ตัวเลือกด้านบนของตาราง ใช้จำกัดผลลัพท์การ query */
            'filters' => array(
                array(
                    'name' => 'urgency',
                    'text' => '{LNG_Urgency}',
                    'options' => array(-1 => '{LNG_all items}') + $this->urgencies,
                    'value' => $params['urgency']
                ),
                array(
                    'name' => 'sender',
                    'text' => '{LNG_Sender}',
                    'options' => array(0 => '{LNG_all items}') + $this->sender->toSelect(),
                    'value' => $params['sender']
                )
            ),
            /* ตั้งค่าการกระทำของของตัวเลือกต่างๆ ด้านล่างตาราง ซึ่งจะใช้ร่วมกับการขีดถูกเลือกแถว */
            'action' => 'index.php/edocument/model/index/action',
            'actionCallback' => 'dataTableActionCallback',
            /* คอลัมน์ที่สามารถค้นหาได้ */
            'searchColumns' => array('topic', 'document_no'),
            /* ส่วนหัวของตาราง และการเรียงลำดับ (thead) */
            'headers' => array(
                'document_no' => array(
                    'text' => '{LNG_Document No.}'
                ),
                'urgency' => array(
                    'text' => '{LNG_Urgency}',
                    'class' => 'center'
                ),
                'downloads' => array(
                    'text' => '',
                    'colspan' => 2
                ),
                'topic' => array(
                    'text' => '{LNG_Document title}'
                ),
                'sender_id' => array(
                    'text' => '{LNG_Sender}',
                    'class' => 'center'
                ),
                'size' => array(
                    'text' => '{LNG_Size of} {LNG_File}',
                    'class' => 'center'
                ),
                'last_update' => array(
                    'text' => '{LNG_Date}',
                    'class' => 'center'
                )
            ),
            /* รูปแบบการแสดงผลของคอลัมน์ (tbody) */
            'cols' => array(
                'document_no' => array(
                    'class' => 'nowrap'
                ),
                'urgency' => array(
                    'class' => 'center'
                ),
                'downloads' => array(
                    'class' => 'center'
                ),
                'ext' => array(
                    'class' => 'center'
                ),
                'sender_id' => array(
                    'class' => 'center nowrap'
                ),
                'size' => array(
                    'class' => 'center'
                ),
                'last_update' => array(
                    'class' => 'center nowrap'
                )
            ),
            /* ปุ่มแสดงในแต่ละแถว */
            'buttons' => array(
                'detail' => array(
                    'class' => 'icon-search button brown notext',
                    'id' => ':id',
                    'title' => '{LNG_Detail}'
                )
            )
        ));
        // save cookie
        setcookie('edocumentIndexPerPage', $table->perPage, time() + 2592000, '/', HOST, HTTPS, true);
        // คืนค่า HTML
        return $table->render();
    }

    /**
     * จัดรูปแบบการแสดงผลในแต่ละแถว
     *
     * @param array  $item ข้อมูลแถว
     * @param int    $o    ID ของข้อมูล
     * @param object $prop กำหนด properties ของ TR
     *
     * @return array คืนค่า $item กลับไป
     */
    public function onRow($item, $o, $prop)
    {
        if (empty($item['downloads'])) {
            $item['downloads'] = '<span class="icon-email-unread color-red notext" title="{LNG_New}"></span>';
        } else {
            $item['downloads'] = '<span class="icon-email-read color-green notext" title="{LNG_Received}"></span>';
        }
        $item['sender_id'] = $this->sender->get($item['sender_id']);
        $item['topic'] = '<span class=one_line title="'.$item['topic'].'">'.$item['topic'].'</span>';
        $item['last_update'] = Date::format($item['last_update'], 'd M Y');
        $item['ext'] = '<img src="'.(is_file(ROOT_PATH.'skin/ext/'.$item['ext'].'.png') ? WEB_URL.'skin/ext/'.$item['ext'].'.png' : WEB_URL.'skin/ext/file.png').'" alt="'.$item['ext'].'">';
        $item['urgency'] = isset($this->urgencies[$item['urgency']]) ? $this->urgencies[$item['urgency']] : '';
        return $item;
    }
}
