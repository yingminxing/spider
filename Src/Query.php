<?php
/**
 * Created by PhpStorm.
 * User: zuoluo
 * Date: 17/3/14
 * Time: 上午10:52
 */

namespace Yingminxing\Spider\Src;

use Yingminxing\Spider\Src\Lib\BaseQuery;
use Yingminxing\Spider\Src\Enum\ConstSetting;

class Query implements BaseQuery
{
    private $ch = null;
    private $timeout = 0;
    private $conTimeout = 0;
    private $inputEncoding = null;
    private $outputEncoding = null;
    private $cookies = [];
    private $domainCookies = [];
    private $hosts = [];
    private $headers = [];
    private $userAgent = '';
    private $clientIpArr = [];
    private $proxies = [];
    private $url = null;
    //private $domain = null;
    private $raw = null;
    private $content = null;
    private $info = [];
    private $referer = '';
    private $statusCode = 0;
    private $errorInfo = null;

    public function __construct()
    {
        $this->timeout = ConstSetting::TIMEOUT;
        $this->conTimeout = ConstSetting::CONTIMEOUT;
        $this->userAgent = ConstSetting::USERAGENT;
    }

    public function setConTimeout($second)
    {
        $this->conTimeout = $second;
    }

    public function setTimeout($second)
    {
        $this->timeout = $second;
    }

    public function setProxies($proxies)
    {
        $this->proxies = $proxies;
    }

    public function setHeaders($key, $value)
    {
        $this->headers[$key] = $value;
    }

    public function setCookie($key, $value, $domain = '')
    {
        if (empty($key)) {
            return ;
        }

        if ($domain) {
            $this->domainCookies[$key] = $value;
        } else {
            $this->cookies[$key] = $value;
        }
    }

    public function setCookies($cookies, $domain)
    {
        $cookiesArr = explode(";", $cookies);

        if (empty($cookiesArr)) {
            return ;
        }

        foreach ($cookiesArr as $cookie) {
            $key = strstr($cookie, '=', true);
            $value = substr(strstr($cookie, '='), 1);

            $this->setCookie($key, $value, $domain);
        }
    }

    public function getCookie($key, $domain)
    {
        if ($domain && !isset($this->domainCookies[$domain])) {
            return '';
        }

        $cookies = empty($domain) ? $this->cookies : $this->domainCookies[$domain];

        return $cookies[$key] ? $cookies[$key] : '';

    }

    public function getCookies($domain)
    {
        if ($domain && !isset($this->domainCookies[$domain])) {
            return [];
        }

        return empty($domain) ? $this->cookies : $this->domainCookies[$domain];
    }

    public function delCookies($domain)
    {
        if ($domain && !isset($this->domainCookies[$domain])) {
            return ;
        }

        if ($domain) {
            unset($this->domainCookies[$domain]);
        } else {
            unset($this->cookies);
        }
    }

    public function setUserAgents($userAgent)
    {
        $this->userAgent = $userAgent;
    }

    public function setHeaderUserAgent($userAgent)
    {
        $this->headers['User-Agent'] = $userAgent;
    }

    public function setReferer($referer)
    {
        $this->referer = $referer;
    }

    public function setClientIp($ip)
    {
        $this->headers['CLIENT-IP'] = $ip;
        $this->headers['X-FORWARDED-FOR'] = $ip;
    }

    public function setClientIpArr($ipArr)
    {
        $this->clientIpArr = $ipArr;
    }

    public function setHosts($hosts)
    {
        $this->hosts = $hosts;
    }

    public function getEncoding($string)
    {
        $encoding = mb_detect_encoding($string, array('UTF-8', 'GBK', 'GB2312', 'LATIN1', 'ASCII', 'BIG5'));

        return strtolower($encoding);
    }

    public function isUrl($url)
    {
        $pattern = '/http:\/\/[\w.]+[\w\/]*[\w.]*\??[\w=&\+\%]*/is';
        $pattern = "/\b(([\w-]+:\/\/?|www[.])[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|\/)))/";

        if (preg_match($pattern, $url)) {
            return true;
        }

        return false;
    }

