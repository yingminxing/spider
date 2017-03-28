<?php
/**
 * Created by PhpStorm.
 * User: zuoluo
 * Date: 17/3/18
 * Time: 下午5:31
 */

namespace Yingminxing\Spider\Src;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Yingminxing\Spider\Src\Enum\SpiderSetting;
use Illuminate\Support\Facades\DB;
use Yingminxing\Spider\Src\Common\Util;

use Yingminxing\Spider\Src\Lib\BaseSpider;

class Spider implements BaseSpider
{
    // 任务名称
    private $name = '';
    // 任务开始时间
    private $timeStart = 0;
    // 任务数量
    private $taskNum = 1;
    // 任务Id
    private $taskId = 1;
    // 当前服务器进程Id
    private $taskPid = 1;
    // 主任务进程
    private $taskMaster = true;
    // 创建子进程标示
    private $forkTaskComplete = false;
    // 服务器Id
    private $serverId = 1;
    // 运行参数
    private $configs = [];
    // 进程模式
    private $daemonize = true;
    // 当前进程是否终止
    private $terminate = false;
    // 服务器数量
    private $multiServer = 1;
    // 是否保存爬虫运行状态
    private $saveRunningState = false;
    // 是否使用redis
    private $useRedis = false;
    // 扫描的域名
    private $domains = [];
    // 扫描的URL
    private $scanUrls = [];
    // 收集URL的数组
    private $collectUrls = [];
    // 收集URL的数量
    private $collectUrlsNum = 0;
    // 收集URL的队列
    private $collectQueue = [];
    // 收集成功数量
    private $collectSucc = 0;
    // 收集失败数量
    private $collectFail = 0;
    // 已经爬得的url数量
    private $collectedUrlsNum = 0;
    // 提取到的字段数
    private $fieldsNum = 0;
    // 采集深度
    private $depthNum = 0;
    // 任务状态
    private $taskStatus = [];

    // 回调函数
    // 爬虫初始化时调用, 用来指定一些爬取前的操作
    private $onStart = null;
    // 判断当前网页是否被反爬虫
    private $isAntiSpider = null;
    // 在一个网页下载完成之后调用, 主要用来对下载的网页进行处理
    private $onDownloadPage = null;
    // URL属于入口页
    // 在爬取到入口url的内容之后, 添加新的url到待爬队列之前调用
    // 主要用来发现新的待爬url, 并且能给新发现的url附加数据
    private $onScanPage = null;
    // URL属于列表页
    // 在爬取到入口url的内容之后, 添加新的url到待爬队列之前调用
    // 主要用来发现新的待爬url, 并且能给新发现的url附加数据
    private $onListPage = null;
    // URL属于内容页
    // 在爬取到入口url的内容之后, 添加新的url到待爬队列之前调用
    // 主要用来发现新的待爬url, 并且能给新发现的url附加数据
    private $onContentPage = null;
    // 在一个网页的所有field抽取完成之后, 可能需要对field进一步处理, 以发布到自己的网站
    private $onExtractPage = null;
    // 当前页面抽取到URL
    private $onFetchUrl = null;

    // 导出类型配置
    private $exportFile = '';
    private $exportType = '';
    private $exportTable = '';

    // 错误信息
    private $errorInfo = null;

    public function __construct($configs = [])
    {
        // 设置基本参数
        $this->setConfig($configs);

        // 检查基本设置
        $this->checkSetting();

        // 检查多服务的redis设置
        $this->checkRedis();

        // 检查验证导出
        $this->checkExport();

        // 检查redis中缓存数据
        $this->checkCache();

    }

    private function setConfig($configs)
    {
        // 常用配置参数设置化
        $this->configs['name']        = isset($configs['name'])        ? $configs['name']        : 'spider';
        $this->configs['proxy']       = isset($configs['proxy'])       ? $configs['proxy']       : '';
        $this->configs['user_agent']  = isset($configs['user_agent'])  ? $configs['user_agent']  : SpiderSetting::AGENT_PC;
        $this->configs['user_agents'] = isset($configs['user_agents']) ? $configs['user_agents'] : null;
        $this->configs['client_ip']   = isset($configs['client_ip'])   ? $configs['client_ip']   : null;
        $this->configs['client_ips']  = isset($configs['client_ips'])  ? $configs['client_ips']  : null;
        $this->configs['interval']    = isset($configs['interval'])    ? $configs['interval']    : SpiderSetting::INTERVAL;
        $this->configs['timeout']     = isset($configs['timeout'])     ? $configs['timeout']     : SpiderSetting::TIMEOUT;
        $this->configs['max_try']     = isset($configs['max_try'])     ? $configs['max_try']     : SpiderSetting::MAXTRY;
        $this->configs['max_depth']   = isset($configs['max_depth'])   ? $configs['max_depth']   : 0;
        $this->configs['max_fields']  = isset($configs['max_fields'])  ? $configs['max_fields']  : 0;
        $this->configs['export']      = isset($configs['export'])      ? $configs['export']      : [];

        // posix_getpid只能在linux下运行,在win下无法运行
        $this->taskPid = function_exists('posix_getpid') ? posix_getpid() : 1;
    }

