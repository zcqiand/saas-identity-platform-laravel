// scripts/scaffold-models.mjs — information_schema 反推生成 Eloquent 桩（DB-First，家族 ADR-0025 口径）
//
// 设计（镜像 springboot scripts/scaffold-entities.mjs，2026-10-19 laravel 全切片 Task 2）：
// - 直接读 information_schema + pg_catalog，emit PHP Eloquent 桩到
//   app/Models/Generated/<Table>.php（PascalCase：sys_user → SysUser）
// - 简化版输出：$table / $incrementing=false / $keyType='string' / $fillable 全列 /
//   $casts（PG 类型 → Laravel cast 名，未映射类型 throw 不静默）
// - 手写业务逻辑（常量 / hasMany / belongsTo / passwordMatches）通过
//   extends Generated\<Model> 叠加在 app/Models/<Model>.php——禁止把业务写进本生成物
// - 复合主键表：protected $primaryKey = null + 类注释（Eloquent 无原生复合主键）
// - 确定性输出（L4.codegen.idempotent strict 复跑字节比对）：表按表名排序、
//   列按 ordinal_position、无时间戳、显式 LF（Windows 下也不出 CRLF）
//
// 用法：
//   DATABASE_URL=postgresql://... bash scripts/scaffold-models.sh
//
// 前提：本仓禁 migrate/seed（DB-First：schema SSOT = shared drizzle public.*，
// 本脚本只读 information_schema）；连接目标必须是本族 _dev 库。

import { createRequire } from "node:module";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { writeFileSync, mkdirSync } from "node:fs";

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, "..");

// 借 pg driver（laravel 是 PHP 仓，没 node_modules；走 sibling nextjs，springboot 同款）
const requireFromRuntime = createRequire(
  resolve("/app/node_modules", "pg/package.json"),
);
let pg;
try {
  pg = requireFromRuntime("pg");
} catch {
  const nextjsRoot = resolve(ROOT, "../saas-identity-platform-nextjs");
  const requireFromNext = createRequire(resolve(nextjsRoot, "package.json"));
  pg = requireFromNext("pg");
}
const { Client } = pg;

// DATABASE_URL 必填。CLAUDE.md §2「禁止 env 默认值兜底」：连接串含 secret，
// 不允许在脚本里写字面量兜底；缺则 fail-fast 让调用者补 env
// （suite checker strict 会注入本机 _dev 库 URL——门禁侧契约常量）。
const DATABASE_URL = process.env.DATABASE_URL;
if (!DATABASE_URL) {
  console.error("[scaffold-models] FATAL: DATABASE_URL 未设（CLAUDE.md §2 禁字面量兜底）");
  console.error("[scaffold-models]        用法：DATABASE_URL='postgresql://…@127.0.0.1:54321/saas_dev' bash scripts/scaffold-models.sh");
  process.exit(2);
}

const OUTPUT_DIR = resolve(ROOT, "app/Models/Generated");

const EXCLUDE_TABLES = new Set([
  // Drizzle tracking — 不是业务表
  "__drizzle_migrations",
  // Rails tracking（REQ-2026-001 期间发现，2026-10-02）：saas_dev 由本族 rails 栈共用，
  // ActiveRecord 会在库里建自己的 bookkeeping 表；与 drizzle tracking 同类——不是家族业务
  // schema（真源 = shared src/db/schema.ts），laravel 不应为其生成 model。
  "schema_migrations",
  "ar_internal_metadata",
]);

// PG 类型 → Laravel $casts 名（镜像 springboot PG_TYPE_MAP 的覆盖面）。
// 未在此映射的 data_type → throw 不静默：schema 演进必须显式扩此表，禁止静默错 cast。
const PG_CAST_MAP = {
  uuid: "string",
  text: "string",
  "character varying": "string",
  integer: "int",
  smallint: "int",
  bigint: "int",
  boolean: "bool",
  "timestamp with time zone": "datetime",
  "timestamp without time zone": "datetime",
  date: "date",
  jsonb: "array",
};

// camelCase 转换：snake_case → camelCase（snakeToPascal 内部依赖）
function snakeToCamel(s) {
  return s.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
}

// PascalCase 转换：snake_case → PascalCase
function snakeToPascal(s) {
  const camel = snakeToCamel(s);
  return camel[0].toUpperCase() + camel.slice(1);
}

function mapCast(dataType, udtName) {
  if (dataType === "ARRAY" || dataType === "USER-DEFINED") {
    throw new Error(
      `Unsupported PG type: ${dataType} (udt=${udtName})——schema 演进请显式扩 PG_CAST_MAP，禁静默`,
    );
  }
  const cast = PG_CAST_MAP[dataType];
  if (!cast) {
    throw new Error(
      `Unsupported PG type: ${dataType} (udt=${udtName})——schema 演进请显式扩 PG_CAST_MAP，禁静默`,
    );
  }
  return cast;
}

