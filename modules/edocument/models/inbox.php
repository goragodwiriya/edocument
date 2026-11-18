<?php
/**
 * @filesource modules/edocument/models/inbox.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Inbox;

/**
 * เอกสารขาเข้า — เอกสารที่สมาชิกอยู่ในรายชื่อผู้รับ
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
     * @param array $params search, urgency (-1 = ทั้งหมด), sender (0 = ทั้งหมด)
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable(array $params, $login)
    {
        $query = static::createQuery()
            ->select('A.id', 'A.document_no', 'A.urgency', 'E.downloads', 'A.ext', 'A.topic', 'A.sender_id', 'A.size', 'A.last_update')
            ->from('edocument_download E')
            ->join('edocument A', [['A.id', 'E.document_id']], 'INNER')
            ->where([['E.member_id', (int) $login->id]]);
        self::applyFilters($query, $params);

        return $query;
    }

    /**
     * ตัวกรองที่หน้าเอกสารขาเข้าและขาออกใช้ร่วมกัน
     *
     * @param \Kotchasan\QueryBuilder\QueryBuilderInterface $query
     * @param array $params
     *
     * @return void
     */
    public static function applyFilters($query, array $params)
    {
        if (isset($params['urgency']) && $params['urgency'] > -1) {
            $query->where([['A.urgency', (int) $params['urgency']]]);
        }
        if (!empty($params['sender'])) {
            $query->where([['A.sender_id', (int) $params['sender']]]);
        }
        if (!empty($params['search'])) {
            $keyword = '%'.$params['search'].'%';
            $query->where([
                ['A.topic', 'LIKE', $keyword],
                ['A.document_no', 'LIKE', $keyword]
            ], 'OR');
        }
    }

    /**
     * จำนวนเอกสารใหม่ที่ยังไม่ได้ดาวน์โหลด (การ์ดหน้าแรก)
     *
     * @param object $login
     *
     * @return int
     */
    public static function countNew($login)
    {
        $result = static::createQuery()
            ->selectCount('E.document_id')
            ->from('edocument_download E')
            ->join('edocument A', [['A.id', 'E.document_id']], 'INNER')
            ->where([
                ['E.member_id', (int) $login->id],
                ['E.downloads', 0]
            ])
            ->first();

        return $result ? (int) $result->count : 0;
    }

    /**
     * ผู้ส่ง [{value, text}] (เฉพาะคนที่เคยส่งเอกสาร)
     *
     * @param int $only 0 = ทุกคน, อื่น ๆ = เฉพาะคนนี้
     *
     * @return array
     */
    public static function senders($only = 0)
    {
        $query = static::createQuery()
            ->select('U.id', 'U.name')
            ->from('user U')
            ->whereExists('edocument E', [['E.sender_id', 'U.id']])
            ->orderBy('U.name');
        if ($only > 0) {
            $query->where([['U.id', (int) $only]]);
        }
        $result = [];
        foreach ($query->fetchAll() as $item) {
            $result[] = ['value' => (string) $item->id, 'text' => $item->name];
        }

        return $result;
    }
}
