<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantGuard;
use App\Models\SysRole;
use App\Models\SysUser;
use App\Models\TenantMember;
use App\Models\TenantMemberRole;
use App\Services\Family\Dicts;
use App\Services\Family\MemberViews;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

// tag: tenant-members —— protected + TenantGuard（rails tenant_members_controller.rb 全文镜像）。
// 寻址：path {userId} = sys_user.id，经 (tenant_id, user_id) 找 member 行，寻不到 404。
// 视图 status 读 member 行。
final class TenantMembersController extends Controller
{
    // status query 过滤 DB 级（total=过滤后计数）；排序 createdAt DESC, id ASC；
    // member 无对应 user 行的条目被滤掉
    // @impl M00.F02.I01 (book anchor xr-know-012)
    public function listTenantUsers(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        $scope = TenantMember::query()->where('tenant_id', (string) $request->route('tenantId'));
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $scope = $scope->where('status', Dicts::memberStatusCode($status));
        }

        [$page, $pageSize] = $this->pageParams($request);
        $total = (clone $scope)->count();
        $window = $scope->orderBy('created_at', 'desc')->orderBy('id', 'asc')
            ->offset($page * $pageSize)->limit($pageSize)->get();

        // 批量取 user 防 N+1；member 无对应 user 行的条目被滤掉
        $users = SysUser::query()->whereIn('id', $window->pluck('user_id'))->get()->keyBy('id');
        $items = [];
        foreach ($window as $member) {
            $user = $users->get((string) $member->user_id);
            if ($user !== null) {
                $items[] = MemberViews::flatUserView($member, $user);
            }
        }

