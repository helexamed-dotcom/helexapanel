<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Validator;
use HeleXa\Services\Auth;
use HeleXa\Services\Phone;

/**
 * Sign-in with a username or mobile number and a password.
 *
 * There is one way in. Codes texted to a phone were removed along with the
 * SMS gateway, and a forgotten password is now reset by an admin from the
 * student's own page — the only path that does not depend on an outside
 * service being reachable and paid for.
 *
 * The form stays an ordinary POST-and-render, so sign-in still works with
 * JavaScript off. The JSON branch exists for the mobile client, which needs
 * the destination without following a redirect.
 */
final class AuthController extends Controller
{
    /* ------------------------------------------------------------- pages */

    public function showLogin(Request $request, array $params = []): Response
    {
        return $this->page('layouts.auth', 'auth.login', $this->loginViewData());
    }

    /** @param array<string,string> $errors */
    private function loginViewData(array $errors = [], array $old = []): array
    {
        return [
            'title'  => 'ورود',
            'errors' => $errors,
            'old'    => array_merge(['identifier' => '', 'remember' => true], $old),
        ];
    }

    /* -------------------------------------------------- password sign-in */

    public function login(Request $request, array $params = []): Response
    {
        $identifier = $request->string('identifier');
        $password   = (string) $request->input('password', '');

        $validator = (new Validator(['identifier' => $identifier, 'password' => $password]))
            ->required('identifier', 'شماره موبایل یا نام کاربری')
            ->required('password', 'رمز عبور');

        if ($validator->fails()) {
            if ($request->isAjax()) {
                return $this->json(['ok' => false, 'message' => implode(' ', $validator->errors())], 422);
            }

            return $this->page('layouts.auth', 'auth.login',
                $this->loginViewData($validator->errors(), [
                    'identifier' => $identifier,
                    'remember'   => $request->bool('remember'),
                ]), 422);
        }

        // A number typed as +98…, with Persian digits or with dashes is the
        // same account as the stored 09… form, so it is normalised before the
        // lookup. Anything that is not a phone number is passed through
        // untouched and matched as a username.
        $normalized = Phone::normalize($identifier);
        $lookup     = $normalized !== '' ? $normalized : $identifier;

        $result = Auth::attempt($request, $lookup, $password, $request->bool('remember'));

        if (!$result['ok']) {
            if ($request->isAjax()) {
                // The code travels too, because one of these — being signed in
                // on another device — is not a retryable failure but a decision
                // the person has to make, and a client cannot tell that from
                // the wording alone.
                return $this->json([
                    'ok'      => false,
                    'code'    => $result['code'],
                    'message' => $result['message'],
                ], $result['code'] === 'SINGLE_DEVICE' ? 403 : 401);
            }

            return $this->page('layouts.auth', 'auth.login',
                $this->loginViewData(['identifier' => $result['message']], [
                    'identifier' => $identifier,
                    'remember'   => $request->bool('remember'),
                ]), 401);
        }

        /**
         * A browser is redirected; a client that asked for JSON is told where
         * it would have been sent.
         *
         * The website never sends X-Requested-With on this form, so its
         * behaviour is untouched — this is an additional answer to the same
         * question, not a changed one.
         */
        if ($request->isAjax()) {
            return $this->json([
                'ok'       => true,
                'redirect' => $this->destinationFor($result['user']),
            ]);
        }

        return $this->afterLogin($result['user']);
    }

    public function logout(Request $request, array $params = []): Response
    {
        Auth::logout($request);
        return $this->redirect('/login');
    }

    /* ------------------------------------------------------------ helpers */

    private function afterLogin(array $user): Response
    {
        if ((int) $user['must_change_password'] === 1) {
            return $this->redirect('/account/password');
        }
        return $this->redirect($this->destinationFor($user));
    }

    private function destinationFor(array $user): string
    {
        if ((int) $user['must_change_password'] === 1) {
            return '/account/password';
        }
        return $user['role_slug'] === 'student' ? '/student' : '/admin';
    }
}
