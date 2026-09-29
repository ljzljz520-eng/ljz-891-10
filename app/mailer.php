<?php
declare(strict_types=1);

interface MailerInterface
{
    /** @return string 失败时返回错误信息，成功返回空串 */
    public function send(string $to, string $subject, string $bodyHtml): string;
}

/**
 * 开发环境邮件驱动：把邮件"投递"到本地文件，
 * 方便无 SMTP 时查看验证码（data/mail/outbox-*.txt）。
 */
class FileMailer implements MailerInterface
{
    public function __construct(private string $spoolDir, private string $from) {}

    public function send(string $to, string $subject, string $bodyHtml): string
    {
        if (!is_dir($this->spoolDir) && !@mkdir($this->spoolDir, 0775, true) && !is_dir($this->spoolDir)) {
            return '邮件目录不可写';
        }
        $file = $this->spoolDir . '/outbox-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt';
        $content = "FROM: {$this->from}\nTO: {$to}\nSUBJECT: {$subject}\nDATE: " . date('c') . "\n\n" . strip_tags($bodyHtml) . "\n";
        if (file_put_contents($file, $content) === false) {
            return '邮件写入失败';
        }
        return '';
    }
}

/**
 * 轻量 SMTP 客户端（ssl / tls 均支持，无第三方依赖）。
 */
class SmtpMailer implements MailerInterface
{
    public function __construct(
        private string $host,
        private int $port,
        private string $security, // ssl | tls | none
        private string $username,
        private string $password,
        private string $from,
        private string $fromName,
        private bool $verifyPeer = false,
        private int $timeout = 10,
    ) {}

    public function send(string $to, string $subject, string $bodyHtml): string
    {
        $remote = ($this->security === 'ssl' ? 'ssl://' : '') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => [
                'verify_peer'      => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed'=> !$this->verifyPeer,
            ]])
        );
        if (!$fp) {
            return "SMTP 连接失败: {$errstr}";
        }
        stream_set_timeout($fp, $this->timeout);

        $err = $this->read($fp);
        if ($err !== null) {
            fclose($fp);
            return $err;
        }

        $host = gethostname() ?: 'localhost';
        if ($err = $this->cmd($fp, 'EHLO ' . $host)) { fclose($fp); return $err; }

        if ($this->security === 'tls') {
            if ($err = $this->cmd($fp, 'STARTTLS', [220])) { fclose($fp); return $err; }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, array_filter([
                'crypto_method'    => $crypto,
                'STREAM_CRYPTO_METHOD_TLS_CLIENT' => $crypto,
                'verify_peer'      => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed'=> !$this->verifyPeer,
            ]))) {
                fclose($fp);
                return 'TLS 握手失败';
            }
            if ($err = $this->cmd($fp, 'EHLO ' . $host)) { fclose($fp); return $err; }
        }

        if ($this->username !== '') {
            if ($err = $this->cmd($fp, 'AUTH LOGIN', [334])) { fclose($fp); return $err; }
            if ($err = $this->cmd($fp, base64_encode($this->username), [334])) { fclose($fp); return $err; }
            if ($err = $this->cmd($fp, base64_encode($this->password), [235])) { fclose($fp); return $err; }
        }

        if ($err = $this->cmd($fp, 'MAIL FROM:<' . $this->from . '>')) { fclose($fp); return $err; }
        if ($err = $this->cmd($fp, 'RCPT TO:<' . $to . '>')) { fclose($fp); return $err; }
        if ($err = $this->cmd($fp, 'DATA', [354])) { fclose($fp); return $err; }

        $headers = [
            'From: =?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $bodyHtml . "\r\n.";
        fwrite($fp, $message . "\r\n");
        if ($err = $this->read($fp, [250])) { fclose($fp); return $err; }

        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return '';
    }

    /** 发送命令并校验响应码 */
    private function cmd($fp, string $cmd, array $expect = [250]): ?string
    {
        fwrite($fp, $cmd . "\r\n");
        return $this->read($fp, $expect);
    }

    /**
     * 读取 SMTP 响应，支持多行（如 250-xxx / 250 xxx）。
     * 返回 null 表示符合期望码，否则返回错误描述。
     */
    private function read($fp, array $expect = [220, 250, 235, 334, 354]): ?string
    {
        $line = '';
        while (!feof($fp)) {
            $chunk = fgets($fp, 515);
            if ($chunk === false) {
                break;
            }
            $line .= $chunk;
            // 多行响应以 "-" 跟在码后，最后一行以空格跟在码后
            if (preg_match('/^\d{3} /', trim($line, "\r\n"))) {
                break;
            }
            if (preg_match('/^\d{3}-/', trim($line, "\r\n"))) {
                $line = '';
            }
        }
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $expect, true)) {
            return 'SMTP: ' . trim($line);
        }
        return null;
    }
}

function make_mailer(): MailerInterface
{
    $m = $GLOBALS['CFG']['mail'];
    if ($m['driver'] === 'smtp') {
        return new SmtpMailer(
            $m['host'], $m['port'], $m['security'],
            $m['username'], $m['password'],
            $m['from'], $m['from_name'], $m['verify_peer'],
        );
    }
    return new FileMailer($m['spool_dir'], $m['from']);
}
