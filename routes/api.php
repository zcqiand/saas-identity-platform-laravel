<?php

// 路由面 = generated/api_manifest.json 的 1:1 镜像（SSOT = shared 契约，suite-hard-rules §4）。
// action 名 = operationId 后半段 camelCase；tests/Contracts/RouteParityTest.php 逐条对账。
// 挂载经 bootstrap/app.php withRouting(api:, apiPrefix: '')——契约路径自带 /api/v1 前缀，
// 前缀留空防 /api/api/v1 叠加。47 条按 manifest tag 分段，勿在此新增契约外路由。
//
// TenantGuard 挂载（rails 各 controller include TenantGuard 逐仓镜像）：保护面
// admin-*/client-menus/me/tenant-* 一律挂 tenant.guard——跳过 TenantGuard 禁止
// （suite-hard-rules §3）。public 面三个：auth（登录/登出本体）、clients（匿名可读
// 公共元数据）、oauth（springboot permitAll 独立链镜像，authorize 链内手动 decode_bearer!）。

use App\Http\Controllers\AdminClientsController;
use App\Http\Controllers\AdminTenantsController;
use App\Http\Controllers\ClientMenusController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\SessionsController;
use App\Http\Controllers\TenantApplicationsController;
use App\Http\Controllers\TenantMembersController;
use App\Http\Controllers\TenantRoleMenusController;
use App\Http\Controllers\TenantRolesController;

// tag: auth（2 op，public）
Route::post('/api/v1/auth/login', [SessionsController::class, 'login']);
Route::post('/api/v1/auth/logout', [SessionsController::class, 'logout']);

// tag: clients（1 op，匿名可读公共元数据）
Route::get('/api/v1/clients/{clientId}', [ClientsController::class, 'getClient']);

// tag: oauth（2 op，public——permitAll 独立链镜像）
Route::post('/api/v1/oauth/authorize', [OAuthController::class, 'authorize']);
Route::post('/api/v1/oauth/token', [OAuthController::class, 'token']);

