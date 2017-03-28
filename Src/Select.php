<?php
/**
 * Created by PhpStorm.
 * User: zuoluo
 * Date: 17/3/17
 * Time: 下午1:35
 */

namespace Yingminxing\Spider\Src;

use DOMXPath;
use DOMDocument;
use phpQuery;

use Yingminxing\Spider\Src\Lib\BaseSelect;

class Select implements BaseSelect
{
    private $html = '';
    private $rule = '';
    private $dom = null;
    private $domAuth = null;
    private $domXpach = null;
    private $selectType = '';
    private $errorInfo = null;

    public function __construct($html, $selectType = 'xpath')
    {
        $this->html = $html;
        $this->selectType = $selectType ? $selectType : 'xpath';
    }

    public function match($html, $rule, $matchType = '')
    {
        if (empty($html)) {
            $this->error = 'there is noting to mathc';
            return '';
        }

        $matchType = $matchType ? strtolower($matchType) : strtolower($this->selectType);

        switch ($matchType)
        {
            case 'xpath' : $matchHtml = $this->matchXpath($html, $rule, false);
                break ;
            case 'regex' : $matchHtml = $this->matchRegex($html, $rule, false);
                break ;
            case 'css'   : $matchHtml = $this->matchCss($html, $rule, false);
                break ;
            default      :
                $matchHtml = '';
                $this->errorInfo = 'Please choose right match type, it is only support xpath,regex,css';
                break ;
        }

        return $matchHtml;
    }

    public function remove($html, $rule, $matchType)
    {
        if (empty($html)) {
            $this->error = 'there is noting to mathc';
            return '';
        }

        $matchType = strtolower($matchType);

        switch ($matchType)
        {
            case 'xpath' : $removeHtml = $this->matchXpath($html, $rule, false);
                break ;
            case 'regex' : $removeHtml = $this->matchRegex($html, $rule, false);
                break ;
            case 'css'   : $removeHtml = $this->matchCss($html, $rule, false);
                break ;
            default      :
                $this->errorInfo = 'Please choose right match type, it is only support xpath,regex,css';
                $removeHtml = $this->errorInfo;
                break ;
        }

        $html = str_replace($removeHtml, "", $html);
        return $html;
    }

    public function matchXpath($html, $rule, $removeFlag)
    {
        if (empty($this->dom)) {
            $this->dom = new DOMDocument();
        }

        if ($this->domAuth != md5($html)) {
            $this->domAuth = md5($html);
            @$this->dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $this->domXpach = new DOMXPath($this->dom);
        }

        $elements = @$this->domXpach->query($rule);
        if ($elements === false || is_null($elements)) {
            $this->errorInfo = "the selector in the xpath(\"{$rule}\") syntax errors";
            return '';
        }

        $result = [];
        foreach ($elements as $element) {
            if ($removeFlag) {
                $content = $this->dom->saveXml($element);
            } else {
                $nodeName = $element->nodeName;
                $nodeType = $element->nodeType;

                if ($nodeType == 1 && in_array($nodeName, ['img'])) {   // 如果是img标签，直接取src值
                    $content = $element->getAttribute('src');
                } elseif ($nodeType == 2 || $nodeType == 3 || $nodeType == 4) {   // 如果是标签属性，直接取节点值
                    $content = $nodeName;
                } else {
                    //给children二次提取
                    $content = $this->dom->saveXml($element);
                    $content = preg_replace(array("#^<{$nodeName}.*>#isU","#</{$nodeName}>$#isU"), array('', ''), $content);
                }
            }

            $result[] = $content;
        }

        if (empty($result)) {
            return '';
        }

        // 如果只有一个元素就直接返回string，否则返回数组??
        return count($result) > 1 ? $result : $result[0];
    }

    public function matchRegex($html, $rule, $removeFlag)
    {
        if ($this->domAuth != md5($html)) {
            $this->domAuth = md5($html);
        }

        if(@preg_match_all($rule, $html, $out) === false)
        {
            $this->errorInfo = "the selector in the regex(\"{$rule}\") syntax errors";
            return '';
        }

        $count = count($out);
        if (empty($count)) {
            return '';
        }

        $result = [];
        if ($count == 0) {
            return '';
        } elseif ($count == 2) {
            if ($removeFlag) {
                $result = $out[0];
            } else {
                $result = $out[1];
            }
        } else {
            for ($i = 1; $i < $count; $i++) {
                $result[] = count($out[$i]) > 1 ? $out[$i] : $out[$i][0];
            }
        }

        if (empty($result))
        {
            return '';
        }

        return count($result) > 1 ? $result : $result[0];
    }

    public function matchCss($html, $rule, $removeFlag)
    {
        if ($this->domAuth != md5($html)) {
            $this->domAuth = md5($html);
            phpQuery::loadDocumentHTML($html);
        }

        if ($removeFlag) {
            $result = pq($rule)->remove();
        } else {
            $result = pq($rule)->html();
        }

        return $result;
    }
}