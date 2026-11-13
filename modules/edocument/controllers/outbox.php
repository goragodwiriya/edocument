<?php
/**
 * @filesource modules/edocument/controllers/outbox.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Outbox;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * api/edocument/outbox — เอกสารขาออก (module=edocument-sent ของระบบเดิม)
 * ผู้ส่งเอกสารได้ (can_upload_edocument) เห็นเอกสารของตัวเอง ผู้จัดการงานสารบรรณเห็นทุกฉบับ
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
    protected $allowedSortColumns = ['id', 'document_no', 'urgency', 'topic', 'sender_id', 'size', 'last_update', 'recipients'];

    /**
     * @param Request $request
     * @param object $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, 'can_upload_edocument')) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
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
            'urgency' => \Edocument\Inbox\Controller::urgencyParam($request),
            // ไม่ใช่ผู้จัดการ เห็นได้แค่เอกสารของตัวเอง (ระบบเดิมบังคับตัวกรองผู้ส่งเป็นตัวเอง)
            'sender' => \Edocument\Document\Model::canHandleAll($login) ? $request->get('sender')->toInt() : (int) $login->id
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
        return Model::toDataTable($params);
    }

    /**
     * @param array $datas
     * @param object $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $datas = \Edocument\Inbox\Controller::decorate($datas);
        foreach ($datas as $item) {
            $item->recipients = (int) $item->recipients;
        }

        return $datas;
    }

    /**
     * ตัวกรองผู้ส่งมีเฉพาะผู้จัดการ (ผู้ส่งทั่วไปเห็นแค่ของตัวเอง)
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters(array $params, $login)
    {
        return [
            'urgency' => \Edocument\Document\Model::urgencyOptions(),
            'sender' => \Edocument\Document\Model::canHandleAll($login) ? \Edocument\Inbox\Model::senders() : false
        ];
    }

    /**
     * เอกสารที่จัดการได้จาก id ที่เลือก
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    private function manageableIds(Request $request, $login)
    {
        $ids = $request->post('ids', [])->toInt();
        if (empty($ids)) {
            $ids = [$request->post('id')->toInt()];
        }
        $ids = array_values(array_filter((array) $ids));
        if (empty($ids)) {
            return [];
        }
        $where = [['id', $ids]];
        if (!\Edocument\Document\Model::canHandleAll($login)) {
            $where[] = ['sender_id', (int) $login->id];
        }
        $result = [];
        foreach (\Kotchasan\DB::create()->select('edocument', $where, [], ['id']) as $item) {
            $result[] = (int) $item->id;
        }

        return $result;
    }

    /**
     * ลบเอกสาร ไฟล์ และรายชื่อผู้รับ (ของตัวเอง หรือทุกฉบับถ้าเป็นผู้จัดการ)
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if ((int) $login->active !== 1 || !ApiController::canModify($login, ['can_upload_edocument'])) {
            return $this->errorResponse('Permission required', 403);
        }
        $ids = $this->manageableIds($request, $login);
        if (empty($ids)) {
            return $this->errorResponse('Delete action failed', 400);
        }
        \Edocument\Document\Model::remove($ids);
        \Index\Log\Model::add(0, 'edocument', 'Delete', '{LNG_Delete} {LNG_Sent document} ID : '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload', \Kotchasan\Language::replace('Deleted :count items', [':count' => count($ids)]), 200, 0, 'table');
    }

    /**
     * ดาวน์โหลดไฟล์ของเอกสารที่ส่ง (ไม่นับเป็นการรับเอกสาร เหมือนระบบเดิม)
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDownloadAction(Request $request, $login)
    {
        $document = \Edocument\Document\Model::get($request->post('id')->toInt());
        if (!$document || !\Edocument\Document\Model::canManage($login, $document)) {
            return $this->errorResponse('Sorry, Item not found It&#39;s may be deleted', 404);
        }
        if ($document->file === '' || !is_file(\Edocument\Document\Model::dir().$document->file)) {
            return $this->errorResponse('Sorry, Item not found It&#39;s may be deleted', 404);
        }

        return $this->successResponse([
            'actions' => [[
                'type' => 'download',
                'url' => WEB_URL.'api/edocument/view/file?id='.(int) $document->id.'&attachment=1',
                'filename' => \Edocument\Document\Model::filename($document)
            ]]
        ], '');
    }

    /**
     * ไปหน้าแก้ไข
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleEditAction(Request $request, $login)
    {
        $document = \Edocument\Document\Model::get($request->post('id')->toInt());
        if (!$document || !\Edocument\Document\Model::canManage($login, $document)) {
            return $this->errorResponse('Permission required', 403);
        }

        return $this->redirectResponse('/edocument-write?id='.(int) $document->id);
    }
}
