<?php

declare(strict_types = 1);

/**
 * PHPUnit 引导文件
 *
 * 兼容两种布局：
 *  - 包独立安装（packages/kingdee-k3cloud-sdk/vendor/autoload.php）
 *  - 作为主项目依赖（主项目 vendor/autoload.php）
 */
$candidates = [
    // 包独立安装
    __DIR__.'/vendor/autoload.php',
    // 作为依赖安装：vendor/trappistes/kingdee-k3cloud-sdk → 项目根 vendor/autoload.php
    __DIR__.'/../../../autoload.php',
    // 仓库内 path 仓库布局：packages/kingdee-k3cloud-sdk → 项目根 vendor/autoload.php
    __DIR__.'/../../vendor/autoload.php',
];

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require $candidate;

        return;
    }
}

throw new RuntimeException('未找到 autoload.php，请先执行 composer install');
