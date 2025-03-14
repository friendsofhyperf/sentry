<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\Sentry\Tracing\Aspect;

use Error;
use FriendsOfHyperf\Sentry\Switcher;
use FriendsOfHyperf\Sentry\Tracing\SpanStarter;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Sentry\Tracing\SpanStatus;
use Throwable;

/**
 * @method array getConfig()
 * @property array $config
 */
class GuzzleHttpClientAspect extends AbstractAspect
{
    use SpanStarter;

    public array $classes = [
        Client::class . '::transfer',
    ];

    public function __construct(
        protected ContainerInterface $container,
        protected Switcher $switcher
    ) {
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        if (
            ! $this->switcher->isTracingSpanEnable('guzzle')
            || Context::get(RpcAspect::SPAN) // If the parent span is not exists or the parent span is belongs to rpc, then skip.
        ) {
            return $proceedingJoinPoint->process();
        }

        $arguments = $proceedingJoinPoint->arguments['keys'];
        /** @var RequestInterface $request */
        $request = $arguments['request'];
        $options = $arguments['options'] ?? [];
        $guzzleConfig = (fn () => match (true) {
            method_exists($this, 'getConfig') => $this->getConfig(), // @deprecated ClientInterface::getConfig will be removed in guzzlehttp/guzzle:8.0.
            property_exists($this, 'config') => $this->config,
            default => [],
        })->call($proceedingJoinPoint->getInstance());

        // If the no_sentry_tracing option is set to true, we will not record the request.
        if (
            ($options['no_sentry_tracing'] ?? null) === true
            || ($guzzleConfig['no_sentry_tracing'] ?? null) === true
        ) {
            return $proceedingJoinPoint->process();
        }

        // Inject trace context && Start span
        $span = $this->startSpan('http.client', $request->getMethod() . ' ' . (string) $request->getUri());
        $options['headers'] = array_replace($options['headers'] ?? [], [
            'sentry-trace' => $span->toTraceparent(),
            'baggage' => $span->toBaggage(),
            'traceparent' => $span->toW3CTraceparent(),
        ]);
        $proceedingJoinPoint->arguments['keys']['options']['headers'] = $options['headers'];

        if (! $span) {
            return $proceedingJoinPoint->process();
        }

        $onStats = $options['on_stats'] ?? null;

        // Add or override the on_stats option to record the request duration.
        $proceedingJoinPoint->arguments['keys']['options']['on_stats'] = function (TransferStats $stats) use ($options, $guzzleConfig, $onStats, $span) {
            $request = $stats->getRequest();
            $uri = $request->getUri();
            $data = [
                // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                'http.query' => $uri->getQuery(),
                'http.fragment' => $uri->getFragment(),
                'http.request.method' => $request->getMethod(),
                'http.request.body.size' => strlen($options['body'] ?? ''),
                'http.request.full_url' => (string) $request->getUri(),
                'http.request.path' => $request->getUri()->getPath(),
                'http.request.scheme' => $request->getUri()->getScheme(),
                'http.request.host' => $request->getUri()->getHost(),
                'http.request.port' => $request->getUri()->getPort(),
                'http.request.user_agent' => $request->getHeaderLine('User-Agent'), // updated key for consistency
                'http.request.headers' => $request->getHeaders(),
                // Other
                'coroutine.id' => Coroutine::id(),
                'http.system' => 'guzzle',
                'http.guzzle.config' => $guzzleConfig,
                'http.guzzle.options' => $options ?? [],
                'duration' => $stats->getTransferTime() * 1000, // in milliseconds
            ];

            if ($response = $stats->getResponse()) {
                $data['response.status'] = $response->getStatusCode();
                $data['response.reason'] = $response->getReasonPhrase();
                $data['response.headers'] = $response->getHeaders();
                $data['response.body.size'] = $response->getBody()->getSize() ?? 0;

                if ($this->switcher->isTracingExtraTagEnable('response.body')) {
                    $data['response.body'] = $response->getBody()->getContents();
                    $response->getBody()->isSeekable() && $response->getBody()->rewind();
                }

                if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                    $span->setStatus(SpanStatus::internalError());
                    $span->setTags([
                        'error' => true,
                        'response.reason' => $response->getReasonPhrase(),
                    ]);
                }
            }

            if ($stats->getHandlerErrorData()) {
                $span->setStatus(SpanStatus::internalError());
                $exception = $stats->getHandlerErrorData() instanceof Throwable ? $stats->getHandlerErrorData() : new Error();
                $span->setTags([
                    'error' => true,
                    'exception.class' => $exception::class,
                    'exception.code' => $exception->getCode(),
                ]);
                if ($this->switcher->isTracingExtraTagEnable('exception.stack_trace')) {
                    $data['exception.message'] = $exception->getMessage();
                    $data['exception.stack_trace'] = (string) $exception;
                }
            }

            $span->setData($data);
            $span->finish();

            if (is_callable($onStats)) {
                ($onStats)($stats);
            }
        };

        return $proceedingJoinPoint->process();
    }
}
