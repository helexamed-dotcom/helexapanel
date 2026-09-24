<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;

/**
 * «امروز من» was merged into the home page. Bookmarks and old links to it
 * land there.
 */
final class TodayController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        return $this->redirect('/student');
    }
}
