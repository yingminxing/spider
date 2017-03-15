<?php
/**
 * Created by PhpStorm.
 * User: zuoluo
 * Date: 17/3/13
 * Time: 下午8:44
 */

namespace Yingminxing\Spider\Src\Lib;

interface BaseQuery
{
    /**
     * 设置链接超时时间
     *
     * @param int $second
     * @return void
     */
    public function setConTimeout($second);

    /**
     * 设置服务超时时间
     *
     * @param int $second
     * @return void
     */
    public function setTimeout($second);

    /**
     * 设置请求代理
     *
     * @param array $proxies
     * array (
     *    'http': 'socks5://user:pass@host:port',
     *    'https': 'socks5://user:pass@host:port'
     *)
     * @return void
     */
    public function setProxies($proxies);

    /**
     * 设置Headers
     *
     * @param string $key, $value
     * @return void
     */
    public function setHeaders($key, $value);

    /**
     * 设置Cookie
     *
     * @param string $key, $value, [$domains]
     * @return void
     */
    public function setCookie($key, $value, $domain);

    /**
     * 设置Cookies
     *
     * @param string $cookie, $domain
     * @return void
     */
    public function setCookies($cookies, $domain);

    /**
     * 获取Cookie
     *
     * @param string $key, [$domain]
     * @return string
     */
    public function getCookie($key, $domian);

    /**
     * 获取Cookies
     *
     * @param string [$domain]
     * @return array()
     */
    public function getCookies($domain);

    /**
     * 删除Cookies
     *
     * @param string [$domain]
     * @return void
     */
    public function delCookies($domain);

    /**
     * 设置多种userAgent
     *
     * @param string $userAgents
     * @return void
     */
    public function setUserAgents($userAgents);

    /**
     * 给header随机设置userAgent
     *
     * @param string $userAgent
     * @return void
     */
    public function setHeaderUserAgent($userAgent);

    /**
     * 设置referer
     *
     * @param string $referer
     * @return void
     */
    public function setReferer($referer);

    /**
     * 设置客户端Ip
     *
     * @param string
     * @return void
     */
    public function setClientIp($ip);

    /**
     * 设置多种伪造IP
     *
     * @param array()
     * @return void
     */
    public function setClientIpArr($ipArr);

    /**
     * 设置Hosts
     *
     * @param string $hosts
     * @return void
     */
    public function setHosts($hosts);

    /**
     * 获取文件编码
     *
     * @param string
     * @return string
     */
    public function getEncoding($string);

    /**
     * 判断参数是否是url
     *
     * @param  string  $url
     * @return boolean
     */
    public function isUrl($url);

    /**
     * 初始化CURL
     *
     * @param  void
     * @return  object
     */
    public function initCurl();

    /**
     * 获取response的body
     *
     * @param  string $domain
     * @return  void
     */
    public function getResponseBody($domain);

    /**
     * 获取response的cookies
     *
     * @param  string $domain
     * @return  void
     */
    public function getResponseCookies($domain);

    /**
     * 获取response的cookies
     *
     * @param  string $html
     * @return  void
     */
    public function getResponseHeaders($html);

    /**
     * 请求
     *
     * @param  string $url, $method, array() $fields
     * @return  string
     */
    public function httpQuery($url, $method, $fields);
}