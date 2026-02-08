<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

class PanelWsServer extends Command
{
    protected $signature = 'xboard:panel-ws {--host= : Bind host} {--port= : Bind port}';

    protected $description = 'Run panel WebSocket transport server for node communication';

    public function handle(): int
    {
        if (!extension_loaded('swoole')) {
            $this->error('The swoole extension is required.');
            return self::FAILURE;
        }

        $host = (string) ($this->option('host') ?: env('PANEL_WS_HOST', '0.0.0.0'));
        $port = (int) ($this->option('port') ?: env('PANEL_WS_PORT', 51821));

        $server = new \Swoole\WebSocket\Server($host, $port);

        /** @var array<int, array<string, mixed>> $connQuery */
        $connQuery = [];

        $server->set([
            'http_compression' => true,
            'open_http_protocol' => true,
            'open_websocket_protocol' => true,
            'websocket_compression' => true,
        ]);

        $server->on('open', function (\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request) use (&$connQuery): void {
            $query = is_array($request->get ?? null) ? $request->get : [];
            $connQuery[(int) $request->fd] = $query;

            $token = $query['token'] ?? null;
            $nodeId = $query['node_id'] ?? null;

            if (!is_string($token) || $token === '' || $nodeId === null || $nodeId === '') {
                $server->disconnect($request->fd, 1008, 'Unauthorized');
            }
        });

        $server->on('message', function (\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame) use (&$connQuery): void {
            $wsRequest = json_decode($frame->data, true);
            if (!is_array($wsRequest)) {
                $this->pushWsResponse($server, $frame->fd, 400, [], 'Invalid JSON');
                return;
            }

            $method = strtoupper((string) ($wsRequest['method'] ?? ''));
            if (!in_array($method, ['GET', 'POST'], true)) {
                $this->pushWsResponse($server, $frame->fd, 405, [], 'Method Not Allowed');
                return;
            }

            $path = (string) ($wsRequest['path'] ?? '');
            if ($path === '' || $path[0] !== '/') {
                $this->pushWsResponse($server, $frame->fd, 400, [], 'Invalid path');
                return;
            }

            $headers = $wsRequest['headers'] ?? [];
            if (!is_array($headers)) {
                $headers = [];
            }

            $bodyRaw = $wsRequest['body'] ?? '';
            $body = '';
            if (is_string($bodyRaw) && $bodyRaw !== '') {
                $decoded = base64_decode($bodyRaw, true);
                if ($decoded === false) {
                    $this->pushWsResponse($server, $frame->fd, 400, [], 'Invalid body encoding');
                    return;
                }
                $body = $decoded;
            }

            $nodeQuery = $connQuery[(int) $frame->fd] ?? [];
            $queryString = http_build_query(is_array($nodeQuery) ? $nodeQuery : []);
            $targetUri = $queryString !== '' ? ($path . '?' . $queryString) : $path;

            $laravelRequest = Request::create($targetUri, $method, [], [], [], [], $body);

            foreach ($headers as $key => $values) {
                if (!is_string($key)) {
                    continue;
                }
                if (is_array($values)) {
                    $laravelRequest->headers->set($key, implode(',', array_map('strval', $values)));
                } elseif (is_string($values)) {
                    $laravelRequest->headers->set($key, $values);
                }
            }

            try {
                $kernel = app()->make(HttpKernel::class);
                $response = $kernel->handle($laravelRequest);
                $kernel->terminate($laravelRequest, $response);

                $status = $response->getStatusCode();
                $respHeaders = $response->headers->all();
                $respBody = $response->getContent();

                $this->pushWsResponse($server, $frame->fd, $status, $respHeaders, $respBody);
            } catch (\Throwable $e) {
                $this->pushWsResponse($server, $frame->fd, 500, [], $e->getMessage());
            }
        });

        $server->on('close', function (\Swoole\WebSocket\Server $server, int $fd) use (&$connQuery): void {
            unset($connQuery[$fd]);
        });

        $this->info("Panel WS server listening on ws://{$host}:{$port}");
        $server->start();

        return self::SUCCESS;
    }

    private function pushWsResponse(\Swoole\WebSocket\Server $server, int $fd, int $status, array $headers, string $body): void
    {
        $payload = [
            'status' => $status,
            'headers' => $this->normalizeHeaders($headers),
            'body' => base64_encode($body),
        ];

        $server->push($fd, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $values) {
            if (!is_string($key)) {
                continue;
            }
            if (is_array($values)) {
                $normalized[$key] = array_values(array_map('strval', $values));
            } elseif (is_string($values)) {
                $normalized[$key] = [$values];
            }
        }
        return $normalized;
    }
}
