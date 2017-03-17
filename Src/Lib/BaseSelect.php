<?php
/**
 * Created by PhpStorm.
 * User: zuoluo
 * Date: 17/3/13
 * Time: 下午8:44
 */

namespace Yingminxing\Spider\Src\Lib;

interface BaseSelect
{
    /**
     * 根据匹配规则查询内容
     *
     * @param int $html, $rule, $matchType
     * @return string
     */
    public function match($html, $rule, $matchType);

    /**
     * 根据匹配规则删除内容
     *
     * @param int $html, $rule, $matchType
     * @return string
     */
    public function remove($html, $rule, $matchType);

    /**
     * 根据xpath匹配规则删除内容
     *
     * @param int $html, $rule, $removeFlag
     * @return string
     */
    public function matchXpath($html, $rule, $removeFlag);

    /**
     * 根据regex匹配规则删除内容
     *
     * @param int $html, $rule, $removeFlag
     * @return string
     */
    public function matchRegex($html, $rule, $removeFlag);

    /**
     * 根据css匹配规则删除内容
     *
     * @param int $html, $rule, $removeFlag
     * @return string
     */
    public function matchCss($html, $rule, $removeFlag);
}