<?php

namespace App\Http\Controllers;

// tag: auth（manifest 2 op）。壳阶段 501 占位——业务在后续任务按 tag 实装。
final class SessionsController extends Controller
{
    public function login(): void
    {
        abort(501);
    }

    public function logout(): void
    {
        abort(501);
    }
}
