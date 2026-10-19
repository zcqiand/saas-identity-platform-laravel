<?php

namespace App\Http\Controllers;

// tag: me（manifest 4 op）。壳阶段 501 占位——业务在后续任务按 tag 实装。
final class MeController extends Controller
{
    public function whoami(): void
    {
        abort(501);
    }

    public function getMyMenus(): void
    {
        abort(501);
    }

    public function listMyTenants(): void
    {
        abort(501);
    }

    public function switchTenant(): void
    {
        abort(501);
    }
}
