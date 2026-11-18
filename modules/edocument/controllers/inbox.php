<?php
/**
 * @filesource modules/edocument/controllers/inbox.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Inbox;

use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * api/edocument/inbox — เอกสารขาเข้า (module=edocument ของระบบเดิม)
 * สมาชิกทุกคนเห็นเอกสารที่ตัวเองอยู่ในรายชื่อผู้รับ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * @var array
     */
    protected $allowedSortColumns = ['id', 'document_no', 'urgency', 'topic', 'sender_id', 'size', 'last_update', 'downloads'];

    /**
     * @param Request $request
     * @param object $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        return $login ? true : $this->errorResponse('Unauthorized', 401);
    }

    /**
     * ความเร่งด่วนจากตัวกรอง ('' = ทั้งหมด เพราะ 0 คือด่วนมาก)
     *
     * @param Request $request
     *
     * @return int -1 = ทั้งหมด
     */
    public static function urgencyParam(Request $request)
    {
        $urgency = $request->get('urgency')->toString();

        return $urgency === '' ? -1 : (int) $urgency;
    }

    /**
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'urgency' => self::urgencyParam($request),
            'sender' => $request->get('sender')->toInt()
        ];
    }

    /**
     * @param array $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable(array $params, $login)
    {
        return Model::toDataTable($params, $login);
    }

    /**
     * @param array $datas
     * @param object $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        return self::decorate($datas);
    }

    /**
     * ข้อมูลแถวที่ใช้ร่วมกับเอกสารขาออก (ข้อมูลล้วน หน้าเว็บจัดรูปเอง)
     *
     * @param array $datas
     *
     * @return array
     */
    public static function decorate(array $datas)
    {
        $senders = [];
        foreach ($datas as $item) {
            $senders[(int) $item->sender_id] = (int) $item->sender_id;
        }
        $names = [];
        if (!empty($senders)) {
            foreach (\Kotchasan\DB::create()->select('user', [['id', array_values($senders)]], [], ['id', 'name']) as $user) {
                $names[(int) $user->id] = $user->name;
            }
        }
        foreach ($datas as $item) {
            $item->urgency = (int) $item->urgency;
            $item->sender = $names[(int) $item->sender_id] ?? '';
            $item->ext_icon = \Edocument\Document\Model::extIcon($item->ext);
            $item->size = (float) $item->size;
            $item->date = empty($item->last_update) ? null : date('Y-m-d H:i:s', (int) $item->last_update);
            if (isset($item->downloads)) {
                $item->downloads = (int) $item->downloads;
                $item->is_new = $item->downloads === 0;
            }
        }

        return $datas;
    }

    /**
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters(array $params, $login)
    {
        return [
            'urgency' => \Edocument\Document\Model::urgencyOptions(),
            'sender' => Model::senders()
        ];
    }

    /**
     * รายละเอียดของเอกสาร (modal) พร้อมปุ่มดาวน์โหลด
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDetailAction(Request $request, $login)
    {
        $document = \Edocument\Document\Model::get($request->post('id')->toInt());
        if (!$document || !\Edocument\Document\Model::canRead($login, $document)) {
            return $this->errorResponse('Sorry, Item not found It&#39;s may be deleted', 404);
        }
        $mine = \Edocument\Document\Model::recipient($document->id, $login->id);
        $sender = \Kotchasan\DB::create()->first('user', [['id', (int) $document->sender_id]], ['name']);
        $urgencies = Language::get('URGENCIES', []);

        return $this->successResponse([
            'data' => [
                'id' => (int) $document->id,
                'document_no' => $document->document_no,
                'urgency' => (int) $document->urgency,
                'urgency_text' => $urgencies[(int) $document->urgency] ?? '',
                'topic' => $document->topic,
                'sender' => $sender ? $sender->name : '',
                'date' => empty($document->last_update) ? null : date('Y-m-d H:i:s', (int) $document->last_update),
                'detail' => $document->detail,
                // ยังไม่เคยดาวน์โหลด (ระบบเดิม: downloads ว่างหรือ 0 = ใหม่)
                'is_new' => !$mine || (int) $mine->downloads === 0,
                'size' => (float) $document->size
            ],
            'actions' => [
                [
                    'type' => 'modal',
                    'action' => 'open',
                    'template' => 'edocument/view.html',
                    'title' => '{LNG_Details of} {LNG_Document}'
                ]
            ]
        ], '');
    }
}
