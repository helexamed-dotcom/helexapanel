<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;

final class HomeController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        if (Auth::validate($request) === null) {
            return $this->redirect('/login');
        }
        return $this->redirect(Auth::isAdmin() ? '/admin' : '/student');
    }
}