    private function checkSetting()
    {
        // 检查PHP版本
        if (version_compare(PHP_VERSION, '5.5.9', 'lt')) {
            Log::error('PHP 5.6+ is required, currently installed version is: ' . phpversion());
            exit ;
        }

        // 检查CURL扩展
        if(!function_exists('curl_init')) {
            Log::error("The curl extension was not found");
            exit ;
        }

        // 多任务需要pcntl扩展支持
        if ($this->taskNum > 1 && !function_exists('pcntl_fork')) {
            Log::error("Multitasking needs pcntl, the pcntl extension was not found");
            exit ;
        }

        // 守护进程需要pcntl扩展支持
        if ($this->daemonize && !function_exists('pcntl_fork')) {
            Log::error("Daemonize needs pcntl, the pcntl extension was not found");
            exit ;
        }

        // 检查scan_urls是否合法
        if (empty($this->scanUrls)) {
            Log::error("No scan url to start");
            exit;
        }

        foreach ( $this->scanUrls as $url ) {
            // 只检查配置中的入口URL, 通过 add_scan_url 添加的不检查了.
            if (!$this->isScanPage($url)) {
                Log::error("Domain of scan_urls (\"{$url}\") does not match the domains of the domain name");
                exit;
            }
        }

        // 日志输出,目前只支持日志文件输出
        $this->logCurInfo();

    }

    private function checkRedis()
    {
        // 集群、保存运行状态、多任务都需要Redis支持
        if ($this->multiServer || $this->saveRunningState || $this->taskNum > 1) {
            $this->useRedis = true;

            try {
                Redis::connect();
            } catch (\Exception $e) {
                if ($this->multiServer) {
                    Log::error('Multiserver needs Redis support, Error');
                    $this->errorInfo = 'Multiserver needs Redis support, Error';
                    exit ;
                }

                if ($this->taskNum) {
                    Log::error('Multitasking needs Redis support, Error');
                    $this->errorInfo = 'Multitasking needs Redis support, Error';
                    exit ;
                }

                if ($this->saveRunningState) {
                    Log::error('Spider kept running state needs Redis support, Error');
                    $this->errorInfo = 'Spider kept running state needs Redis support, Error';
                    exit ;
                }
            }
        }
    }

    private function checkExport()
    {

    }

    private function checkCache()
    {

    }

    private function isScanPage($url)
    {
        $parseUrl = parse_url($url);

        if (empty($parseUrl) || !in_array($parseUrl['host'], $this->domains)) {
            return false;
        }

        return true;
    }

    private function isListPage($url)
    {
        $result = false;

        if ($this->configs['list_url_regexes']) {
            foreach ($this->configs['list_url_regexes'] as $regex) {
                if (preg_match("#{$regex}#i", $url)) {
                    $result = true;
                    break;
                }
            }
        }

        return $result;
    }

    private function isContentPage($url)
    {
        $result = false;

        if ($this->configs['content_url_regexes']) {
            foreach ($this->configs['content_url_regexes'] as $regex) {
                if (preg_match("#{$regex}#i", $url)) {
                    $result = true;
                    break;
                }
            }
        }

        return $result;
    }

    private function logCurInfo()
    {
        $this->timeStart = time();
        $msg = "[ " . $this->name . " Spider ] is started...\n\n";
        Log::info($msg);
    }

    private function initRedis()
    {
        if (!$this->useRedis) {
            return ;
        }

        // 添加当前服务器到服务器列表
        $this->addServerList($this->serverId, $this->taskNum);

        // 删除当前服务器的任务状态
        for ($i = 1; $i <= $this->taskNum; $i++) {
            $this->delTaskId($this->serverId, $i);
        }
    }

