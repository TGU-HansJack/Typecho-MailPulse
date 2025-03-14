/**
 * MailPulse 后台增强脚本
 * 功能：CID输入验证、模板预览
 */
jQuery(document).ready(function($) {
    // 推荐文章CID格式验证
    $('#recommend_cids').on('blur', function() {
        const value = $(this).val().trim();
        const cidPattern = /^\d+(,\d+)*$/;

        if (value && !cidPattern.test(value)) {
            alert('CID格式错误：请输入数字或逗号分隔的数字');
            $(this).focus(); // 重新聚焦到输入框
        }
    });

    // 邮件模板预览按钮
    $('<button type="button" class="btn">预览模板</button>')
        .insertAfter('#mail_template') // 插入按钮到模板输入框后
        .on('click', function() {
            const content = $('#mail_template').val();
            const win = window.open('', '模板预览');

            // 处理新窗口的打开和样式
            win.document.write(`
                <html>
                    <head>
                        <title>模板预览</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 10px; }
                            h1 { font-size: 24px; }
                            p { font-size: 16px; }
                        </style>
                    </head>
                    <body>
                        <h1>邮件模板预览</h1>
                        <div>${content}</div>
                    </body>
                </html>
            `);
            win.document.close(); // 关闭文档域
        });
});