        return $this->renderCamel([
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ]);
    }

    // @impl M00.F02.I02 (book anchor xr-know-012)
    public function createTenantUser(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        $body = [];
        foreach (['username', 'password', 'email'] as $key) {
            $value = $request->input($key);
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("{$key}: is required");
            }
            $body[$key] = $value;
        }
        // 镜像 CreateSysUserRequest.password @Size(min=8, max=256)（shared 契约
        // minLength/maxLength；springboot MethodArgumentNotValidException → 400 同语义）
        $passwordLength = mb_strlen($body['password']);
        if ($passwordLength < 8 || $passwordLength > 256) {
            throw new InvalidArgumentException('password: size must be between 8 and 256');
        }

        $now = Date::now();
        $user = new SysUser([
            'id' => (string) Str::uuid(),
            'username' => $body['username'],
            'password' => "plain:{$body['password']}",
            'email' => $body['email'],
            'mobile' => $request->input('mobile'),
            'status' => 1,
            'failed_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user->save();

        $member = new TenantMember([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $request->route('tenantId'),
            'user_id' => (string) $user->id,
            'member_name' => $body['username'],
            'is_owner' => false,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $member->save();

        return $this->renderCamel(MemberViews::flatUserView($member, $user));
    }

    // @impl M00.F02.I03 (book anchor xr-know-012)
    public function getTenantUser(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        [$member, $user] = $this->locateMember($request);

        return $this->renderCamel(MemberViews::flatUserView($member, $user));
    }

    // 镜像：契约已删 status 字段（状态唯一通道是 /status 端点）；只更新 user 行 email/mobile。
    // rails update! 会 touch updated_at（timestamps=false 故显式写列）
    // @impl M00.F02.I04 (book anchor xr-know-012)
    public function updateTenantUser(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        [$member, $user] = $this->locateMember($request);

        $updates = [];
        foreach (['email', 'mobile'] as $key) {
            $value = $request->input($key);
            if ($value !== null) {
                $updates[$key] = $value;
            }
        }
        if ($updates !== []) {
            $updates['updated_at'] = Date::now();
            SysUser::query()->where('id', (string) $user->id)->update($updates);
            $user->fill($updates);
        }

        return $this->renderCamel(MemberViews::flatUserView($member, $user));
    }

    // 删 member_role 绑定 + member 行；user 行不删；204
    // @impl M00.F02.I05 (book anchor xr-know-012)
    public function deleteTenantUser(Request $request): Response
    {
        TenantGuard::verifyPathTenant($request);
        [$member] = $this->locateMember($request);

        TenantMemberRole::query()->where('member_id', (string) $member->id)->delete();
        $member->delete();

        return response()->noContent();
    }

    // 双写：member.status 与 sys_user.status 同值（家族口径）。
    // rails update! touch → updated_at 显式写列
    // @impl M00.F02.I08 (book anchor xr-know-012)
    public function changeTenantUserStatus(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        [$member, $user] = $this->locateMember($request);

        $status = $request->input('status');
        if ($status === null || (is_string($status) && trim($status) === '')) {
            // rails params.require(:status) → ParameterMissing → 400 malformed request body
            throw new BadRequestHttpException('status is required');
        }
        $statusCode = Dicts::memberStatusCode((string) $status);

        $now = Date::now();
        TenantMember::query()->where('id', (string) $member->id)
            ->update(['status' => $statusCode, 'updated_at' => $now]);
        SysUser::query()->where('id', (string) $user->id)
            ->update(['status' => $statusCode, 'updated_at' => $now]);
        $member->status = $statusCode;
        $member->updated_at = $now;

        return $this->renderCamel(MemberViews::flatUserView($member, $user));
    }

    // body { roleIds }：先全删再插；只接受本租户 sys_role 的 roleId，外来/未知静默忽略。
    // 复合主键表禁 Model::create()（returning "id" 42703）——一律 DB::table()->insert()
    // @impl M01.F02.I01 (book anchor xr-know-012)
    public function assignTenantMemberRoles(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);
        [$member, $user] = $this->locateMember($request);

        $roleIds = $request->input('roleIds');
        $roleIds = is_array($roleIds) ? array_map(strval(...), $roleIds) : [];
        $validIds = SysRole::query()
            ->where('tenant_id', (string) $request->route('tenantId'))
            ->whereIn('id', $roleIds)
            ->pluck('id')
            ->all();

        TenantMemberRole::query()->where('member_id', (string) $member->id)->delete();
        foreach ($validIds as $roleId) {
            DB::table('tenant_member_role')->insert([
                'member_id' => (string) $member->id,
                'role_id' => (string) $roleId,
            ]);
        }

        return $this->renderCamel(MemberViews::flatUserView($member, $user));
    }

    // invitations：email 空 -> 400；建真 sys_user（username=email, password 为空串, status=2）；
    // member status=1(active)；响应嵌套视图
    // @impl M00.F02.I06 (book anchor xr-know-012)
    public function inviteTenantUser(Request $request): JsonResponse
    {
        TenantGuard::verifyPathTenant($request);

        $email = trim((string) $request->input('email'));
        if ($email === '') {
            throw new InvalidArgumentException('email: is required');
        }

        $now = Date::now();
        $user = new SysUser([
            'id' => (string) Str::uuid(),
            'username' => $email,
            'password' => '',
            'email' => $email,
            'status' => 2,
            'failed_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user->save();

        $member = new TenantMember([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $request->route('tenantId'),
            'user_id' => (string) $user->id,
            'member_name' => $email,
            'is_owner' => false,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $member->save();

        return $this->renderCamel(MemberViews::nestedView($member, $user));
    }

    /**
     * rails #locate_member 镜像：(tenant_id, user_id) 解析 member 行，寻不到 404；
     * member 无对应 user 行也 404（'member user missing'）。
     *
     * @return array{0: TenantMember, 1: SysUser}
     */
    private function locateMember(Request $request): array
    {
        $userId = (string) $request->route('userId');
        $member = TenantMember::query()
            ->where('tenant_id', (string) $request->route('tenantId'))
            ->where('user_id', $userId)
            ->first();
        if ($member === null) {
            throw (new ModelNotFoundException)->setModel(TenantMember::class, [$userId]);
        }

        $user = SysUser::query()->where('id', (string) $member->user_id)->first();
        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(SysUser::class, [(string) $member->user_id]);
        }

        return [$member, $user];
    }
}
