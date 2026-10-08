#!/usr/bin/env sh
# 棍棋 · 三角版 — 联网对战服务器（Linux / macOS 启动脚本）
# 用法：./start-server.sh [端口]
cd "$(dirname "$0")" || exit 1

if ! command -v php >/dev/null 2>&1; then
  echo "[错误] 没有找到 php 命令，请先安装 PHP 8.0+（如 sudo apt install php-cli）"
  exit 1
fi

exec php start.php "$@"
