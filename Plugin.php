<?php
/**
 * MailPulse - 邮件推送订阅插件
 * @package MailPulse
 * @version 1.3.0
 * @author HansJack
 * @link https://www.hansjack.com
 */

require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailPulse_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        // 创建邮件订阅者表
        $db->query("
            CREATE TABLE IF NOT EXISTS {$prefix}mailpulse_subscribers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                token CHAR(32) NOT NULL,
                status TINYINT DEFAULT 1,
                created INT DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        ");

        // 包含设置的邮箱地址的表
        $db->query("
            CREATE TABLE IF NOT EXISTS {$prefix}mailpulse_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        ");

        // 挂载文章发布钩子
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'triggerMail');

        return _t('插件已激活 - 请配置SMTP参数');
    }

    public static function deactivate()
    {
        return _t('插件已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // SMTP配置
        $smtpGroup = new Typecho_Widget_Helper_Layout('div', array('class' => 'typecho-page-title'));
        $smtpGroup->html('<h2>SMTP 配置</h2>');
        $form->addItem($smtpGroup);

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'smtp_host',
            null,
            'smtp.example.com',
            _t('SMTP服务器地址'),
            _t('例如：smtp.qq.com')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'smtp_port',
            null,
            '465',
            _t('SMTP端口'),
            _t('SSL加密端口：465，TLS端口：587')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'smtp_user',
            null,
            '',
            _t('SMTP用户名'),
            _t('完整邮箱地址，例如：example@qq.com')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Password(
            'smtp_pass',
            null,
            '',
            _t('SMTP密码/授权码')
        ));

        // 邮件模板
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'mail_template',
            null,
            file_get_contents(__DIR__ . '/templates/default.html'),
            _t('邮件模板'),
            _t('可用变量：{{title}}, {{url}}, {{excerpt}}, {{author}}')
        ));

        // 接收通知的邮箱设置
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'notify_email',
            null,
            '',
            _t('接收通知的邮箱'),
            _t('将这些邮箱用于接收新文章发布通知，多个邮箱用逗号分隔')
        ));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

 public static function triggerMail($post)
{
    // 输出 $post 的类型和内容用于调试
    self::log("接收到的 \$post 类型: " . gettype($post));
    self::log("接收到的 \$post 内容: " . print_r($post, true)); // 打印整个 $post 数组

    // 确保 $post 是数组并从中提取信息
    if (is_array($post)) {
        $postData = (object) $post; // 转换为对象以方便访问属性
    } else {
        self::log("触发邮件失败: \$post 不是有效的数组");
        return;
    }

    // 获取文章标题
    $title = htmlspecialchars($postData->title); // 转义标题
    // 构建文章链接
    $permalinkSuffix = !empty($postData->slug) ? $postData->slug : '';
    $siteUrl = Typecho_Widget::widget('Widget_Options')->siteUrl; // 获取站点 URL
    $postUrl = rtrim($siteUrl, '/') . '/' . $permalinkSuffix; // 构建文章链接

    // 检查文章的 permalink 是否可用
    if (empty($postUrl)) {
        self::log("错误: 文章链接为空");
        return;
    }

    // 获取摘录
    $excerpt = self::makeExcerpt($postData->text ?? ''); // 使用 strip_tags 防止 XSS
    if (empty($excerpt)) {
        $excerpt = "没有可用的摘录"; // 如果摘录为空，给出默认内容
    }

    // 定义邮件主题
    $subject = "新文章: " . $title; // 使用获取的标题

    // 获取作者信息
    $author = !empty($postData->author) ? $postData->author->screenName : '未知作者'; // 获取作者

    // 打印调试信息
    $options = Typecho_Widget::widget('Widget_Options')->plugin('MailPulse');
    $notifyEmailList = explode(',', $options->notify_email);
    $notifyEmailList = array_map('trim', $notifyEmailList); // 去除多余的空格

    self::log("准备发送邮件给: " . implode(', ', $notifyEmailList));
    self::log("邮件主题: " . $subject);
    self::log("文章链接: " . $postUrl);
    self::log("文章摘录: " . $excerpt);
    self::log("文章作者: " . $author);

    foreach ($notifyEmailList as $notifyEmail) {
        $mailBody = str_replace(
            ['{{title}}', '{{url}}', '{{excerpt}}', '{{author}}'],
            [
                $title, // 使用获取的标题
                $postUrl, // 保持原始链接，以确保完整性
                $excerpt, // 保持原始摘录内容
                htmlspecialchars($author), // 转义作者
            ],
            $options->mail_template
        );

        // 直接发送邮件
        self::sendImmediately($notifyEmail, $subject, $mailBody);
    }
}


    private static function makeExcerpt($content, $length = 100)
    {
        $text = trim(strip_tags($content));
        return mb_substr($text, 0, $length) . (mb_strlen($text) > $length ? '...' : '');
    }

    private static function log($message, $level = 'INFO') {
        $logFile = __DIR__ . '/mailpulse.log'; // 日志文件路径
        $logEntry = sprintf(
            "[%s] %s - %s\n",
            date('Y-m-d H:i:s'),
            str_pad($level, 7),
            $message
        );
        file_put_contents($logFile, $logEntry, FILE_APPEND); // 追加写入日志文件
    }

    private static function sendImmediately($to, $subject, $body)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('MailPulse');

        // 创建 PHPMailer 实例
        $mail = new PHPMailer();
        try {
            // SMTP 配置
            $mail->isSMTP();
            $mail->Host = $options->smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $options->smtp_user;
            $mail->Password = $options->smtp_pass;
            $mail->SMTPSecure = 'ssl'; // 使用 SSL 加密
            $mail->Port = $options->smtp_port;

            // 发件人和收件人设置
            $mail->setFrom($options->smtp_user);
            $mail->addAddress($to);

            // 邮件内容
            $mail->isHTML(true); // 支持 HTML 格式
            $mail->Subject = $subject;
            $mail->Body = $body;

            if (!$mail->send()) {
                self::log("邮件发送失败: " . $mail->ErrorInfo);
            } else {
                self::log("邮件发送成功: {$subject} 到 {$to}");
            }
        } catch (Exception $e) {
            self::log("邮件发送异常: " . $e->getMessage());
        }
    }
}
?>
