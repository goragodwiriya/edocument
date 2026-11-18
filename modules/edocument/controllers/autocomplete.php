<?php
/**
 * @filesource modules/edocument/controllers/autocomplete.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Autocomplete;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * api/edocument/autocomplete/users?q= — ค้นสมาชิกให้ช่องผู้รับของฟอร์มส่งเอกสาร
 * (Edocument\Autocomplete\Model::findUser ของระบบเดิม)
 * สมาชิกที่ใช้งานอยู่ ยกเว้นตัวเอง ค้นจากชื่อหรือชื่อผู้ใช้ คืน [{value, text}]
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * จำนวนรายการสูงสุดต่อการค้นหนึ่งครั้ง
     */
    const LIMIT = 20;

    /**
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function users(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, 'can_upload_edocument')) {
                return $this->errorResponse('Permission required', 403);
            }
            $search = $request->get('q')->topic();
            if ($search === '') {
                return $this->successResponse([], 'Search completed');
            }
            $keyword = '%'.$search.'%';
            $query = \Kotchasan\Model::createQuery()
                ->select('id', 'name', 'username')
                ->from('user')
                ->where([
                    ['active', 1],
                    ['id', '!=', (int) $login->id]
                ])
                ->where([
                    ['name', 'LIKE', $keyword],
                    ['username', 'LIKE', $keyword]
                ], 'OR')
                ->orderBy('name')
                ->limit(self::LIMIT);
            $result = [];
            foreach ($query->fetchAll() as $row) {
                $result[] = [
                    'value' => (string) $row->id,
                    'text' => \Edocument\Document\Model::memberLabel($row)
                ];
            }

            return $this->successResponse($result, 'Search completed');
        } catch (\Exception $e) {
            return $this->errorResponse(self::exceptionMessage($e), $e->getCode() ?: 500, $e);
        }
    }
}
