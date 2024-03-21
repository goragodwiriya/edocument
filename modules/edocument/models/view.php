<?php
/**
 * @filesource modules/edocument/models/view.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\View;

/**
 * module=edocument-view
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * คืนค่ารายละเอียดของเอกสารที่ส่ง
     *
     * @param int $document_id
     * @param int $member_id
     *
     * @return object
     */
    public static function get($document_id, $member_id)
    {
        $sql2 = static::createQuery()
            ->select('E.downloads')
            ->from('edocument_download E')
            ->where(array(
                array('E.document_id', 'A.id'),
                array('E.member_id', $member_id)
            ))
            ->limit(1);
        return static::createQuery()
            ->from('edocument A')
            ->where(array('A.id', $document_id))
            ->first('A.id', 'A.document_no', 'A.urgency', array($sql2, 'downloads'), 'A.topic', 'A.ext', 'A.sender_id', 'A.size', 'A.last_update', 'A.detail');
    }
}
