<?php

class WebSocket
{
    public $master;
    public $sockets = [];

    public function __construct($address = '0.0.0.0', $port = '8080')
    {
        try {
            $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
            socket_bind($this->master, $address, $port);
            socket_listen($this->master, 10);
        } catch (Exception $e) {
            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);
            var_dump($err_msg);
            exit(0);
        }
        $this->sockets[0] = ['resource' => $this->master];
    }

    public function handleSocket()
    {
        $write = null;
        $except = null;
        $sockets = array_column($this->sockets, 'resource');
        $read_num = socket_select($sockets, $write, $except, null);

        if (false === $read_num) {
            $err_code = socket_last_error();
            error_log(socket_strerror($err_code), 3, "./a.log");
            return;
        }

        foreach ($sockets as $socket) {
            if ($socket == $this->master) {
                $client = socket_accept($this->master);
                if (false === $client) {
                    continue;
                } else {
                    $this->connect($client);
                    continue;
                }
            } else {
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                if ($bytes == 0) {
                    $recv_msg = $this->disconnect($socket);
                } else {
                    if (!isset($this->sockets[(int) $socket]['handshake'])) {
                        $this->dohandshake($socket, $buffer);
                        continue;
                    } else {
                        $recv_msg = $this->parseFrame($buffer);
                    }
                }
                $msg = $this->buildFrame(json_encode($recv_msg));
                $this->send($msg);
            }
        }
    }

    public function send($msg)
    {
        foreach ($this->sockets as $socket) {
            if ($socket['resource'] == $this->master) {
                continue;
            }
            socket_write($socket['resource'], $msg, strlen($msg));
        }
    }

    public function connect($socket)
    {
        $socket_info = [
            'resource' => $socket,
        ];
        $this->sockets[(int) $socket] = $socket_info;
    }

    private function disconnect($socket)
    {

        $recv_msg = "client#" . (int) $socket . " disconnect";
        unset($this->sockets[(int) $socket]);
        return $recv_msg;
    }

    public function buildFrame($msg)
    {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }

        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;

        $data = implode('', $frame);

        return pack("H*", $data);
    }

    private function parseFrame($buffer)
    {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return $decoded;
    }

    public function dohandshake($socket, $req)
    {
        preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $req, $match);
        $key = $match[1];
        $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgrade .= "Upgrade: websocket\r\n";
        $upgrade .= "Connection: Upgrade\r\n";
        $upgrade .= "Sec-WebSocket-Version: 13\r\n";
        $upgrade .= "Sec-WebSocket-Accept: " . $acceptKey . "\r\n\r\n";

        socket_write($socket, $upgrade, strlen($upgrade));
        $this->sockets[(int) $socket]['handshake'] = true;

        $msg = 'handshake success';
        $msg = $this->frame(json_encode($msg));
        socket_write($socket, $msg, strlen($msg));

        return true;
    }

    public function run()
    {
        while (true) {
            $this->handleSocket();
        }
    }
}

(new WebSocket('127.0.0.1', '4567'))->run();
