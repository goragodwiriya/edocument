<?php
/**
 * @filesource modules/edocument/models/autocomplete.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Autocomplete;

use Gcms\Login;
use Kotchasan\Http\Request;

/**
 * ค้นหาสมาชิก สำหรับ autocomplete
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ค้นหาสมาชิก สำหรับ autocomplete
     * คืนค่าเป็น JSON
     *
     * @param Request $request
     */
    public function findUser(Request $request)
    {
        if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
            $search = $request->post('receiver')->topic();
            if ($search != '') {
                $where = array(
                    array('active', 1),
                    array('id', '!=', $login['id'])
                );
                $where2 = array(
                    array('name', 'LIKE', "%$search%"),
                    array('username', 'LIKE', "%$search%")
                );
                $result = $this->db()->createQuery()
                    ->select('id', 'username receiver', 'name')
                    ->from('user')
                    ->where($where)
                    ->andWhere($where2, 'OR')
                    ->order('username')
                    ->limit($request->post('count')->toInt())
                    ->toArray()
                    ->execute();
                // คืนค่า JSON
                if (!empty($result)) {
                    echo json_encode($result);
                }
            }
        }
    }
}
