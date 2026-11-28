<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Exception;

use RuntimeException;

/**
 * SDK 异常基类
 *
 * 异常体系划分依据（便于调用方决定「重试 / 重新登录 / 修配置」）：
 *  - ConfigurationException：本地配置问题，重试无意义；
 *  - AuthenticationException：认证/会话问题，可能需要重新登录；
 *  - BusinessException：服务端按业务规则拒绝，重试无意义；
 *  - TransportException：网络层问题，是否可重试由异常自身标记。
 *
 * 兜底范围（重要）：
 *  本类覆盖的是 **SDK 协议层**的异常——发请求前的配置校验、
 *  传输失败、金蝶业务失败等。
 *
 *  **API 误用**（参数类型不对、查询对象传错、写入策略用错端点等）
 *  抛的是 SPL 的 InvalidArgumentException（继承 LogicException），
 *  **不是**本类的子类。因此只捕获 KingdeeException 并不能兜住全部失败，
 *  需要连同 InvalidArgumentException 一起捕获。
 *
 * 之所以不改层级：把 InvalidArgumentException 纳入本体系会破坏调用方
 * 现有的 catch (InvalidArgumentException) 分支，属于破坏性变更。
 */
class KingdeeException extends RuntimeException {}
