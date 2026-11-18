<?php
/**
 * @filesource modules/edocument/controllers/view.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\View;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * api/edocument/view/download (POST) และ api/edocument/view/file (GET)
 *
 * ดาวน์โหลดเอกสาร = ลงชื่อรับเอกสาร (ผู้รับยืนยันก่อนดาวน์โหลด เหมือนระบบเดิม)
 *   download นับการดาวน์โหลดของผู้รับ แล้วตอบลิงก์ไฟล์กลับไป
 *   file     ส่งไฟล์ ตรวจสิทธิ์ทุกครั้ง (ผู้รับ ผู้ส่ง ผู้จัดการ) ลิงก์ที่หลุดไปจึงใช้กับคนอื่นไม่ได้
 *            (ระบบเดิมเก็บไฟล์ไว้ใน session แล้วเปิดผ่าน modules/edocument/filedownload.php)
 * ตั้งค่า "เมื่อคลิกดาวน์โหลด" = เปิดไฟล์ (ที่รู้จัก) จะเปิด pdf/รูปในเบราว์เซอร์แทนการดาวน์โหลด
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function download(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            $document = \Edocument\Document\Model::get($request->post('id')->toInt());
            if (!$document || !\Edocument\Document\Model::canRead($login, $document)) {
                return $this->errorResponse('Sorry, Item not found It&#39;s may be deleted', 404);
            }
            if ($document->file === '' || !is_file(\Edocument\Document\Model::dir().$document->file)) {
                return $this->errorResponse('File not found', 404);
            }
            if (ApiController::isNotDemoMode($login)) {
                // นับเฉพาะผู้ที่อยู่ในรายชื่อผู้รับ
                \Edocument\Document\Model::recordDownload($document->id, $login->id);
            }
            $url = WEB_URL.'api/edocument/view/file?id='.(int) $document->id;
            if (\Edocument\Document\Model::opensInline($document)) {
                $action = ['type' => 'redirect', 'url' => $url, 'target' => '_blank'];
            } else {
                $action = ['type' => 'download', 'url' => $url, 'filename' => \Edocument\Document\Model::filename($document)];
            }

            return $this->successResponse([
                'actions' => [
                    $action,
                    ['type' => 'modal', 'action' => 'close'],
                    ['type' => 'redirect', 'url' => 'reload', 'target' => 'edocumentInbox']
                ]
            ], '');
        } catch (\Exception $e) {
            return $this->errorResponse(self::exceptionMessage($e), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * ส่งไฟล์ (attachment=1 = ดาวน์โหลดเสมอ ใช้กับปุ่มดาวน์โหลดของเอกสารขาออก)
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response|void
     */
    public function file(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            $document = \Edocument\Document\Model::get($request->get('id')->toInt());
            if (!$document || !\Edocument\Document\Model::canRead($login, $document)) {
                return $this->errorResponse('Sorry, Item not found It&#39;s may be deleted', 404);
            }
            $file = \Edocument\Document\Model::dir().$document->file;
            if ($document->file === '' || !is_file($file)) {
                return $this->errorResponse('File not found', 404);
            }
            while (ob_get_level()) {
                ob_end_clean();
            }
            if ($request->get('attachment')->toInt() !== 1 && \Edocument\Document\Model::opensInline($document)) {
                header('Content-Type: '.\Kotchasan\Mime::get($document->ext));
                header('Content-Disposition: inline');
            } else {
                $name = \Edocument\Document\Model::filename($document);
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="'.str_replace(['"', "\r", "\n"], '', $name).'"; filename*=UTF-8\'\''.rawurlencode($name));
            }
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: private, must-revalidate');
            header('Pragma: public');
            header('Content-Length: '.filesize($file));
            readfile($file);
            exit;
        } catch (\Exception $e) {
            return $this->errorResponse(self::exceptionMessage($e), $e->getCode() ?: 500, $e);
        }
    }
}
