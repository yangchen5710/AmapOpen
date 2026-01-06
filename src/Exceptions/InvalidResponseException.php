<?php

namespace Ycstar\Amapopen\Exceptions;

/**
 * 无效响应异常类
 *
 * 当高德开放平台 API 返回失败响应时抛出此异常
 * 通常包含错误码和错误信息，用于调试和错误处理
 *
 * @package Ycstar\Amapopen\Exceptions
 * @author  Ycstar
 */
class InvalidResponseException extends \Exception {}
