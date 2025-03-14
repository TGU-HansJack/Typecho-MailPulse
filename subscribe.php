<?php
require __DIR__ . '/../../config.inc.php'; // 加载Typecho配置
Typecho_Widget::widget('Widget_Init'); // 初始化Typecho环境

// 调用插件处理订阅
echo MailPulse_Plugin::handleSubscription();
