# LibreNMS for FebNMS（Docker 镜像）

在官方 `librenms/librenms` 镜像上打上 FebNMS App 需要、但 LibreNMS 官方还没合并的改动，用户只要把 compose 里的镜像名换掉即可。

| 改动 | 作用 | 状态 |
| --- | --- | --- |
| [#20760](https://github.com/librenms/librenms/pull/20760) | 设备秒级实时指标（`/api/v1/devices/{device}/metrics/...`） | 官方已关闭，作为补丁长期维护 |
| `patches/febnms-rrd-fetch.diff` | 让 #20760 适配 26.11 的 RRD 接口（原版在 26.11 上 summary 和 io-wait 会 500） | 我们自己的修正 |
| [#20356](https://github.com/librenms/librenms/pull/20356) | 端口速率覆盖 API | 等待官方评审 |
| `patches/febnms-traffic-baseline.diff` | 端口流量同期基线（每小时由计划任务计算）和告警宏 `port_in/out_drop_perc`、`port_in/out_baseline`，用于“流量比平时骤降”告警；`/api/v0/system` 返回 `febnms_features` | 我们自己的功能，见 FORK_CHANGES.md |

#20355（传感器阈值）和 #20357（服务最后检查时间）目前打不上最新的 LibreNMS，需要先更新这两个 PR。

## 构建

```bash
docker build -t ghcr.io/febcloud/febnms-librenms:26.11.0 docker/febnms
# 换 LibreNMS 版本：--build-arg LIBRENMS_VERSION=26.12.0
```

构建时会从 GitHub 下载 PR 的 diff。升级 `LIBRENMS_VERSION` 后如果某个补丁打不上，构建会直接失败，不会产出半成品镜像。

## 使用

照官方 [librenms/docker](https://github.com/librenms/docker) 的 compose 部署，把 `librenms`、`dispatcher`、`syslogng`、`snmptrapd` 几个服务的 `image:` 换成本镜像，然后执行一次：

```bash
docker compose exec --user librenms librenms lnms config:set api.v1.enabled true
```

## 验证记录

2026-11-24 在 26.11.0 上构建通过；用 mariadb + redis + 本镜像起了一套，开启 v1 后 `metrics/summary`、`realtime`、`io-wait`、`live/load`、`live/network` 都返回 200 和正确结构。测试设备没有 SNMP 数据，所以没有验证真实数值。
