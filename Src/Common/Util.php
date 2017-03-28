<?php

/**
 * Created by PhpStorm.
 * User: zuoluo
 * Date: 17/3/26
 * Time: 下午3:12
 */

namespace Yingminxing\Spider\Src\Common;

class Util
{
    public static function putFile($file, $content, $flag = 0)
    {
        $pathInfo = pathinfo($file);
        if ($pathInfo['dirname']) {
            if (file_exists($pathInfo['dirname']) === false) {
                $result = @mkdir($pathInfo['dirname'], 0777, true);
                if ($result === false) {
                    return false;
                }
            }
        }

        if ($flag === FILE_APPEND) {
            // 多个php-fpm写一个文件的时候容易丢失，要加锁???
            //return @file_put_contents($file, $content, FILE_APPEND|LOCK_EX);
            return @file_put_contents($file, $content, FILE_APPEND);
        } else {
            return @file_put_contents($file, $content, LOCK_EX);
        }
    }

    public static function formatCsv($data)
    {
        if (empty($data) || !is_array($data)) {
            return ;
        }

        foreach ($data as &$value) {
            $value = str_replace(",", "", $value);
            $value = str_replace("，", "", $value);
        }

        return implode(",", $data);
    }

    public static function isWin()
    {
        return strtoupper(substr(PHP_OS,0,3))==="WIN";
    }

    public static function time2Second($time)
    {
        if (!is_numeric($time)) {
            return false;
        }

        $result = [
            'years' => 0,
            'days'  => 0,
            'hours' => 0,
            'minutes'=>0,
            'seconds'=>0
        ];

        if($time >= 31556926) {
            $result["years"] = floor($time / 31556926);
            $time = ($time % 31556926);
        }
        if($time >= 86400) {
            $result["days"] = floor($time / 86400);
            $time = ($time % 86400);
        }
        if($time >= 3600) {
            $result["hours"] = floor($time / 3600);
            $time = ($time % 3600);
        }
        if($time >= 60) {
            $result["minutes"] = floor($time / 60);
            $time = ($time % 60);
        }
        $result["seconds"] = floor($time);

        return $result["days"] . " days " . $result["hours"] . " hours " . $result["minutes"] . " minutes";
    }
}