    /**
     * 添加当前服务器信息到服务器列表
     *
     * @return void
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-11-16 11:06
     */
    private function addServerList($serverId, $taskNum)
    {
        if (!$this->useRedis) {
            return ;
        }

        // 更新服务器列表
        $serverListJson = Redis::get('server_list');
        if ($serverListJson) {
            $serverList = json_decode($serverListJson, JSON_UNESCAPED_UNICODE);
        }

        $serverList[$serverId] = [
            'serverId' => $serverId,
            'taskNum' => $taskNum,
            'time' => time(),
        ];

        Redis::set("server_list", json_encode($serverList));
    }

    /**
     * 删除任务Id
     *
     * @return void
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-11-16 11:06
     */
    private function delTaskId($serverId, $taskId)
    {
        if (!$this->useRedis) {
            return ;
        }

        $tempKey = "server_{$serverId}_task_id-{$taskId}";
        Redis::del($tempKey);
    }

    public function start()
    {
        // 日志记录开始
        $this->logCurInfo();

        // 初始化redis
        $this->initRedis();

        // 添加入口URL到队列
        foreach ($this->scanUrls as $url) {
            $this->addScanUrl($url);
        }

        // 将参数添加到入口页面
        if ($this->onStart) {
            call_user_func($this->onStart, $this);
        }

        // 启动后台进程
        $this->daemonize();

        // 安装信号
        $this->installSignal();

        // 开始采集
        $this->doCollectPage();

    }

    private function addScanUrl($url, $options = [], $allowRepeat = true)
    {
        $link = $options;
        $link['url'] = $url;

        if (!isset($link['proxy'])) {
            $link['proxy'] = $this->configs['proxy'];
        }

        if (!isset($link['max_try'])) {
            $link['max_try'] = $this->configs['maxTry'];
        }

        if ($this->isListPage($url)) {
            $link['url_type'] = 'list_page';
        } elseif ($this->isContentPage($url)) {
            $link['url_type'] = 'content_page';
        } else {
            $link['url_type'] = 'scan_page';
        }

        $status = $this->linkPush($link, $allowRepeat, 'L');

        if ($status) {
            if ($link['url_type'] == 'scan_page') {
                Log::debug("Find scan page: {$url}");
            }
            elseif ($link['url_type'] == 'list_page') {
                Log::debug("Find list page: {$url}");
            }
            elseif ($link['url_type'] == 'content_page') {
                Log::debug("Find content page: {$url}");
            }
        }
    }

    private function linkPush($link, $allowRepeat, $direction)
    {
        if (empty($link) || empty($link['url'])) {
            return false;
        }

        // 向左或者右入队列
        if (!in_array($direction, ['L', 'R'])) {
            return false;
        }

        $url = $link['url'];
        $status = false;

        if ($this->useRedis) {
            $key = 'collect_urls_' . md5($url);
            $exists = Redis::exists($key);

            if (!$exists || $allowRepeat) {
                // 待爬取网页记录数加一
                Redis::incr('collect_urls_num');
                // 先标记为待爬取网页
                Redis::set($key, time());

                $link = json_encode($link);
                if ($direction == 'L') {
                    Redis::lpush("collect_queue", $link);
                } elseif ($direction == 'R') {
                    Redis::rpush("collect_queue", $link);
                }

                $status = true;
            }
        } else {
            $key = md5($url);
            if (!array_key_exists($key, $this->collectUrls))
            {
                $this->collectUrlsNum ++;
                $this->collectUrls[$key] = time();
                array_push($this->collectQueue, $link);

                $status = true;
            }
        }

        return $status;
    }

    private function daemonize()
    {
        if (!$this->daemonize) {
            return;
        }

        umask(0);

        $pid = pcntl_fork();
        if ($pid < 0) {  // exception
            exit ;
        } else if ($pid) { // parent
            exit ;
        } else { // child
            $sid = posix_setsid();
            if ($sid < 0) {  // exception
                exit ;
            }
        }
    }

    private function installSignal()
    {
        if (function_exists('pcntl_signal')) {
            // stop
            pcntl_signal(SIGINT, array(__CLASS__, 'signalHandler'), false);
            // status
            pcntl_signal(SIGUSR2, array(__CLASS__, 'signalHandler'), false);
            // ignore
            pcntl_signal(SIGPIPE, SIG_IGN, false);
        }
    }

