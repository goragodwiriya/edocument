<?php
/**
 * @filesource modules/edocument/models/outbox.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Outbox;

use Kotchasan\Database\Sql;

/**
 * เอกสารขาออก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ห้ามมี orderBy — Gcms\Table ห่อ query นี้เป็น COUNT(*) subquery
     *
     * @param array $params search, urgency (-1 = ทั้งหมด), sender (0 = ทุกคน)
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable(array $params)
    {
        // จำนวนผู้รับ
        $recipients = static::createQuery()
            ->selectRaw('COUNT(*)')
            ->from('edocument_download D')
            ->where([['D.document_id', Sql::column('A.id')]]);
        $query = static::createQuery()
            ->select('A.id', 'A.document_no', 'A.urgency', 'A.ext', 'A.topic', 'A.sender_id', 'A.size', 'A.last_update', [$recipients, 'recipients'])
            ->from('edocument A');
        \Edocument\Inbox\Model::applyFilters($query, $params);

        return $query;
    }
}
