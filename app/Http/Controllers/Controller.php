<?php

namespace App\Http\Controllers;

use App\Support\Camel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

// 家族契约底座：camelCase JSON 渲染 + 错误体 {code,message} 助手 + 分页信封 + UUID 参数甄别。
// 错误映射表在 bootstrap/app.php withExceptions（rails application_controller rescue_from
// 表逐行镜像，recon §2.1）；本基座供 Task 5-10 控制器消费渲染/分页/甄别面。
abstract class Controller
{
    private const UUID_RE = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /**
     * snake_case → lowerCamel 深变换渲染（家族 DTO 形状是 camelCase，DB 列是 snake_case）。
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function renderCamel(array $data, int $status = 200): JsonResponse
    {
        return response()->json(Camel::deep($data), $status);
    }

    protected function renderError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    /**
     * 分页参数（rails page_params 镜像）：page=0 / pageSize=20 缺省（query key 是 camelCase
     * 的 pageSize，契约口径）；page<0 或 pageSize<1 → ArgumentError → 基座 400 BAD_REQUEST。
     *
     * @return array{0: int, 1: int} [page, pageSize]
     */
    protected function pageParams(Request $request): array
    {
        $page = (int) $request->query('page', '0');
        $pageSize = (int) $request->query('pageSize', '20');
        if ($page < 0) {
            throw new InvalidArgumentException('page must be >= 0');
        }
        if ($pageSize < 1) {
            throw new InvalidArgumentException('pageSize must be >= 1');
        }

        return [$page, $pageSize];
    }

    /**
     * 家族分页信封 { items, page, pageSize, total }（contract-test 约定）：count 全量、
     * limit/offset 切页、mapper 逐行装配后统一 renderCamel。
     *
     * @param  callable(mixed): array<array-key, mixed>  $mapper
     */
    protected function renderPaginated(Builder $query, callable $mapper): JsonResponse
    {
        [$page, $pageSize] = $this->pageParams(request());
        $total = (clone $query)->count();
        $items = $query->offset($page * $pageSize)->limit($pageSize)->get()
            ->map(fn ($row): array => $mapper($row))
            ->all();

        return $this->renderCamel([
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ]);
    }

    /**
     * rails bad_uuid_param 镜像：ModelNotFound 渲染前的 UUID 甄别——路径段声称 uuid
     * 但格式非法 → 返回首个坏值（基座映射转 400 malformed uuid）；合法格式查无 → null（404）。
     * 扫描键与 rails 同表（:id/:tenant_id/:user_id/:role_id/:menu_id；clientId 是业务串不入列）。
     */
    public static function badUuidParam(Request $request): ?string
    {
        foreach (['id', 'tenantId', 'userId', 'roleId', 'menuId'] as $key) {
            $value = $request->route($key);
            if (is_string($value) && $value !== '' && preg_match(self::UUID_RE, $value) !== 1) {
                return $value;
            }
        }

        return null;
    }
}