Route::middleware('tenant.guard')->group(function (): void {
    // tag: admin-clients（6 op；:clientId 是 oauth_client.client_id 业务串）
    Route::get('/api/v1/admin/clients', [AdminClientsController::class, 'listClients']);
    Route::post('/api/v1/admin/clients', [AdminClientsController::class, 'createClient']);
    Route::get('/api/v1/admin/clients/{clientId}', [AdminClientsController::class, 'getClient']);
    Route::patch('/api/v1/admin/clients/{clientId}', [AdminClientsController::class, 'updateClient']);
    Route::patch('/api/v1/admin/clients/{clientId}/status', [AdminClientsController::class, 'setClientStatus']);
    Route::delete('/api/v1/admin/clients/{clientId}', [AdminClientsController::class, 'deleteClient']);

    // tag: admin-tenants（5 op；:id 是行 UUID）
    Route::get('/api/v1/admin/tenants', [AdminTenantsController::class, 'listTenants']);
    Route::post('/api/v1/admin/tenants', [AdminTenantsController::class, 'createTenant']);
    Route::get('/api/v1/admin/tenants/{id}', [AdminTenantsController::class, 'getTenant']);
    Route::patch('/api/v1/admin/tenants/{id}', [AdminTenantsController::class, 'updateTenant']);
    Route::delete('/api/v1/admin/tenants/{id}', [AdminTenantsController::class, 'deleteTenant']);

    // tag: client-menus（7 op）
    Route::get('/api/v1/clients/{clientId}/menus', [ClientMenusController::class, 'listSysMenus']);
    Route::post('/api/v1/clients/{clientId}/menus', [ClientMenusController::class, 'createSysMenu']);
    Route::get('/api/v1/clients/{clientId}/menus/{menuId}', [ClientMenusController::class, 'getSysMenu']);
    Route::patch('/api/v1/clients/{clientId}/menus/{menuId}', [ClientMenusController::class, 'updateSysMenu']);
    Route::patch('/api/v1/clients/{clientId}/menus/{menuId}/parent', [ClientMenusController::class, 'moveSysMenu']);
    Route::put('/api/v1/clients/{clientId}/menus/{menuId}/reorder', [ClientMenusController::class, 'reorderSysMenus']);
    Route::delete('/api/v1/clients/{clientId}/menus/{menuId}', [ClientMenusController::class, 'deleteSysMenu']);

    // tag: me（4 op）
    Route::get('/api/v1/me', [MeController::class, 'whoami']);
    Route::get('/api/v1/me/menus', [MeController::class, 'getMyMenus']);
    Route::get('/api/v1/me/tenants', [MeController::class, 'listMyTenants']);
    Route::post('/api/v1/me/tenants/{tenantId}/switch', [MeController::class, 'switchTenant']);

    // tag: tenant-applications（4 op）
    Route::get('/api/v1/tenants/{tenantId}/applications', [TenantApplicationsController::class, 'listTenantApplications']);
    Route::post('/api/v1/tenants/{tenantId}/applications', [TenantApplicationsController::class, 'subscribeTenantApplication']);
    Route::patch('/api/v1/tenants/{tenantId}/applications/{clientId}', [TenantApplicationsController::class, 'updateTenantApplication']);
    Route::delete('/api/v1/tenants/{tenantId}/applications/{clientId}', [TenantApplicationsController::class, 'removeTenantApplication']);

    // tag: tenant-members（8 op；path {userId} = sys_user.id）
    Route::get('/api/v1/tenants/{tenantId}/members', [TenantMembersController::class, 'listTenantUsers']);
    Route::post('/api/v1/tenants/{tenantId}/members', [TenantMembersController::class, 'createTenantUser']);
    Route::post('/api/v1/tenants/{tenantId}/members/invitations', [TenantMembersController::class, 'inviteTenantUser']);
    Route::get('/api/v1/tenants/{tenantId}/members/{userId}', [TenantMembersController::class, 'getTenantUser']);
    Route::patch('/api/v1/tenants/{tenantId}/members/{userId}', [TenantMembersController::class, 'updateTenantUser']);
    Route::patch('/api/v1/tenants/{tenantId}/members/{userId}/status', [TenantMembersController::class, 'changeTenantUserStatus']);
    Route::put('/api/v1/tenants/{tenantId}/members/{userId}/roles', [TenantMembersController::class, 'assignTenantMemberRoles']);
    Route::delete('/api/v1/tenants/{tenantId}/members/{userId}', [TenantMembersController::class, 'deleteTenantUser']);

    // tag: tenant-role-menus（3 op）
    Route::get('/api/v1/tenants/{tenantId}/roles/{roleId}/menus', [TenantRoleMenusController::class, 'listSysRoleMenus']);
    Route::put('/api/v1/tenants/{tenantId}/roles/{roleId}/menus', [TenantRoleMenusController::class, 'setSysRoleMenus']);
    Route::delete('/api/v1/tenants/{tenantId}/roles/{roleId}/menus', [TenantRoleMenusController::class, 'clearSysRoleMenus']);

    // tag: tenant-roles（5 op）
    Route::get('/api/v1/tenants/{tenantId}/roles', [TenantRolesController::class, 'listSysRoles']);
    Route::post('/api/v1/tenants/{tenantId}/roles', [TenantRolesController::class, 'createSysRole']);
    Route::get('/api/v1/tenants/{tenantId}/roles/{roleId}', [TenantRolesController::class, 'getSysRole']);
    Route::patch('/api/v1/tenants/{tenantId}/roles/{roleId}', [TenantRolesController::class, 'updateSysRole']);
    Route::delete('/api/v1/tenants/{tenantId}/roles/{roleId}', [TenantRolesController::class, 'deleteSysRole']);
});
