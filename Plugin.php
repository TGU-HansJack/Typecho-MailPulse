<?php
/**
 * MailPulse - 邮件推送订阅插件
 * @package MailPulse
 * @version 1.1.0
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
    /*-------------------- 必须实现的接口方法 --------------------*/
    public static function activate()
    {
        // 创建数据库表
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        
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

        // 挂载文章发布钩子
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'triggerMail');
        
        // 添加退订路由
        Helper::addRoute('mailpulse_unsub', '/mailpulse/unsubscribe', 'MailPulse_Action', 'unsubscribe');
        
        return _t('插件已激活 - 请配置SMTP参数');
    }

    public static function deactivate()
    {
        Helper::removeRoute('mailpulse_unsub');
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
            _t('完整邮箱地址')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Password(
            'smtp_pass',
            null,
            '',
            _t('SMTP密码/授权码')
        ));

        // 推送配置
        $pushGroup = new Typecho_Widget_Helper_Layout('div', array('class' => 'typecho-page-title'));
        $pushGroup->html('<h2>推送配置</h2>');
        $form->addItem($pushGroup);

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'push_interval',
            null,
            '0',
            _t('推送延迟（分钟）'),
            _t('0=立即发送，>0需配置服务器定时任务')
        ));

        // 邮件模板
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'mail_template',
            null,
            file_get_contents(__DIR__ . '/templates/default.html'),
            _t('邮件模板'),
            _t('可用变量：{{title}}, {{url}}, {{excerpt}}, {{author}}, {{unsubscribe}}, {{recommend}}')
        ));

        // 推荐文章
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'recommend_cids',
            null,
            '',
            _t('推荐文章CID'),
            _t('逗号分隔的文章ID，如：12,34,56')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_recommend',
            array('1' => '启用', '0' => '禁用'),
            '0',
            _t('是否推送推荐文章')
        ));
    }

    // 修复报错的核心：实现 personalConfig 方法
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /*-------------------- 核心业务逻辑 --------------------*/
    public static function triggerMail($post)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('MailPulse');
        $subscribers = self::getSubscribers();

        // 生成推荐文章内容
        $recommendContent = ($options->enable_recommend == '1') 
            ? self::generateRecommend($options->recommend_cids) 
            : '';

        foreach ($subscribers as $sub) {
            $unsubLink = Helper::options()->siteUrl . 'mailpulse/unsubscribe?email=' 
                . urlencode($sub['email']) . '&token=' . $sub['token'];

            $mailBody = str_replace(
                ['{{title}}', '{{url}}', '{{excerpt}}', '{{author}}', '{{unsubscribe}}', '{{recommend}}'],
                [
                    $post->title,
                    $post->permalink,
                    self::makeExcerpt($post->text),
                    $post->author->screenName,
                    '<a href="' . $unsubLink . '">点击退订</a>',
                    $recommendContent
                ],
                $options->mail_template
            );

            if ($options->push_interval > 0) {
                self::addToQueue($sub['email'], "新文章通知: {$post->title}", $mailBody);
            } else {
                self::sendImmediately($sub['email'], "新文章: {$post->title}", $mailBody);
            }
        }
    }

    private static function sendImmediately($to, $subject, $content)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('MailPulse');
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $options->smtp_host;
            $mailer->Port = $options->smtp_port;
            $mailer->SMTPAuth = true;
            $mailer->Username = $options->smtp_user;
            $mailer->Password = $options->smtp_pass;
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;

            $mailer->setFrom($options->smtp_user, Helper::options()->title);
            $mailer->addAddress($to);
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $content;

            $mailer->send();
            self::log("发送成功: $to");
        } catch (Exception $e) {
            self::log("发送失败: {$e->getMessage()}", 'ERROR');
        }
    }

    private static function generateRecommend($cids)
    {
        if (empty($cids)) return '';
        
        $db = Typecho_Db::get();
        $recommendPosts = $db->fetchAll($db->select()->from('table.contents')
            ->where('cid IN ?', explode(',', $cids))
            ->where('status = ?', 'publish'));
        
        $html = '<ul>';
        foreach ($recommendPosts as $post) {
            $html .= sprintf('<li><a href="%s">%s</a></li>',
                Typecho_Common::url($post['slug'], Helper::options()->index),
                $post['title']
            );
        }
        return $html . '</ul>';
    }

    /**
 * 将邮件任务添加到队列
 * @param string $email 收件人邮箱
 * @param string $subject 邮件标题
 * @param string $content 邮件内容
 */
private static function addToQueue($email, $subject, $content) 
{
    $queueFile = __DIR__ . '/queue.json'; // 队列文件路径
    $queue = [];

    // 读取现有队列
    if (file_exists($queueFile)) {
        $queue = json_decode(file_get_contents($queueFile), true);
        if (!is_array($queue)) {
            $queue = []; // 防止文件损坏
        }
    }

    // 计算发送时间
    $options = Typecho_Widget::widget('Widget_Options')->plugin('MailPulse');
    $sendTime = time() + ($options->push_interval * 60);

    // 添加新任务
    $queue[] = [
        'send_time' => $sendTime,
        'email' => $email,
        'subject' => $subject,
        'content' => $content
    ];

    // 保存队列
    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
}

/**
 * 获取所有状态为启用的订阅者
 * @return array 订阅者列表（含邮箱和令牌）
 */
private static function getSubscribers()
{
    $db = Typecho_Db::get();
    return $db->fetchAll(
        $db->select('email', 'token')
           ->from('table.mailpulse_subscribers')
           ->where('status = 1') // 仅获取启用状态的订阅者
    );
}

/**
 * 生成文章摘要
 * @param string $content 原始内容
 * @param int $length 摘要长度（默认100字符）
 * @return string 处理后的摘要
 */
private static function makeExcerpt($content, $length = 100)
{
    $text = trim(strip_tags($content)); // 去除HTML标签
    return Typecho_Common::subStr($text, 0, $length, '...'); // 截断并添加省略号
}

/**
 * 记录插件运行日志
 * @param string $message 日志内容
 * @param string $level 日志级别（INFO/WARNING/ERROR）
 */
private static function log($message, $level = 'INFO')
{
    $logFile = __DIR__ . '/mailpulse.log';
    $logEntry = sprintf(
        "[%s] %s - %s\n",
        date('Y-m-d H:i:s'),
        str_pad($level, 7), // 对齐日志级别
        $message
    );
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
    
    
}

class MailPulse_Action extends Typecho_Widget
{
    public function unsubscribe()
    {
        $db = Typecho_Db::get();
        $email = $this->request->get('email');
        $token = $this->request->get('token');

        $valid = $db->fetchRow($db->select('token')->from('table.mailpulse_subscribers')
            ->where('email = ?', $email));

        if ($valid && $valid['token'] === $token) {
            $db->query($db->update('table.mailpulse_subscribers')
                ->rows(['status' => 0])
                ->where('email = ?', $email));
            echo '退订成功';
        } else {
            echo '无效的退订请求';
        }
    }
}