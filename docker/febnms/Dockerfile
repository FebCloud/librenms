# 官方 LibreNMS 镜像 + FebNMS App 用到、但官方还没合并的改动。
# The official LibreNMS image plus the changes the FebNMS app uses that upstream has not merged.
#
# 20760  设备实时指标 API v1 / device live metrics (closed upstream, kept as a patch)
# 20356  端口速率覆盖 API / port speed override
# patches/febnms-rrd-fetch.diff  让 20760 适配 26.11 的 RRD 接口 / adapts 20760 to the 26.11 RRD backend
# patches/febnms-traffic-baseline.diff  端口流量同期基线与骤降告警宏 / port traffic baselines and drop macros
ARG LIBRENMS_VERSION=26.11.0
FROM librenms/librenms:${LIBRENMS_VERSION}

ARG UPSTREAM_PRS="20760 20356"
COPY patches/ /tmp/febnms-patches/

RUN set -eux; \
    cd /opt/librenms; \
    for pr in $UPSTREAM_PRS; do \
      curl -fsSL "https://github.com/librenms/librenms/pull/${pr}.diff" -o "/tmp/febnms-patches/${pr}.pr.diff"; \
      git apply --exclude='doc/*' --exclude='tests/*' "/tmp/febnms-patches/${pr}.pr.diff"; \
      if [ "$pr" = "20760" ]; then git apply /tmp/febnms-patches/febnms-rrd-fetch.diff; fi; \
    done; \
    git apply /tmp/febnms-patches/febnms-traffic-baseline.diff; \
    rm -rf /tmp/febnms-patches bootstrap/cache/*.php; \
    chown -R librenms:librenms app routes includes LibreNMS bootstrap database resources lang

LABEL org.opencontainers.image.title="LibreNMS for FebNMS" \
      org.opencontainers.image.description="Official LibreNMS image with the pending upstream pull requests used by the FebNMS app"
