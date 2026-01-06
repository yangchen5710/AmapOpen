<?php

namespace Ycstar\Amapopen;

use Ycstar\Amapopen\Exceptions\InvalidResponseException;

/**
 * 高德开放平台 API 客户端类
 *
 * 提供与高德开放平台 API 交互的功能，包括工单创建、工单查询等操作
 * 支持 MD5 签名方式确保请求安全性
 *
 * @package Ycstar\Amapopen
 * @author  Ycstar
 */
class AmapOpen
{
    protected $host;
    protected $eId;
    protected $appId;
    protected $keyt;
    protected $ent;
    protected $signKey;

    /**
     * 构造函数
     *
     * @param string $host    API主机地址
     * @param string $eId     企业ID
     * @param string $appId   应用ID
     * @param string $keyt    密钥类型
     * @param int $ent     企业标识
     * @param string $signKey 签名密钥
     */
    public function __construct(
        string $host,
        string $eId,
        string $appId,
        string $keyt,
        int $ent,
        string $signKey,
    ) {
        $this->host = $host;
        $this->eId = $eId;
        $this->appId = $appId;
        $this->keyt = $keyt;
        $this->ent = $ent;
        $this->signKey = $signKey;
    }

    /**
     * 创建工单
     *
     * @param string $amapOrderId     高德订单ID (必填)
     * @param string $content          投诉内容描述，最多不超过40个汉字 (必填)
     * @param string $channelOrderId   渠道订单号(可选)
     * @return array 工单创建结果
     * @throws InvalidResponseException
     */
    public function createComplainCase(
        string $amapOrderId,
        string $content,
        string $channelOrderId = ""
    ): array {
        // 验证投诉内容长度
        if (mb_strlen($content) > 40) {
            throw new InvalidResponseException("投诉内容描述不能超过40个汉字");
        }

        $params = [
            "amapOrderId" => $amapOrderId,
            "content" => $content,
        ];

        if (!empty($channelOrderId)) {
            $params["channelOrderId"] = $channelOrderId;
        }

        return $this->callPostApi(
            $this->host . "/ws/boss/channel/openapi/order/complain/case/create",
            $params,
        );
    }

    /**
     * 查询工单详情
     *
     * @param string $caseId 工单号
     * @return array 工单详情
     * @throws InvalidResponseException
     */
    public function getComplainCaseDetail(string $caseId): array
    {
        $params = [
            "caseId" => $caseId,
        ];

        return $this->callPostApi(
            $this->host . "/ws/boss/channel/openapi/order/complain/case/detail",
            $params,
        );
    }

    /**
     * 调用POST接口
     *
     * 公共响应格式:
     * {
     *   "code": int,           // 1表示成功，非1表示失败
     *   "message": String,     // code=1时为"Successful"，code!=1时为错误信息
     *   "data": Object,        // 返回数据内容
     *   "timestamp": long,     // 服务器时间
     *   "traceId": String      // 当前请求的链路跟踪id
     * }
     *
     * @param string $url  接口地址
     * @param array  $data 业务数据
     * @return array 响应数据
     * @throws InvalidResponseException
     */
    protected function callPostApi(string $url, array $data): array
    {
        // 构建请求参数，包含公共参数
        $params = array_merge($data, [
            "appId" => $this->appId,
            "eid" => $this->eId,
            "keyt" => $this->keyt,
            "ent" => $this->ent,
        ]);

        // 生成签名
        $sign = $this->getSign($params);
        $params["sign"] = $sign;

        // 打印原始签名串用于调试
        $originalString = $this->buildOriginalSignString($params);
        error_log("[AmapOpen] Original signature string: " . $originalString);

        $response = $this->post($url, $params);
        $rs = json_decode($response, true);

        // 打印完整响应用于调试
        error_log("[AmapOpen] API Response: " . $response);

        // 检查 JSON 解析是否成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidResponseException(
                "响应解析失败: " . json_last_error_msg() . " | 原始响应: " . $response
            );
        }

        // 检查响应状态：code=1 表示成功
        if (!isset($rs["code"]) || $rs["code"] !== 1) {
            $code = $rs["code"] ?? "unknown";
            $message = $rs["message"] ?? "未知错误";
            $traceId = $rs["traceId"] ?? "";
            $errorInfo = $traceId ? "[traceId: {$traceId}]" : "";
            throw new InvalidResponseException(
                "[code: {$code}] {$message} {$errorInfo}"
            );
        }

        return $rs["data"] ?? [];
    }

    /**
     * 构建原始签名串
     *
     * 1. 将请求参数的key值按ASCII码递增排序
     * 2. 按照"参数=参数值"的格式拼接
     * 3. 使用"&"符连接
     * 4. 最后拼接"@signKey"
     * 5. 公共参数keyt和ent不参与加签
     *
     * @param array $params 请求参数
     * @return string 原始签名串
     */
    private function buildOriginalSignString(array $params): string
    {
        // 过滤不参与签名的参数：sign、keyt、ent
        $filteredParams = array_filter($params, function ($key) {
            return $key !== 'sign' && $key !== 'keyt' && $key !== 'ent';
        }, ARRAY_FILTER_USE_KEY);

        // 按ASCII码递增排序
        ksort($filteredParams);

        // 按"参数=参数值"格式拼接，用"&"连接（使用原始值，不进行URL编码）
        $pairs = [];
        foreach ($filteredParams as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $queryString = implode('&', $pairs);

        // 拼接@signKey
        return $queryString . '@' . $this->signKey;
    }

    /**
     * 计算签名
     *
     * 计算原始签名串的MD5值，得到sign值
     *
     * @param array $params 请求参数
     * @return string 签名值
     */
    private function getSign(array $params): string
    {
        $originalString = $this->buildOriginalSignString($params);
        return md5($originalString);
    }

    /**
     * 发送POST请求
     *
     * @param string $url    请求地址
     * @param array  $params 请求参数
     * @return string 响应内容
     */
    private function post(string $url, array $params): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/x-www-form-urlencoded",
        ]);
        // SSL 验证配置
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        // TLS 版本设置
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        // 禁用代理（避免 TLS 握手问题）
        curl_setopt($ch, CURLOPT_PROXY, '');

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("[AmapOpen] HTTP Code: " . $httpCode);
        error_log("[AmapOpen] Curl Error: " . ($curlError ?: 'none'));

        // 检查 curl_exec 是否失败
        if ($result === false) {
            $errorMsg = "请求失败: " . ($curlError ?: "未知错误");
            if ($httpCode === 0) {
                $errorMsg .= " (无法连接到服务器，请检查网络或代理设置)";
            }
            throw new InvalidResponseException($errorMsg);
        }

        return $result;
    }
}