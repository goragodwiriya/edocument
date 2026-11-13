<?php
/**
 * @filesource modules/edocument/controllers/init.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Init;

use Gcms\Api as ApiController;
use Kotchasan\Language;

/**
 * สิทธิ์ เมนู และการ์ดหน้าแรกของโมดูลงานสารบรรณ
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Controller
{
    /**
     * สิทธิ์ของโมดูล (ชื่อเดียวกับระบบเดิม สมาชิกเดิมไม่ต้องตั้งค่าใหม่)
     *
     * @param array $permissions
     * @param mixed $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initPermission($permissions, $params = null, $login = null)
    {
        $permissions[] = ['value' => 'can_handle_all_edocument', 'text' => '{LNG_Can manage the} {LNG_E-Document}'];
        $permissions[] = ['value' => 'can_upload_edocument', 'text' => '{LNG_Can upload your document file} ({LNG_E-Document})'];

        return $permissions;
    }

    /**
     * เมนูของโมดูล (เหมือนระบบเดิม)
     * ผู้ส่งเอกสารได้ (can_upload_edocument) มีเมนูงานสารบรรณ = เอกสารขาเข้า + เอกสารขาออก
     * สมาชิกอื่นมีเฉพาะเอกสารขาเข้า · ตั้งค่าโมดูลอยู่ในเมนูตั้งค่า (can_config)
     *
     * @param array $menus
     * @param mixed $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initMenus($menus, $params = null, $login = null)
    {
        if (!$login) {
            return $menus;
        }

        if (ApiController::hasPermission($login, 'can_upload_edocument')) {
            $item = [
                'title' => '{LNG_E-Document}',
                'icon' => 'icon-edocument',
                'children' => [
                    ['title' => '{LNG_Received document}', 'url' => '/edocument', 'icon' => 'icon-inbox'],
                    ['title' => '{LNG_Sent document}', 'url' => '/edocument-sent', 'icon' => 'icon-outbox']
                ]
            ];
        } else {
            $item = ['title' => '{LNG_Received document}', 'url' => '/edocument', 'icon' => 'icon-edocument'];
        }
        $menus = parent::insertMenuByKey($menus, 'edocument', $item, 'dashboard');

        if (ApiController::hasPermission($login, 'can_config')) {
            $menus = parent::insertMenuChildren($menus, [
                [
                    'title' => '{LNG_E-Document}',
                    'icon' => 'icon-edocument',
                    'children' => [
                        [
                            'title' => '{LNG_Module Settings}',
                            'url' => '/edocument-settings',
                            'icon' => 'icon-cog'
                        ]
                    ]
                ]
            ], 'settings', null, 1);
        }

        return $menus;
    }

    /**
     * การ์ดหน้าแรก จำนวนเอกสารใหม่ที่ยังไม่ได้รับ (แสดงทุกครั้งที่เข้าระบบ เหมือนระบบเดิม)
     *
     * @param array $cards
     * @param mixed $params
     * @param object|null $login
     *
     * @return array
     */
    public static function initDashboardCards($cards, $params = null, $login = null)
    {
        if (!$login) {
            return $cards;
        }
        // ข้อมูลล้วน: จำนวนดิบ · บรรทัดล่างเป็นชิ้นส่วน (หน้าแรกจัดรูปและแปลเอง)
        $cards[] = [
            'title' => Language::get('E-Document'),
            'value' => \Edocument\Inbox\Model::countNew($login),
            'unit' => '',
            'icon' => 'icon-edocument',
            'url' => '/edocument',
            'hint' => [['text' => Language::get('New document')]]
        ];

        return $cards;
    }
}
