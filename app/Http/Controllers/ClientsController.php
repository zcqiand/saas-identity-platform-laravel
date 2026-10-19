<?php

namespace App\Http\Controllers;

// tag: clients（manifest 1 op，匿名可读公共元数据）。壳阶段 501 占位——业务在后续任务按 tag 实装。
final class ClientsController extends Controller
{
    public function getClient(): void
    {
        abort(501);
    }
}
