<?php
/**
 * @filesource modules/edocument/controllers/document.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Edocument\Document;

use Gcms\Api as ApiController;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;
use Kotchasan\Text;

/**
 * api/edocument/document/get|save — ส่งเอกสาร/แก้ไขเอกสาร (module=edocument-write ของระบบเดิม)
 * ส่งได้: can_upload_edocument · แก้ไขได้: ผู้ส่ง หรือผู้จัดการงานสารบรรณ
 *
 * ผู้รับ = สมาชิกในแผนกที่เลือก + สมาชิกที่เลือกรายบุคคล (ไม่รวมผู้ส่ง)
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
    public function get(Request $request)
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
            $id = $request->get('id')->toInt();
            $index = $id > 0 ? Model::get($id) : Model::newDocument($login);
            if (!$index) {
                return $this->redirectResponse('/404', 'Sorry, Item not found It&#39;s may be deleted', 404);
            }
            if ($index->id > 0 && !Model::canManage($login, $index)) {
                return $this->errorResponse('Permission required', 403);
            }
            $urgencies = [];
            foreach (Language::get('URGENCIES', []) as $key => $label) {
                $urgencies[] = ['value' => (string) $key, 'text' => $label, 'checked' => (int) $key === (int) $index->urgency];
            }
            $departments = [];
            foreach (\Gcms\Category::init()->toOptions('department') as $item) {
                $departments[] = $item + ['checked' => in_array((string) $item['value'], $index->department, true)];
            }
            $types = (array) \Edocument\Settings\Controller::value('edocument_file_typies');
            $hasFile = $index->id > 0 && $index->file !== '' && is_file(Model::dir().$index->file);

            return $this->successResponse([
                'data' => [
                    'id' => (int) $index->id,
                    'document_no' => (string) $index->document_no,
                    'topic' => (string) $index->topic,
                    'detail' => (string) $index->detail,
                    'urgencies' => $urgencies,
                    'departments' => $departments,
                    // ผู้รับรายบุคคล {key, value} — ช่อง tags แสดงชื่อได้โดยไม่ต้องถาม endpoint
                    'receiver' => $index->id > 0 ? Model::individualReceivers($index->id) : [],
                    'file_name' => $hasFile ? Model::filename($index) : '',
                    'accept' => '.'.implode(',.', $types),
                    'file_comment' => Language::replace('Upload :type files no larger than :size', [
                        ':type' => implode(', ', $types),
                        ':size' => Text::formatFileSize(Model::uploadSize())
                    ]),
                    // ตัวเลือกแจ้งผู้รับมีเมื่อตั้งอีเมลผู้ส่งไว้ ติ๊กไว้ก่อนเฉพาะเอกสารใหม่ (ระบบเดิม)
                    'can_send_mail' => !empty(self::$cfg->noreply_email),
                    'send_mail' => (int) $index->id === 0
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse(self::exceptionMessage($e), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }
            if ((int) $login->active !== 1 || !ApiController::canModify($login, ['can_upload_edocument'])) {
                return $this->errorResponse('Permission required', 403);
            }
            $id = $request->post('id')->toInt();
            $index = $id > 0 ? Model::get($id) : Model::newDocument($login);
            if (!$index) {
                return $this->errorResponse('Sorry, Item not found It&#39;s may be deleted', 404);
            }
            if ($index->id > 0 && !Model::canManage($login, $index)) {
                return $this->errorResponse('Permission required', 403);
            }
            $urgencies = Language::get('URGENCIES', []);
            $urgency = $request->post('urgency')->toInt();
            $save = [
                'document_no' => $request->post('document_no')->topic(),
                'urgency' => isset($urgencies[$urgency]) ? $urgency : 2,
                'topic' => $request->post('topic')->topic(),
                'detail' => $request->post('detail')->textarea()
            ];
            // แผนกผู้รับ เฉพาะแผนกที่มีอยู่จริง
            $category = \Gcms\Category::init();
            $departments = [];
            foreach ((array) $request->post('department', [])->topic() as $department) {
                if ($department !== '' && $category->exists('department', $department)) {
                    $departments[$department] = $department;
                }
            }
            $departments = array_values($departments);
            $errors = [];
            $db = \Kotchasan\DB::create();
            if ($save['document_no'] !== '') {
                $search = $db->first('edocument', [['document_no', $save['document_no']]], ['id']);
                if ($search && (int) $search->id !== (int) $index->id) {
                    $errors['document_no'] = Language::replace('This :name already exist', [':name' => Language::get('Document No.')]);
                }
            }
            if ($save['detail'] === '') {
                $errors['detail'] = 'Please fill in';
            }
            // ผู้รับ: คนในแผนกที่เลือก แล้วตามด้วยคนที่เลือกรายบุคคล (ทับเป็นรายบุคคล เหมือนระบบเดิม)
            $receivers = Model::departmentMembers($departments, $login->id);
            foreach (Model::activeMembers((array) $request->post('receiver', [])->toInt(), $login->id) as $member_id) {
                $receivers[$member_id] = '';
            }
            if (empty($receivers)) {
                $errors[$category->isEmpty('department') ? 'receiver' : 'department'] = 'The recipient was not found. or no recipients found in the selected department.';
            }
            $upload = null;
            $files = $request->getUploadedFiles();
            $file = isset($files['file']) ? $files['file'] : null;
            /** @var \Kotchasan\Http\UploadedFile|null $file */
            if ($file && $file->hasUploadFile()) {
                if (!$file->validFileExt((array) \Edocument\Settings\Controller::value('edocument_file_typies'))) {
                    $errors['file'] = 'The type of file is invalid';
                } elseif ($file->getSize() > Model::uploadSize()) {
                    $errors['file'] = 'The file size larger than the limit';
                } else {
                    $upload = $file;
                }
            } elseif ($file && $file->hasError()) {
                $errors['file'] = $file->getErrorMessage();
            } elseif ($index->id == 0) {
                // เอกสารใหม่ต้องมีไฟล์
                $errors['file'] = 'Please browse file';
            }
            if ($upload) {
                $save['ext'] = strtolower($upload->getClientFileExt());
                $name = preg_replace('/\.'.preg_quote($save['ext'], '/').'$/i', '', $upload->getClientFilename());
                if ($save['topic'] === '') {
                    // ไม่กรอกเรื่อง ใช้ชื่อไฟล์ที่อัปโหลด
                    $save['topic'] = Text::topic($name);
                }
            }
            if ($save['topic'] === '') {
                $errors['topic'] = 'Please fill in';
            }
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }
            $mktime = time();
            if ($upload) {
                $dir = Model::dir();
                if (!File::makeDirectory($dir)) {
                    return $this->formErrorResponse(['file' => Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.'edocument/')], 400);
                }
                $save['file'] = $mktime.'.'.$save['ext'];
                while (file_exists($dir.$save['file'])) {
                    ++$mktime;
                    $save['file'] = $mktime.'.'.$save['ext'];
                }
                try {
                    $save['size'] = $upload->getSize();
                    $upload->moveTo($dir.$save['file']);
                } catch (\Exception $exc) {
                    return $this->formErrorResponse(['file' => Language::get(self::exceptionMessage($exc))], 400);
                }
                if (!empty($index->file) && $index->file !== $save['file'] && is_file($dir.$index->file)) {
                    @unlink($dir.$index->file);
                }
            }
            if ($save['document_no'] === '') {
                // ไม่กรอกเลขที่ ออกเลขอัตโนมัติตามรูปแบบในตั้งค่า
                $save['document_no'] = \Index\Number\Model::get(
                    0,
                    (string) \Edocument\Settings\Controller::value('edocument_format_no'),
                    'edocument',
                    'document_no',
                    (string) \Edocument\Settings\Controller::value('edocument_prefix')
                );
            }
            // เรื่องใช้เป็นชื่อไฟล์ตอนดาวน์โหลด ตัดอักขระที่ใช้ไม่ได้ เหมือนระบบเดิม
            $save['topic'] = preg_replace('/[,;:_]{1,}/', '_', $save['topic']);
            $save['department'] = ','.implode(',', $departments).',';
            $save['last_update'] = $mktime;
            if ($index->id == 0) {
                $save['sender_id'] = (int) $login->id;
                $id = (int) $db->insert('edocument', $save);
            } else {
                $id = (int) $index->id;
                $db->update('edocument', [['id', $id]], $save);
            }
            Model::saveReceivers($id, $receivers);
            \Index\Log\Model::add($id, 'edocument', 'Save', '{LNG_Send Document} ID : '.$id, $login->id);

            $message = 'Saved successfully';
            if (!empty(self::$cfg->noreply_email) && $request->post('send_mail')->toBoolean()) {
                $message = Model::notify(array_keys($receivers), $save);
            }

            return $this->redirectResponse('/edocument-sent', $message, 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse(self::exceptionMessage($e), $e->getCode() ?: 500, $e);
        }
    }
}
