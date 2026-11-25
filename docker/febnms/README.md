# LibreNMS for FebNMS（Docker 镜像）

官方 `librenms/librenms` 镜像，加上 FebCloud/librenms 分支相对同一 LibreNMS 版本改动过的文件。FebNMS 自己的服务器跑的就是同一份构建。用户只要把官方 compose 里的镜像名换掉。

包含的功能（详见 [FORK_CHANGES.md](../../FORK_CHANGES.md)）：

| 功能 | App 里的作用 |
| --- | --- |
| 设备实时指标 API（`/api/v0/devices/{host}/metrics/...`） | 服务器详情的秒级 CPU、内存、磁盘 I/O、网络 |
| 端口速率覆盖、传感器阈值 API | 在 App 里修改端口速率和传感器阈值 |
| 服务检查：最后检查时间、检查间隔与重试、响应慢标记 | 服务页的检查时间、重试和“响应慢”告警 |
| 告警通知方式与通知设置 API、`operation_timing` | 推送自动配置；规则里的“持续多久才报警” |
| 端口流量同期基线、设备 CPU/内存同期基线（每小时由计划任务计算） | “流量比平时骤降”“CPU/内存比平时骤升”告警 |
| 个人偏好：进出流量画在同一坐标轴 | 网页版流量图 |

## 构建

```bash
docker/febnms/build-context.sh 26.11.0          # 把改动过的文件复制到 docker/febnms/src/
docker build --build-arg LIBRENMS_VERSION=26.11.0 -t ghcr.io/febcloud/febnms-librenms:26.11.0 docker/febnms
```

分支删除了官方文件时脚本会报错退出（复制覆盖的方式删不掉文件）。换 LibreNMS 版本时先把分支合并到新版本，再用新的版本号构建。

GitHub Actions 的 `FebNMS image` 工作流（手动触发）会发布 `ghcr.io/febcloud/febnms-librenms:<版本>`、`<版本>-<分支提交>` 和 `latest`。同一个 LibreNMS 版本加了新功能时，会重新发布同一个 `<版本>` 标签，用户 `docker compose pull` 即可更新。

## 使用

照官方 [librenms/docker](https://github.com/librenms/docker) 的 compose 部署，把 `librenms`、`dispatcher`、`syslogng`、`snmptrapd` 几个服务的 `image:` 换成本镜像，`docker compose up -d`。容器启动时会自动建立新增的数据表。

## 验证记录

- 2026-11-26：`src/` 与 FebNMS 两台生产服务器（5UF、FEB）当前镜像里的分支文件逐个校验一致（80 个文件，分支提交 89ce1a7aa9）。
