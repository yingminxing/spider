<?php
/**
 * Created by PhpStorm.
 * User: zuoluo
 * Date: 17/3/18
 * Time: 下午3:10
 */

namespace Yingminxing\Spider\Src\Lib;

interface BaseSpider
{
    /**
     * 爬虫运行函数
     *
     * @param int $html, $rule, $matchType
     * @return string
     */
    public function start();


}