<?php
use \Workerman\Worker;
use \Workerman\Events\EventInterface;

/*********************************************
* 监控文件更新并自动 reload workerman   
*                安装inotify扩展
*********************************************/

// 监控的目录，默认是Applications
$monitor_dir = realpath(__DIR__.'/..');
// worker
$worker = new Worker();
// worker的名字，方便status时标识
$worker->name = 'FileMonitor';
// 改进程收到reload信号时，不执行reload
$worker->reloadable = false;
// 所有被监控的文件，key为inotify id
$monitor_files = array();

// 进程启动后创建inotify监控句柄
$worker->onWorkerStart = function($worker)
{
    if(!extension_loaded('inotify'))
    {
        echo "FileMonitor : Please install inotify extension.\n";
        return;
    }
    
    global $monitor_dir, $monitor_files;
    // 初始化inotify句柄
    $worker->inotifyFd = inotify_init();
    // 设置为非阻塞
    stream_set_blocking($worker->inotifyFd, 0);
    // 递归遍历目录里面的文件
    $dir_iterator = new RecursiveDirectoryIterator($monitor_dir);
    $iterator = new RecursiveIteratorIterator($dir_iterator);
    foreach ($iterator as $file)
    {
        // 只监控php文件
        if(pathinfo($file, PATHINFO_EXTENSION) != 'php')
        {
            continue;
        }
        // 把文件加入inotify监控，这里只监控了IN_MODIFY文件更新事件
        $wd = inotify_add_watch($worker->inotifyFd, $file, IN_MODIFY);
        $monitor_files[$wd] = $file;
    }
    // 监控inotify句柄可读事件
    Worker::$globalEvent->add($worker->inotifyFd, EventInterface::EV_READ, 'check_files_change');
};

// 检查哪些文件被更新，并执行reload
function check_files_change($inotify_fd)
{
    global $monitor_files;
    // 读取有哪些文件事件
    $events = inotify_read($inotify_fd);
    if($events)
    {
        // 检查哪些文件被更新了
        foreach($events as $ev)
        {
            // 更新的文件
            $file = $monitor_files[$ev['wd']];
            echo $file ." update and reload\n";
            unset($monitor_files[$ev['wd']]);
            // 需要把文件重新加入监控
            $wd = inotify_add_watch($inotify_fd, $file, IN_MODIFY);
            $monitor_files[$wd] = $file;
        }
        // 给父进程也就是主进程发送reload信号
        posix_kill(posix_getppid(), SIGUSR1);
    }
}