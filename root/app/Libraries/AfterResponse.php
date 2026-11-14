<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Work done once the response has gone to the client, so how long it takes says nothing to the client (whether an
 * email went out, say). CodeIgniter's post_system event comes before the response is sent, so this runs at shutdown:
 * under PHP-FPM after fastcgi_finish_request(); elsewhere (Apache's mod_php) after flushing a response that carries
 * its Content-Length and Connection: close, which the client takes as complete. In testing it runs at once.
 */
final class AfterResponse
{
    /** @var list<callable> */
    private static array $tasks = [];

    private static bool $registered = false;

    private function __construct()
    {
    }

    /**
     * Run $task after the response; a failure is logged
     * @param ResponseInterface $response the response about to be sent, made complete in itself for the client
     */
    public static function defer(ResponseInterface $response, callable $task): ResponseInterface
    {
        if (ENVIRONMENT === 'testing') {
            self::run($task);

            return $response;
        }
        self::$tasks[] = $task;
        if (! self::$registered) {
            self::$registered = true;
            register_shutdown_function(static function (): void {
                ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    while (ob_get_level() > 0) {
                        @ob_end_flush();
                    }
                    flush();
                }
                foreach (self::$tasks as $queued) {
                    self::run($queued);
                }
                self::$tasks = [];
            });
        }

        return $response->setHeader('Connection', 'close')->setHeader('Content-Length', (string) strlen((string) $response->getBody()));
    }

    private static function run(callable $task): void
    {
        try {
            $task();
        } catch (Throwable $e) {
            log_message('error', 'After the response: {message}', ['message' => $e->getMessage()]);
        }
    }
}
