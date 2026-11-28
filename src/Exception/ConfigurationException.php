<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Exception;

/**
 * 配置异常：客户端配置缺失或不完整
 *
 * 与「认证异常」的区分：本异常在**发出请求之前**抛出，
 * 表示调用方给的配置本身有问题（如 appid 格式错误、appsecret 为空），
 * 重试或重新登录都不会变好，必须先修配置。
 */
class ConfigurationException extends KingdeeException {}