    private function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGINT:
                Log::warn("Program stopping...");
                $this->terminate = true;
                break;
            // Show status.
            case SIGUSR2:
                echo "show status\n";
                break;
        }
    }

    private function doCollectPage()
    {
        $queueLen = $this->getQueueLength();

        if ($this->taskMaster) { // 如果是主任务
            if ($this->taskNum > 1 && !$this->forkTaskComplete) {   // 多任务下主任务未准备就绪
                if ($queueLen > $this->taskNum * 2) { // 主进程采集到两倍于任务数时, 生成子任务一起采集??
                    $this->forkTaskComplete = true;

                    for ($i = 2; $i < $this->taskNum; $i ++) { // task进程从2开始, 1被master进程所使用
                        $this->forkOneTask($i);
                    }
                }
            }

            // 抓取页面
            $this->collectPage();
            // 保存任务状态
            $this->setTaskStatus();

        } else { // 如果是子任务
            // 如果队列中的网页比任务数2倍多, 子任务可以采集, 否则等待...
            if ($queueLen > $this->taskNum * 2) {
                $this->collectPage();

                $this->setTaskStatus();
            } else {
                Log::warn("Task(" . $this->taskId . ") waiting...");
                sleep(1);
            }
        }

        // 检查进程是否收到关闭信号
        $this->checkTerminate();

        // 从服务器列表中删除当前服务器信息
        $this->delServerList($this->serverId);
    }

    private function checkTerminate()
    {
        if (!$this->terminate) {
            return false;
        }

        $this->delTaskStatus($this->serverId, $this->taskId);

        if ($this->taskMaster) {
            // 检查子进程是否都退出
            while (true) {
                $allStop = true;
                for ($i = 2; $i <= $this->taskNum; $i++) {
                    // 只要一个还活着就说明没有完全退出
                    $taskStatus = $this->getTaskStatus($this->serverId, $i);
                    if ($taskStatus) {
                        $allStop = false;
                    }
                }
                if ($allStop) {
                    break;
                } else {
                    Log::warn("Task stop waiting...");
                }
                sleep(1);
            }

            $this->delServerList($this->serverId);

            $spiderTimeRun = Util::time2Second(intval(microtime(true) - $this->timeStart));
            Log::note("Spider finished in {$spiderTimeRun}");

            $getCollectedUrlNum = $this->getCollectedUrlNum();
            Log::note("Total pages: {$getCollectedUrlNum} \n");
        }

        exit();
    }

    private function getCollectedUrlNum()
    {
        if ($this->useRedis) {
            return Redis::get("collected_urls_num");
        } else {
            return $this->collectedUrlsNum;
        }
    }

    private function getCollectUrlNum()
    {
        if ($this->useRedis) {
            return Redis::get("collect_urls_num");
        } else {
            return $this->collectUrlsNum;
        }
    }

    private function delServerList($serverId)
    {
        if (!$this->useRedis) {
            return false;
        }

        $serverListJson = Redis::get("server_list");
        if ($serverListJson) {
            $serverList = json_decode($serverListJson, true);
            if (isset($serverList[$serverId])) {
                unset($serverList[$serverId]);
            }

            if ($serverList) {
                ksort($serverList);
                Redis::set("server_list", json_encode($serverList));
            }
        }
    }

    private function setTaskStatus()
    {
        // 每采集成功一个页面, 生成当前进程状态到文件, 供主进程使用
        $mem = round(memory_get_usage(true)/(1024*1024),2);
        $useTime = microtime(true) - $this->timeStart;
        $speed = round(($this->collectSucc + $this->collectFail) / $useTime, 2);

        $status = array(
            'id' => $this->taskId,
            'pid' => $this->taskPid,
            'mem' => $mem,
            'collect_succ' => $this->collectSucc,
            'collect_fail' => $this->collectFail,
            'speed' => $speed,
        );
        $taskStatus = json_encode($status);

        if ($this->useRedis) {
            $key = "server_" . $this->serverId . "_task_status_" . $this->taskId;
            Redis::set($key, $taskStatus);
        } else {
            $this->taskStatus = [$taskStatus];
        }
    }

    private function getTaskStatus($serverId, $taskId)
    {
        if (!$this->useRedis) {
            return false;
        }

        $key = "server_{$serverId}_task_status_{$taskId}";
        return Redis::get($key);
    }

    private function delTaskStatus($serverId, $taskId)
    {
        if (!$this->useRedis) {
            return false;
        }

        $key = "server_{$serverId}_task_status_{$taskId}";
        Redis::del($key);
    }

    private function getQueueLength()
    {
        if ($this->useRedis) {
            return Redis::llen('collect_queue');
        } else {
            return count($this->collectQueue);
        }
    }

    private function forkOneTask($taskId)
    {
        $pId = pcntl_fork();

        if($pId > 0) { // 主进程记录子进程pid
            // do nothing
        } elseif(0 === $pId) {// 子进程运行
            Log::warn("Fork children task({$taskId}) successful...");

            // 初始化子进程参数
            $this->timeStart = microtime(true);
            $this->taskId = $taskId;
            $this->taskMaster = false;
            $this->taskPid = posix_getpid();
            $this->collectSucc = 0;
            $this->collectFail = 0;

            $this->doCollectPage();

            // 这里用0表示正常退出
            exit(0);
        } else {
            Log::error("Fork children task({$taskId}) fail...");
            exit;
        }
    }

    private function collectPage()
    {
        $getCollectUrlNum = $this->getCollectUrlNum();
        Log::info("Find pages: {$getCollectUrlNum} ");

        $queueLen = $this->getQueueLength();
        Log::info("Waiting for collect pages: {$queueLen} ");

        $getCollectedUrlNum = $this->getCollectedUrlNum();
        Log::info("Collected pages: {$getCollectedUrlNum} ");

        // 多任务的时候输出爬虫序号
        if ($this->taskNum > 1) {
            Log::info("Current task id: " . $this->taskId);
        }

        $link = $this->queueRPop();
        $url = $link['url'];

        // 标记为已爬取网页
        $this->incrCollectedUrlNum($url);

        // 爬取页面开始时间
        $pageTimeStart = microtime(true);
        $html = $this->queryUrl($url, $link);

        if (!$html) {
            return false;
        }

        // 当前正在爬取的网页页面的对象
        $page = [
            'url'     => $url,
            'raw'     => $html,
            'request' => [
                'url'          => $url,
                'method'       => $link['method'],
                'headers'      => $link['headers'],
                'params'       => $link['params'],
                'context_data' => $link['context_data'],
                'try_num'      => $link['try_num'],
                'max_try'      => $link['max_try'],
                'depth'        => $link['depth'],
                'taskId'       => $this->taskId,
            ]
        ];
        unset($html);

        //--------------------------------------------------------------------------------
        // 处理回调函数
        //--------------------------------------------------------------------------------
        // 判断当前网页是否被反爬虫了, 需要开发者实现
        if ($this->isAntiSpider) {
            $isAntiSpider = call_user_func($this->isAntiSpider, $url, $page['raw'], $this);
            // 如果在回调函数里面判断被反爬虫并且返回true
            if ($isAntiSpider) {
                return false;
            }
        }

        // 在一个网页下载完成之后调用. 主要用来对下载的网页进行处理.
        // 比如下载了某个网页, 希望向网页的body中添加html标签
        if ($this->onDownloadPage) {
            $return = call_user_func($this->onDownloadPage, $page, $this);
            if (isset($return)) {
                $page = $return;
            }
        }

        // 是否从当前页面分析提取URL
        // 回调函数如果返回false表示不需要再从此网页中发现待爬url
        $isFindUrl = true;
        if ($link['url_type'] == 'scan_page') {
            if ($this->onScanPage) {
                $return = call_user_func($this->onScanPage, $page, $page['raw'], $this);
                if (isset($return)) {
                    $isFindUrl = $return;
                }
            }
        }
        elseif ($link['url_type'] == 'list_page') {
            if ($this->onListPage) {
                $return = call_user_func($this->onListPage, $page, $page['raw'], $this);
                if (isset($return)) {
                    $isFindUrl = $return;
                }
            }
        }
        elseif ($link['url_type'] == 'content_page') {
            if ($this->onContentPage) {
                $return = call_user_func($this->onContentPage, $page, $page['raw'], $this);
                if (isset($return)) {
                    $isFindUrl = $return;
                }
            }
        }

        // on_scan_page、on_list_page、on_content_page 返回false表示不需要再从此网页中发现待爬url
        if ($isFindUrl) {
            // 如果深度没有超过最大深度, 获取下一级URL
            if ($this->configs['max_depth'] == 0 || $link['depth'] < $this->configs['max_depth']) {
                // 分析提取HTML页面中的URL
                $this->getUrls($page['raw'], $url, $link['depth'] + 1);
            }
        }

        // 如果是内容页, 分析提取HTML页面中的字段
        // 列表页也可以提取数据的, source_type: urlcontext, 未实现
        if ($link['url_type'] == 'content_page') {
            $this->getHtmlFields($page['raw'], $url, $page);
        }

        // 如果当前深度大于缓存的, 更新缓存
        $this->incrDepthNum($link['depth']);

        // 处理页面耗时时间
        $timeRun = round(microtime(true) - $pageTimeStart, 3);
        Log::debug("Success process page {$url} in {$timeRun} s");

        $spiderTimeRun = Util::time2Second(intval(microtime(true) - $this->timeStart));
        Log::info("Spider running in {$spiderTimeRun}");

        if (!isset($this->configs['interval'])) {
            // 默认睡眠100毫秒, 太快了会被认为是ddos
            $this->configs['interval'] = 100;
        }
        usleep($this->configs['interval'] * 1000);
    }

    private function incrDepthNum($depth)
    {
        if ($this->useRedis) {
            $depthNum = Redis::get('depth_num');
            if ($depthNum < $depth) {
                Redis::set("depth_num", $depth);
            }
        } else {
            if ($this->depthNum < $depth) {
                $this->depthNum = $depth;
            }
        }
    }

    private function getHtmlFields($html, $url, $page)
    {
        $fields = $this->getFields($this->configs['fields'], $html, $url, $page);

        if ($fields) {
            if ($this->onExtractPage) {
                $return = call_user_func($this->onExtractPage, $page, $fields);
                if (!isset($return)) {
                    Log::warn("on_extract_page return value can't be empty");
                }
                elseif (!is_array($return)) {
                    Log::warn("on_extract_page return value must be an array");
                } else {
                    $fields = $return;
                }
            }

            if (isset($fields) && is_array($fields)) {
                $fieldsNum = $this->incrFieldsNum();
                if ($this->configs['max_fields'] != 0 && $fieldsNum > $this->configs['max_fields']) {
                    exit ;
                }

                if (version_compare(PHP_VERSION,'5.4.0','<')) {
                    $fieldsStr = json_encode($fields);
                    $fieldsStr = preg_replace_callback( "#\\\u([0-9a-f]{4})#i", function($matchs) {
                        return iconv('UCS-2BE', 'UTF-8', pack('H4', $matchs[1]));
                    }, $fieldsStr );
                } else {
                    $fieldsStr = json_encode($fields, JSON_UNESCAPED_UNICODE);
                }

                if (Util::isWin()) {
                    $fieldsStr = mb_convert_encoding($fieldsStr, 'gb2312', 'utf-8');
                }
                Log::info("Result[ {$fieldsNum} ]: " . $fieldsStr);

                // 如果设置了导出选项
                if ($this->configs['export']) {
                    $this->exportType = isset($this->configs['export']['type']) ? $this->configs['export']['type'] : '';
                }

                if ($this->exportType == 'csv') {
                    Util::putFile($this->exportFile, Util::formatCsv($fields) . "\n", FILE_APPEND);
                } elseif ($this->exportType == 'sql') {
                    $sql = $this->getInsertSql($this->exportTable, $fields, true);
                    Util::putFile($this->exportFile, $sql.";\n", FILE_APPEND);
                } elseif ($this->exportType == 'db') {
                    $sql = $this->getInsertSql($this->exportTable, $fields, true);
                    DB::insert($sql);
                }
            }
        }
    }

    private function getInsertSql($table, $data)
    {
        if (empty($table) || empty($data) || !is_array($data)) {
            return '';
        }

        $itemsSql = $valuesSql = "";
        foreach ($data as $k => $v) {
            $v = stripslashes($v);
            $v = addslashes($v);
            $itemsSql .= "`$k`,";
            $valuesSql .= "\"$v\",";
        }
        $sql = "Insert Ignore Into `{$table}` (" . substr($itemsSql, 0, -1) . ") Values (" . substr($valuesSql, 0, -1) . ")";

        return $sql;
    }

    private function incrFieldsNum()
    {
        if ($this->useRedis) {
            $fieldsNum = Redis::incr('fields_num');
        } else {
            $fieldsNum = ++ $this->fieldsNum;
        }

        return $fieldsNum;
    }

    private function getUrls($html, $collectUrl, $depth = 0)
    {
        //--------------------------------------------------------------------------------
        // 正则匹配出页面中的URL
        //--------------------------------------------------------------------------------
        $selectObj = new Select();
        $urls = $selectObj->match($html, '//a/@href');

        if (empty($urls)) {
            return false;
        }

        foreach ($urls as &$url) {
            $url = str_replace(array("\"", "'",'&amp;'), array("",'','&'), $url);
        }

        // 去除重复的RUL
        $urls = array_unique($urls);
        $collectUrl = trim($collectUrl);
        foreach ($urls as $key => $url) {
            $url = trim($url);

            if (empty($url)) {
                continue ;
            }

            $value = $this->fillUrl($url, $collectUrl);

            if ($value) {
                $urls[$key] = $value;
            } else {
                unset($urls[$key]);
            }
        }

        if (empty($urls)) {
            return false;
        }

        //--------------------------------------------------------------------------------
        // 把抓取到的URL放入队列
        //--------------------------------------------------------------------------------
        foreach ($urls as $url) {
            if ($this->onFetchUrl) {
                $return = call_user_func($this->onFetchUrl, $url, $this);
                $url = isset($return) ? $return : $url;
                unset($return);

                // 如果 onFetchUrl 返回 false，此URL不入队列
                if (empty($url)) {
                    continue;
                }
            }

            // 把当前页当做找到的url的Referer页
            $options = [
                'headers' => [
                    'Referer' => $collectUrl
                ]
            ];
            $this->addUrl($url, $options, $depth);
        }
    }

    private function addUrl($url, $options = [], $depth = 0)
    {
        $status = false;

        $link = $options;
        $link['url'] = $url;
        $link['depth'] = $depth;
        $link = $this->linkUncompress($link);

        if ($this->isListPage($url)) {
            $link['url_type'] = 'list_page';
            $status = $this->linkPush($link, false, 'L');
        }

        if ($this->is_content_page($url)) {
            $link['url_type'] = 'content_page';
            $status = $this->linkPush($link, false, 'L');
        }

        if ($status) {
            if ($link['url_type'] == 'scan_page') {
                Log::debug("Find scan page: {$url}");
            } elseif ($link['url_type'] == 'list_page') {
                Log::debug("Find list page: {$url}");
            } elseif ($link['url_type'] == 'content_page') {
                Log::debug("Find content page: {$url}");
            }
        }

        return $status;
    }

    private function linkUncompress($link)
    {
        $link = [
            'url'          => isset($link['url'])          ? $link['url']          : '',
            'url_type'     => isset($link['url_type'])     ? $link['url_type']     : '',
            'method'       => isset($link['method'])       ? $link['method']       : 'get',
            'headers'      => isset($link['headers'])      ? $link['headers']      : [],
            'params'       => isset($link['params'])       ? $link['params']       : [],
            'context_data' => isset($link['context_data']) ? $link['context_data'] : '',
            'proxy'        => isset($link['proxy'])        ? $link['proxy']        : $this->configs['proxy'],
            'try_num'      => isset($link['try_num'])      ? $link['try_num']      : 0,
            'max_try'      => isset($link['max_try'])      ? $link['max_try']      : $this->configs['max_try'],
            'depth'        => isset($link['depth'])        ? $link['depth']        : 0,
        ];

        return $link;
    }

    private function fillUrl($url, $collectUrl)
    {
        // 排除JavaScript的连接
        if( preg_match("@^(javascript:|#|'|\")@i", $url) || $url == '') {
            return false;
        }
        // 排除没有被解析成功的语言标签
        if (substr($url, 0, 3) == '<%=') {
            return false;
        }

        $parseUrl = @parse_url($collectUrl);
        if (empty($parseUrl['scheme']) || empty($parseUrl['host'])) {
            return false;
        }

        $scheme = $parseUrl['scheme'];
        $domain = $parseUrl['host'];
        $path = empty($parseUrl['path']) ? '' : $parseUrl['path'];
        $baseUrlPath = $domain . $path;
        $baseUrlPath = preg_replace("/\/([^\/]*)\.(.*)$/", "/", $baseUrlPath);
        $baseUrlPath = preg_replace("/\/$/", '', $baseUrlPath);

        $i = $pathStep = 0;
        $dStr = $pStr = '';
        $pos = strpos($url, '#');

        if ($pos > 0) {
            $url = substr($url, 0, $pos);
        }

        if(substr($url, 0, 2) == '//') { // 京东变态的都是 //www.jd.com/111.html
            $url = str_replace("//", "", $url);
        } elseif($url[0] == '/') { // /1234.html
            $url = $domain . $url;
        } elseif ($url[0] == '.') { // ./1234.html、../1234.html 这种类型的
            if(!isset($url[2])) {
                return false;
            } else {
                $urls = explode('/',$url);
                foreach($urls as $u) {
                    if( $u == '..' ) {
                        $pathStep ++;
                    } else if( $i < count($urls)-1 ) {
                        $dStr .= $urls[$i].'/';
                    } else {
                        $dStr .= $urls[$i];
                    }
                    $i ++;
                }
                $urls = explode('/', $baseUrlPath);
                if(count($urls) <= $pathStep) {
                    return false;
                } else {
                    $pStr = '';
                    for($i=0; $i < count($urls)-$pathStep; $i++) {
                        $pStr .= $urls[$i].'/';
                    }
                    $url = $pStr . $dStr;
                }
            }
        } else {
            if ( strtolower(substr($url, 0, 7)) == 'http://' ) {
                $url = preg_replace('#^http://#i','',$url);
                $scheme = "http";
            } else if( strtolower(substr($url, 0, 8))=='https://' ) {
                $url = preg_replace('#^https://#i', '', $url);
                $scheme = "https";
            } else {
                $url = $baseUrlPath . '/' . $url;
            }
        }

        // 两个 / 或以上的替换成一个 /
        $url = preg_replace('@/{1,}@i', '/', $url);
        $url = $scheme . '://' . $url;

        $parseUrl = @parse_url($url);
        $domain = empty($parseUrl['host']) ? $domain : $parseUrl['host'];
        // 如果host不为空, 判断是不是要爬取的域名
        if ($parseUrl['host']) {
            //排除非域名下的url以提高爬取速度
            if (!in_array($parseUrl['host'], $this->configs['domains'])) {
                return false;
            }
        }

        return $url;
    }

    private function queueRPop()
    {
        if ($this->useRedis) {
            $link = Redis::rpop("collect_queue");
            $link = json_decode($link, true);
        } else {
            $link = array_shift($this->collectQueue);
        }

        return $link;
    }

    private function incrCollectedUrlNum($url)
    {
        if ($this->useRedis) {
            Redis::incr('collected_urls_num');
        } else {
            $this->collectedUrlsNum ++;
        }
    }

    private function queryUrl($url, $link)
    {
        $timeStart = microtime(true);

        $queryObj = new Query();

        $queryObj->setOutputEncoding('utf-8');
        $queryObj->setTimeout($this->configs['timeout']);
        $queryObj->setUserAgent($this->configs['user_agent']);

        if ($this->configs['input_encoding']) {
            $queryObj->setInputEncoding($this->configs['input_encoding']);
        }
        if ($this->configs['user_agents']) {
            $queryObj->setUserAgents($this->configs['user_agents']);
        }
        if ($this->configs['client_ip']) {
            $queryObj->setClientIp($this->configs['client_ip']);
        }
        if ($this->configs['client_ips']) {
            $queryObj->setClientIpArr($this->configs['client_ips']);
        }
        if ($link['proxy']) {
            $queryObj->setProxies(['http' => $link['proxy'], 'https' => $link['proxy']]);
            // 自动切换IP
            $queryObj->setHeaders('Proxy-Switch-Ip', 'yes');
        }
        if ($link['headers']) {
            foreach ($link['headers'] as $key => $value) {
                $queryObj->setHeaders($key, $value);
            }
        }

        $method = empty($link['method']) ? 'get' : strtolower($link['method']);
        $params = empty($link['params']) ? array() : $link['params'];
        $html = $queryObj->$method($url, $params);

        // 此url附加的数据不为空, 比如内容页需要列表页一些数据, 拼接到后面去
        if ($html && $link['context_data']) {
            $html .= $link['context_data'];
        }

        $httpCode = $queryObj->getStatusCode();

        if ($httpCode != 200) {
            if ($httpCode == 301 || $httpCode == 302) {
                $info = $queryObj->getQueryInfo();

                if (isset($info['redirect_url'])) {
                    $url = $info['redirect_url'];
                    $queryObj->setInputEncoding(null);
                    $html = $this->queryUrl($url, $link);

                    if ($html && $link['context_data']) {
                        $html .= $link['context_data'];
                    }
                } else {
                    return false;
                }
            } else {
                if ($httpCode == 407) {
                    // 扔到队列头部去, 继续采集
                    $this->linkPush($link, false, 'R');
                    Log::error("Failed to download page {$url}");
                    $this->collectFail ++;

                } elseif (in_array($httpCode, ['0','502','503','429'])) {
                    // 采集次数加一
                    $link['try_num'] ++;
                    // 抓取次数 小于 允许抓取失败次数
                    if ($link['try_num'] <= $link['max_try']) {
                        // 扔到队列头部去, 继续采集
                        $this->linkPush($link, false, 'R');
                    }
                    Log::error("Failed to download page {$url}, retry({$link['try_num']})");

                } else {
                    Log::error("Failed to download page {$url}");
                    $this->collectFail ++;
                }
                Log::error("HTTP CODE: {$httpCode}");
                return false;
            }
        }

        // 爬取页面耗时时间
        $timeRun = round(microtime(true) - $timeStart, 3);
        Log::debug("Success download page {$url} in {$timeRun} s");
        $this->collectSucc ++;

        return $html;
    }
}