    public function initCurl()
    {
        if ( !is_resource($this->ch)) {
            $this->ch = curl_init();

            curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $this->ch, CURLOPT_CONNECTTIMEOUT, $this->conTimeout );
            curl_setopt( $this->ch, CURLOPT_HEADER, false );
            curl_setopt( $this->ch, CURLOPT_USERAGENT, $this->userAgent );
            curl_setopt( $this->ch, CURLOPT_TIMEOUT, $this->timeout );
        }
    }

    public function get($url, $fields = [])
    {
        return $this->httpQuery($url, 'GET', $fields);
    }

    public function post($url, $fields = [])
    {
        return $this->httpQuery($url, 'POST', $fields);
    }

    public function put($url, $fields = [])
    {
        return $this->httpQuery($url, 'PUT', $fields);
    }

    public function delete($url, $fields = [])
    {
        return $this->httpQuery($url, 'DELETE', $fields);
    }

    public function head($url, $fields = [])
    {
        return $this->httpQuery($url, 'HEAD', $fields);
    }

    public function options($url, $fields = [])
    {
        return $this->httpQuery($url, 'OPTIONS', $fields);
    }

    public function patch($url, $fields = [])
    {
        return $this->httpQuery($url, 'PATCH', $fields);
    }

    public function getResponseBody($domain)
    {
        if (empty($this->raw)) {
            return '';
        }

        $this->getResponseCookies($domain);

        $header = '';
        // 解析http数据流
        // body里面可能有 \r\n\r\n，但是第一个一定是HTTP Header，去掉后剩下的就是body
        $tempArr = explode("\r\n\r\n", $this->raw);
        foreach ($tempArr as $key => $value) {
            // post 方法会有两个http header：HTTP/1.1 100 Continue、HTTP/1.1 200 OK
            if (preg_match("#^HTTP/.*? 100 Continue#", $value)) {
                unset($tempArr[$key]);
                continue;
            }
            if (preg_match("#^HTTP/.*? \d+ #", $value)) {
                $header = $value;
                unset($tempArr[$key]);
                $this->getResponseHeaders($value);
            }
        }
        $body = implode("\r\n\r\n", $tempArr);

        // 如果用户没有明确指定输入的页面编码格式(utf-8, gb2312)，通过程序去判断
        if($this->inputEncoding == null) {
            // 从头部获取
            preg_match("/charset=([^\s]*)/i", $header, $output);
            $encoding = empty($output[1]) ? '' : str_replace(array('"', '\''), '', strtolower(trim($output[1])));

            if (empty($encoding)) {
                // 在某些情况下,无法从response header中获取,则获取html的编码格式
                $encoding = strtolower($this->getEncoding($body));
                if($encoding == false || $encoding == "ascii") {
                    $encoding = 'gbk';
                }
            }
            $this->inputEncoding = $encoding;
        }

        // 设置了输出编码的转码，注意: xpath只支持utf-8，iso-8859-1 不要转，他本身就是utf-8
        if ($this->outputEncoding && $this->inputEncoding != $this->outputEncoding && $this->inputEncoding != 'iso-8859-1') {
            // 先将非utf8编码,转化为utf8编码
            $body = @mb_convert_encoding($body, $this->outputEncoding, $this->inputEncoding);
            // 将页面中的指定的编码方式修改为utf8
            $body = preg_replace("/<meta([^>]*)charset=([^>]*)>/is", '<meta charset="UTF-8">', $body);
        }

        return $body;
    }

    /**
     * 解析cookie存入cookies
     *
     * @param string $domain
     */
    public function getResponseCookies($domain)
    {
        preg_match_all("/.*?Set\-Cookie: ([^\r\n]*)/i", self::$raw, $matches);
        $cookies = empty($matches[1]) ? [] : $matches[1];

        if ($cookies) {
            $cookies = implode(";", $cookies);
            $cookies = explode(";", $cookies);

            foreach ($cookies as $cookie) {
                $cookieArr = explode("=", $cookie);
                // 过滤 httponly、secure
                if (count($cookieArr) < 2) {
                    continue;
                }

                $cookieName = $cookieArr[0] ? trim($cookieArr[0]) : '';
                if (empty($cookieName)) {
                    continue;
                }

                // 过滤掉domain路径
                if (in_array(strtolower($cookieName), array('path', 'domain', 'expires', 'max-age'))) {
                    continue;
                }
                $this->domainCookies[$domain][$cookieName] = trim($cookieArr[1]);
            }
        }
    }

    public function getResponseHeaders($html)
    {
        $headerLines = explode("\n", $html);

        if ($headerLines) {
            foreach ($headerLines as $line) {
                $headerArr = explode(":", $line);
                $key = empty($headerArr[0]) ? '' : trim($headerArr[0]);
                $value = empty($headerArr[1]) ? '' : trim($headerArr[1]);
                if (empty($key) || empty($value)) {
                    continue;
                }
                $this->headers[$key] = $value;
            }
        }
    }

    /**
     * 不同类型请求封装
     *
     * $method 有多种类型:1.GET; 2.POST, 3.PUT, 4.DELETE, 5.HEAD, 6.OPTIONS, 7.PATCH
     *
     * $fields 有三种类型:1、数组；2、http query；3、json
     * 1、array('name'=>'yangzetao') 2、http_build_query(array('name'=>'yangzetao')) 3、json_encode(array('name'=>'yangzetao'))
     * 前两种是普通的post，可以用$_POST方式获取
     * 第三种是post stream( json rpc，其实就是webservice )，虽然是post方式，但是只能用流方式 http://input 后者 $HTTP_RAW_POST_DATA 获取
     *
     * @param  string $url, $method, array() $fields
     * @return  string
     */
    public function httpQuery($url, $method, $fields)
    {
        $method = strtoupper($method);

        // 准备请求的curl参数
        $result = $this->prepareCurl($url, $method, $fields);

        if ($result['code'] == -1) {
            return $this->errorInfo;
        }

        // 执行curl
        $this->raw = curl_exec( $this->ch );
        $this->info = curl_getinfo( $this->ch );
        $this->statusCode = $this->info['http_code'];

        if ($this->raw === false) {
            $this->errorInfo = ' Curl error: ' . curl_error( $this->ch );
        }

        // 关闭句柄
        curl_close( $this->ch );

        $this->url = $url;
        $this->content = $this->getResponseBody($result['domain']);

        return $this->content;
    }

    private function prepareCurl($url, $method, $fields)
    {
        // 判断url合法性
        if ( !$this->isUrl($url) ) {
            $this->errorInfo = "You have requested URL ({$url}) is not a valid HTTP address";
            return ['code' => -1, 'res' => null];
        }

        // 如果是get方式，拼url
        if ($method == 'GET') {
            if ($fields) {
                $url = $url . (strpos($url, "?") === false ? "?" : "&") . http_build_query($fields);
            }
        } else {
            // 如果是 post 方式
            if ($method == 'POST') {
                curl_setopt( $this->ch, CURLOPT_POST, true );
            } else {
                $this->headers['X-HTTP-Method-Override'] = $method;
                curl_setopt( $this->ch, CURLOPT_CUSTOMREQUEST, $method );
            }
            if ($fields) {
                if (is_array($fields)) {
                    $fields = http_build_query($fields);
                }
                // 不能直接传数组，不知道是什么Bug，会非常慢
                curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $fields );
            }
        }

        // 解析url中scheme和host
        $parseUrl = parse_url($url);
        if (empty($parseUrl) || empty($parseUrl['host']) || !in_array($parseUrl['scheme'], ['http', 'https'])) {
            $this->errorInfo = "No connection adapters were found for '{$url}'";
            return ['code' => -1, 'res' => null];
        }

        $domain = $parseUrl['host'];

        $scheme = $parseUrl['scheme'];

        // 随机绑定hosts，做负载均衡?????
        if ($this->hosts) {
            if (isset($this->hosts[$domain])) {
                $hosts = $this->hosts[$domain];
                $key = rand(0, count($hosts)-1);
                $ip = $hosts[$key];
                $url = str_replace($domain, $ip, $url);
                $this->headers['Host'] = $domain;
            }
        }

        curl_setopt( $this->ch, CURLOPT_URL, $url );

        $cookies = $this->cookies();
        $domainCookies = $this->domainCookies($domain);
        $cookies =  array_merge($cookies, $domainCookies);

        if ($cookies) {
            $cookieArr = [];
            foreach ($cookies as $key => $value)
            {
                $cookieArr[] = $key . "=" . $value;
            }
            $cookies = implode("; ", $cookieArr);

            curl_setopt( $this->ch, CURLOPT_COOKIE, $cookies );
        }

        if ($this->userAgent) {
            $this->headers['User-Agent'] = $this->userAgent;
        }

        if ($this->clientIpArr) {
            $key = rand(0, count($this->clientIpArr) - 1);
            $this->headers['CLIENT-IP'] = $this->clientIpArr[$key];
            $this->headers['X-FORWARDED-FOR'] = $this->clientIpArr[$key];
        }

        if ($this->headers) {
            $headers = [];
            foreach ($this->headers as $key => $value) {
                $headers[] = $key . ": " . $value;
            }
            curl_setopt( $this->ch, CURLOPT_HTTPHEADER, $headers );
        }

        curl_setopt( $this->ch, CURLOPT_ENCODING, 'gzip');

        if ('https' == substr($url, 0, 5)) {
            curl_setopt( $this->ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt( $this->ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        // 启用代理
        if ($this->proxies[$scheme]) {
            curl_setopt( $this->ch, CURLOPT_PROXY, $this->proxies[$scheme]);
        }

        curl_setopt( $this->ch, CURLOPT_HEADER, true );

        return ['code' => 1, 'domain' => $domain];
    }

}