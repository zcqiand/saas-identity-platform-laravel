# 更新日志

格式照 `v<MAJOR>.<MINOR>.<PATCH>-<YYYYMMDD>`（tag 即放行：全量回归绿后打 tag）。

历史版本（tag 底账，明细见 git log）：

- `v0.2.2-20261025`（2026-10-25）— fix(docker): 镜像补 COPY resources/——blade 壳页视图进镜像（v0.2.1 首航红第二层修正）
- `v0.2.1-20261025`（2026-10-25）— fix(swagger): 文档面路由挪 bootstrap then: 闭包 + 契约落盘改文件 public/api-docs.yaml——v0.2.0 首航红双根因修正（REQ-2026-002）
- `v0.2.0-20261025`（2026-10-25）— feat(docs): REQ-2026-002 swagger 文档面——shared openapi.yaml 派生零漂移 + pinned swagger-ui + 根跳转
- `v0.1.5-20261025`（2026-10-25）— docs(req): REQ-2026-001 翻已上线——T-4 首航闭环（v0.1.4-20261025 prod /health 200 实证）+ 首航取证六指纹入册
- `v0.1.4-20261025`（2026-10-25）— fix(docker): install-php-extensions 补 zip——composer --prefer-dist 无 zip/unzip 装不了
- `v0.1.3-20261025`（2026-10-25）— fix(docker): COPY database/ 删行——DB-First 无 migrations，目录 git 内不存在致 build checksum 失败
- `v0.1.2-20261025`（2026-10-25）— fix(deploy): 首航指纹修正——frankenphp 官方镜像替静态二进制 + APP_KEY 进 env 契约
- `v0.1.1-20261025`（2026-10-25）— fix(deploy): octane:start 显式 --admin-port=2019——端口 5110 触发 admin 端口推算负数
