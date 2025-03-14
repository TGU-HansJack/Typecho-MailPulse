<?php
/**
 * 定时任务脚本 - 需通过服务器Cron定期执行
 */
require __DIR__ . '/../../config.inc.php'; // 加载Typecho配置
Typecho_Widget::widget('Widget_Init'); // 初始化Typecho环境

$queueFile = __DIR__ . '/queue.json';
if (!file_exists($queueFile)) exit;

$queue = json_decode(file_get_contents($queueFile), true);
$now = time();
$processed = [];

foreach ($queue as $key => $job) {
    if ($job['send_time'] <= $now) {
        // 调用发送方法
        MailPulse_Plugin::sendImmediately($job['email'], $job['subject'], $job['content']);
        $processed[] = $key; // 标记已处理任务
    }
}

// 移除已处理任务
foreach ($processed as $key) {
    unset($queue[$key]);
}

// 保存更新后的队列
file_put_contents($queueFile, json_encode(array_values($queue), JSON_PRETTY_PRINT));