<?php

namespace App\Http\Controllers;

// tag: oauth（manifest 2 op，operationId 前缀 OAuth）。壳阶段 501 占位——业务在后续任务按 tag 实装。
final class OAuthController extends Controller
{
    public function authorize(): void
    {
        abort(501);
    }

    public function token(): void
    {
        abort(501);
    }
}
