<?php

namespace App\Service\Ssh;

class SSHService
{
    /** @var resource */
    private $session;
    /** @var false|resource */
    private $sftp = false;

    /**
     * @param string $host
     * @param int $port
     * @param array|null $methods
     * @param array|null $callbacks
     * @throws SSHException
     */
    public function __construct(string $host, int $port = 22, ?array $methods = null, ?array $callbacks = null)
    {
        $this->sshConnect($host, $port, $methods, $callbacks);
    }

    /**
     * Закрывает соединение с удалённым сервером SSH
     * @throws SSHException
     */
    public function __destruct()
    {
        $this->sshDisconnect();
    }

    /**
     * Проверка инициализирована ли подсистема SFTP
     * @param false|resource $sftp Ресурс SSH2 SFTP.
     * @return resource
     * @throws SSHException
     */
    private function getSftp($sftp = null)
    {
        $sftp = $sftp ?? $this->sftp;
        if (!is_resource($sftp)) {
            throw new SSHException('Failed to initialize the SFTP subsystem');
        }

        return $sftp;
    }

    /**
     * @return void
     * @throws SSHException
     */
    public function sshDisconnect(): void
    {
        $session = $this->session;
        if (is_resource($session)) {
            if (ssh2_disconnect($session) === false) {
                throw new SSHException('Failed disconnection from the SSH server');
            }
        }
    }

    /**
     * Подключение к SSH-серверу
     * @param string $host Сервер.
     * @param int $port Порт сервера.
     * @param array|null $methods
     * @param array|null $callbacks
     * @return void
     * @throws SSHException
     */
    private function sshConnect(string $host, int $port = 22, ?array $methods = null, ?array $callbacks = null): void
    {
        $connect = ssh2_connect($host, $port, $methods, $callbacks);
        if ($connect === false) {
            throw new SSHException('Failed connection to SSH server');
        }
        $this->session = $connect;
    }

    /**
     * Аутентификация через SSH с использованием обычного пароля
     * @param string $sshUsername Имя пользователя на удалённом сервере.
     * @param string $sshPassword Пароль для пользователя.
     * @return void
     * @throws SSHException
     */
    public function authPassword(string $sshUsername, string $sshPassword): void
    {
        if (ssh2_auth_password($this->session, $sshUsername, $sshPassword) === false) {
            throw new SSHException('Failed authentication');
        }
    }

    /**
     * Выполнение команды на удалённом сервере
     * @param string $command Идентификатор соединения SSH, полученный из ssh2_connect().
     * @param string $pty
     * @param array $env Может передаваться как ассоциативный массив пар имя/значение, представляющие переменные окружения, которые нужно установить перед запуском команды.
     * @param int $width Ширина виртуального терминала.
     * @param int $height Высота виртуального терминала.
     * @param int $widthHeightType Должен быть SSH2_TERM_UNIT_CHARS или SSH2_TERM_UNIT_PIXELS.
     * @param bool $needResponse
     * @return string|void
     * @throws SSHException
     */
    public function sshExec(string $command, string $pty = "", array $env = [], int $width = 80, int $height = 25, int $widthHeightType = SSH2_TERM_UNIT_CHARS, bool $needResponse = true)
    {
        $command .= ';echo "[return_code:$?]"';
        $stream = ssh2_exec($this->session, $command, $pty, $env, $width, $height, $widthHeightType);
        if ($stream === false) {
            throw new SSHException('Failed to execute command on remote server');
        }

        if ($needResponse) {
            return $this->getResponseExecuteCommand($stream);
        } else {
            return;
        }
    }

    /**
     * @param resource $stream
     * @return string
     * @throws SSHException
     */
    private function getResponseExecuteCommand($stream): string
    {
        stream_set_blocking($stream, true);

        $streamOut = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
        $streamOutError = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);

        $streamOutMessage = stream_get_contents($streamOut);
        $streamOutErrorMessage = stream_get_contents($streamOutError);

        fclose($streamOut);
        fclose($streamOutError);

        if (!empty($streamOutErrorMessage)) {
            throw new SSHException($streamOutErrorMessage);
        }

        preg_match('/\[return_code:(.*?)\]/', $streamOutMessage, $match);
        if (is_array($match) && count($match) === 2) {
            $returnCode = $match[1];
            $streamOutMessage = str_replace("[return_code:$returnCode]\r\n", '', $streamOutMessage);
            if ($returnCode > 0) {
                throw new SSHException($streamOutMessage);
            }
        }

        return $streamOutMessage;
    }

    /**
     * Отправка файла через SCP
     * @param string $localFile Путь к локальному файлу.
     * @param string $remoteFile Путь на сервере, куда будет сохранён файл.
     * @param int $createMode Файл будет создан с правами доступа, указанным в createMode.
     * @return void
     * @throws SSHException
     */
    public function sshScpSend(string $localFile, string $remoteFile, int $createMode = 0644): void
    {
        if (ssh2_scp_send($this->session, $localFile, $remoteFile, $createMode) === false) {
            throw new SSHException('Failed to send file to remote server');
        }
    }

    /**
     * Копирование файла с сервера на клиент, используя протокол SCP.
     * @param string $remote_file Путь к файлу на сервере.
     * @param string $local_file Локальный путь для сохранения.
     * @return void
     * @throws SSHException
     */
    public function sshScpRecv(string $remote_file, string $local_file): void
    {
        if (ssh2_scp_recv($this->session, $remote_file, $local_file) === false) {
            throw new SSHException('Failed to retrieve file on remote server');
        }
    }

    /**
     * Инициализированние подсистемы SFTP
     * @return void
     */
    public function sshSftp()
    {
        $sftp = ssh2_sftp($this->session);
        $this->sftp = $sftp;
    }

    /**
     * Изменение прав доступа
     * @param string $filename Путь к файлу на сервере.
     * @param int $mode Права доступа к файлу. Для более детальной информации смотрите описание функции chmod().
     * @return void
     * @throws SSHException
     */
    public function sftpChmod(string $filename, int $mode): void
    {
        if (ssh2_sftp_chmod($this->getSftp(), $filename, $mode) === false) {
            throw new SSHException('Access rights could not be changed');
        }
    }

    /**
     * Создать директорию
     * @param string $dirname Путь к новой директории.
     * @param int $mode Маска прав доступа. Фактический режим зависит от текущей umask.
     * @param bool $recursive Если recursive задан как true, создаются все родительские директории dirname, если их нет.
     * @return void
     * @throws SSHException
     */
    public function sshSftpMkdir(string $dirname, int $mode = 0777, bool $recursive = false): void
    {
        if (ssh2_sftp_mkdir($this->getSftp(), $dirname, $mode, $recursive) === false) {
            throw new SSHException('Failed to create directory');
        }
    }

    /**
     * Удалить директорию
     * @param string $dirname Путь к директории на сервере.
     * @return void
     * @throws SSHException
     */
    public function sshSftpRmdir(string $dirname): void
    {
        if (ssh2_sftp_rmdir($this->getSftp(), $dirname) === false) {
            throw new SSHException('Failed to remove directory');
        }
    }

    /**
     * Удалить файл на сервере
     * @param string $filename Путь к файлу на сервере.
     * @return void
     * @throws SSHException
     */
    public function sshSftpUnlink(string $filename): void
    {
        if (ssh2_sftp_unlink($this->getSftp(), $filename) === false) {
            throw new SSHException('Failed to remove file');
        }
    }
}
