<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\StudentTypes;

/**
 * A student says what kind of student they are. The admin decides.
 */
final class StudentTypeController extends Controller
{
    public function request(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $result = StudentTypes::request($userId, $request->int('type_id'), trim($request->string('note')));

        if ($result === 'invalid') {
            $this->flash('error', 'این نوع دانشجو در دسترس نیست.');
        } else {
            ActivityLogger::log('student_type.requested', $userId, 'user', $userId,
                ['type_id' => $request->int('type_id'), 'result' => $result], 'info', $request);
            $this->flash('success', $result === 'approved'
                ? 'ثبت شد؛ بخش‌های مخصوص تو فعال شد.'
                : 'درخواستت ثبت شد. بعد از تأیید مدیر، بخش‌های مخصوص تو فعال می‌شود.');
        }

        return $this->redirect('/student');
    }
}
