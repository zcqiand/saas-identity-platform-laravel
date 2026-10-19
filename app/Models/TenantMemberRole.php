<?php

declare(strict_types=1);

// 成员-角色绑定（public.tenant_member_role，复合主键 member_id+role_id）。
// 镜像 rails app/models/tenant_member_role.rb；单键语义见 Generated\TenantMemberRole::$primaryKey 注释。

namespace App\Models;

class TenantMemberRole extends Generated\TenantMemberRole {}
