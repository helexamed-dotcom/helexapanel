<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Validator;
use HeleXa\Services\Auth;

final class AuthController extends Controller
{
    public function showLogin(Request $request, array $params = []): Response
    {
        return $this->page('layouts.auth', 'auth.login', [
            'title'  => 'ورود به سامانه',
            'errors' => [],
            'old'    => ['identifier' => '', 'remember' => true],
        ]);
    }

    public function login(Request $request, array $params = []): Response
    {
        $identifier = $request->string('identifier');
        $password   = (string) $request->input('password', '');

        $validator = (new Validator(['identifier' => $identifier, 'password' => $password]))
            ->required('identifier', 'نام کاربری یا شماره موبایل')
            ->required('password', 'رمز عبور');

        if ($validator->fails()) {
            return $this->page('layouts.auth', 'auth.login', [
                'title'  => 'ورود به سامانه',
                'errors' => $validator->errors(),
                'old'    => ['identifier' => $identifier, 'remember' => $request->bool('remember')],
            ], 422);
        }

        $result = Auth::attempt($request, $identifier, $password, $request->bool('remember'));

        if (!$result['ok']) {
            return $this->page('layouts.auth', 'auth.login', [
                'title'  => 'ورود به سامانه',
                'errors' => ['identifier' => $result['message']],
                'old'    => ['identifier' => $identifier, 'remember' => $request->bool('remember')],
            ], 401);
        }

        $user = $result['user'];
        if ((int) $user['must_change_password'] === 1) {
            return $this->redirect('/account/password');
        }

        return $this->redirect($user['role_slug'] === 'student' ? '/student' : '/admin');
    }

    public function logout(Request $request, array $params = []): Response
    {
        Auth::logout($request);
        return $this->redirect('/login');
    }
}