async function main() {
  const client = new Client({
    connectionString: DATABASE_URL,
    connectionTimeoutMillis: 10000,
  });
  await client.connect();
  console.log(`[scaffold-models] 连接 ${DATABASE_URL.replace(/:[^:@/]+@/, ":***@")}`);

  // 1. 读所有表（ORDER BY table_name → 输出按表名排序，字节幂等前提）
  const { rows: tables } = await client.query(`
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
    ORDER BY table_name
  `);
  const filtered = tables.filter((t) => !EXCLUDE_TABLES.has(t.table_name));
  console.log(`[scaffold-models] 发现 ${filtered.length} 张业务表（排除 tracking 表 ${tables.length - filtered.length} 张）`);

  // 2. 复合主键检测（information_schema.table_constraints，springboot 同款）
  const { rows: pkRows } = await client.query(`
    SELECT tc.table_name, kcu.column_name, kcu.ordinal_position
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu
      ON tc.constraint_name = kcu.constraint_name
     AND tc.table_schema = kcu.table_schema
    WHERE tc.table_schema = 'public'
      AND tc.constraint_type = 'PRIMARY KEY'
    ORDER BY tc.table_name, kcu.ordinal_position
  `);
  const compositePKs = new Map(); // tableName -> [columnName, ...]
  const pkColsByTable = new Map();
  for (const r of pkRows) {
    if (!pkColsByTable.has(r.table_name)) pkColsByTable.set(r.table_name, []);
    pkColsByTable.get(r.table_name).push(r.column_name);
  }
  for (const [tableName, cols] of pkColsByTable) {
    if (cols.length >= 2) compositePKs.set(tableName, cols);
  }

  mkdirSync(OUTPUT_DIR, { recursive: true });

  // 3. 逐表生成
  for (const tbl of filtered) {
    const { rows: cols } = await client.query(
      `
      SELECT column_name, data_type, udt_name, is_nullable
      FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = $1
      ORDER BY ordinal_position
    `,
      [tbl.table_name],
    );

    const className = snakeToPascal(tbl.table_name);
    const pkCols = compositePKs.get(tbl.table_name) ?? null;
    const isCompositePK = pkCols != null;

    // 单列 uuid PK 表才有 $incrementing/$keyType 语义；复合主键表两行皆无意义（评审 I-1）。
    const incrementingBlock = isCompositePK
      ? ""
      : `
    public $incrementing = false;

    protected $keyType = 'string';
`;

    const fillable = cols.map((c) => `        '${c.column_name}',`);
    const casts = cols.map((c) => `        '${c.column_name}' => '${mapCast(c.data_type, c.udt_name)}',`);

    // 复合主键：$primaryKey = null（brief 精确行）。不带 @var 标签——父类 Model::$primaryKey
    // @var string 与 string|null 不协变（phpstan property.phpDocType 会红），置 null 本身
    // larastan L5 实测不报，无需 ignore。
    const primaryKeyBlock = isCompositePK
      ? `
    // 复合主键：${pkCols.join(" + ")}（两列联合主键，真源=information_schema）。
    // Eloquent 不支持原生复合主键——置 null 禁用单键 find() 语义，
    // 写操作一律 where 两列显式定位；手写子类禁止覆写回单键。
    protected $primaryKey = null;
`
      : "";

    const compositeDoc = isCompositePK
      ? ` *\n * 复合主键：${pkCols.join(" + ")}（两列联合主键）——单键 find() 禁用，见 \$primaryKey 注释。\n`
      : "";

    const php = `<?php

declare(strict_types=1);

// DB-First scaffold：${tbl.table_name}（家族 DB-First 口径；schema SSOT = shared drizzle public.${tbl.table_name}，
// 本仓禁 migrate/seed）。由 scripts/scaffold-models.mjs 从 saas_dev 真库 information_schema 反推生成——
// 禁手改，L4.codegen.idempotent strict 复跑字节比对会暴露手改。手写业务逻辑（常量 / 关系 / 领域方法）
// 写在 app/Models/${className}.php extends Generated\\${className}。
//
// 字段含义见 saas-identity-platform-shared/src/db/schema.ts。

namespace App\\Models\\Generated;

use Illuminate\\Database\\Eloquent\\Model;

/**
 * DB-First scaffold：${tbl.table_name}。
${compositeDoc} */
class ${className} extends Model
{
    protected $table = '${tbl.table_name}';

    // Eloquent 默认 $timestamps=true 且不查列存在性——saas_dev 实测 7/12 表无 updated_at
    // （首写即 SQLSTATE 42703），12 表全量关自动时间戳（评审 I-1）。时间戳由业务代码
    // 显式写列（DB-First：created_at/updated_at 是普通列）。
    public $timestamps = false;
${incrementingBlock}${primaryKeyBlock}
    /** @var list<string> */
    protected $fillable = [
${fillable.join("\n")}
    ];

    /** @var array<string, string> */
    protected $casts = [
${casts.join("\n")}
    ];
}
`;

    const outPath = resolve(OUTPUT_DIR, `${className}.php`);
    writeFileSync(outPath, php, "utf8");
    console.log(
      `[scaffold-models]   → ${tbl.table_name} → Models/Generated/${className}.php (${cols.length} 字段${isCompositePK ? ", composite PK " + pkCols.join("+") : ""})`,
    );
  }

  await client.end();
  console.log(`[scaffold-models] OK — ${filtered.length} 个 Eloquent 桩已生成到 ${OUTPUT_DIR}`);
}

main().catch((err) => {
  console.error("[scaffold-models] FATAL:", err.message);
  console.error(err.stack);
  process.exit(1);
});
