<?php

declare(strict_types=1);

namespace App\Tests\Simulator\Http;

use App\Simulator\State\PersistedStateReader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Serves the simulator through `php -S`, so every call runs in its own PHP process.
 */
final class SimulatorHttpServer
{
    private const float READINESS_TIMEOUT_SECONDS = 30.0;
    private const int READINESS_POLL_INTERVAL_MICROSECONDS = 10_000;
    private const int REQUEST_TIMEOUT_SECONDS = 10;

    private function __construct(
        private readonly Process $process,
        private readonly string $baseUrl,
        private readonly string $temporaryDirectory,
        private readonly string $stateFilePath,
    ) {
    }

    public static function start(string $projectDirectory): self
    {
        $temporaryDirectory = sys_get_temp_dir().'/pixelcast-simulator-http-'.bin2hex(random_bytes(6));
        $stateFilePath = $temporaryDirectory.'/state.json';
        $port = self::reserveFreePort();

        $process = new Process(
            [
                \PHP_BINARY,
                // php -S hides inherited environment variables from $_SERVER and $_ENV,
                // where Dotenv looks for APP_ENV before falling back to .env.
                '-d', 'variables_order=EGPCS',
                '-S', '127.0.0.1:'.$port,
                '-t', 'public/',
            ],
            $projectDirectory,
            [
                'APP_ENV' => 'test',
                // Without debug, the child keeps serving a container compiled before the last source change.
                'APP_DEBUG' => '1',
                // Debug mode logs every dispatched event to stderr; -1 keeps only errors.
                'SHELL_VERBOSITY' => '-1',
                'PIXELCAST_SIMULATOR_STATE_FILE' => $stateFilePath,
            ],
        );
        $process->setTimeout(null);
        $process->start();

        $server = new self($process, 'http://127.0.0.1:'.$port, $temporaryDirectory, $stateFilePath);

        try {
            $server->awaitReady();
        } catch (\RuntimeException $startFailure) {
            $server->stop();

            throw $startFailure;
        }

        return $server;
    }

    public function get(string $path): SimulatorHttpResponse
    {
        return $this->send('GET', $path, null);
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     */
    public function post(string $path, ?array $jsonBody = null): SimulatorHttpResponse
    {
        return $this->send('POST', $path, null === $jsonBody ? null : json_encode($jsonBody, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedStateFile(): array
    {
        if (!is_file($this->stateFilePath)) {
            throw new \RuntimeException('The simulator wrote no state file at '.$this->stateFilePath);
        }

        $contents = new Filesystem()->readFile($this->stateFilePath);
        $persistedState = PersistedStateReader::payload(json_decode($contents, true));

        if (null === $persistedState) {
            throw new \RuntimeException('The simulator state file holds no JSON object: '.$contents);
        }

        return $persistedState;
    }

    public function serverOutput(): string
    {
        return $this->process->getOutput().$this->process->getErrorOutput();
    }

    public function stop(): void
    {
        $this->process->stop(2.0);

        new Filesystem()->remove($this->temporaryDirectory);
    }

    private static function reserveFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        if (false === $socket) {
            throw new \RuntimeException(\sprintf('No free TCP port could be reserved: %s (%d)', $errorMessage, $errorNumber));
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (false === $address || 1 !== preg_match('/:(\d+)$/', $address, $matches)) {
            throw new \RuntimeException('The reserved socket exposed no port number.');
        }

        return (int) $matches[1];
    }

    private function awaitReady(): void
    {
        $deadline = microtime(true) + self::READINESS_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                throw new \RuntimeException('The simulator server stopped while starting: '.$this->serverOutput());
            }

            $response = $this->attempt('GET', '/api/__inspect', null);

            if (null !== $response) {
                if (200 !== $response->statusCode) {
                    throw new \RuntimeException(\sprintf('The simulator answered %d on /api/__inspect while starting: %s %s', $response->statusCode, $response->body, $this->serverOutput()));
                }

                return;
            }

            usleep(self::READINESS_POLL_INTERVAL_MICROSECONDS);
        }

        throw new \RuntimeException(\sprintf('The simulator server did not answer within %d seconds: %s', (int) self::READINESS_TIMEOUT_SECONDS, $this->serverOutput()));
    }

    private function send(string $method, string $path, ?string $encodedBody): SimulatorHttpResponse
    {
        $response = $this->attempt($method, $path, $encodedBody);

        if (null === $response) {
            throw new \RuntimeException(\sprintf('%s %s reached no server: %s', $method, $path, $this->serverOutput()));
        }

        return $response;
    }

    private function attempt(string $method, string $path, ?string $encodedBody): ?SimulatorHttpResponse
    {
        $requestHeaders = "Accept: application/json\r\n";
        $requestOptions = [
            'method' => $method,
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ];

        if (null !== $encodedBody) {
            $requestHeaders .= "Content-Type: application/json\r\n";
            $requestOptions['content'] = $encodedBody;
        }

        $requestOptions['header'] = $requestHeaders;
        $streamContext = stream_context_create(['http' => $requestOptions]);

        // file_get_contents emits a PHP warning while the server is not accepting
        // connections yet; we swallow it because we already detect the failure via false.
        set_error_handler(static fn (): bool => true);
        try {
            $responseBody = file_get_contents($this->baseUrl.$path, false, $streamContext);
            $responseHeaders = http_get_last_response_headers() ?? [];
        } finally {
            restore_error_handler();
        }

        if (false === $responseBody && [] === $responseHeaders) {
            return null;
        }

        $statusCode = self::parseHttpStatus($responseHeaders);

        if (null === $statusCode) {
            throw new \RuntimeException('The simulator answered without a readable HTTP status line.');
        }

        return new SimulatorHttpResponse($statusCode, \is_string($responseBody) ? $responseBody : '');
    }

    /**
     * @param list<string> $responseHeaders
     */
    private static function parseHttpStatus(array $responseHeaders): ?int
    {
        $statusLine = $responseHeaders[0] ?? null;

        if (null === $statusLine || 1 !== preg_match('#^HTTP/\d+\.?\d*\s+(\d{3})#', $statusLine, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
