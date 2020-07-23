<?php

/**
 * This file is part of the authbucket/oauth2-php package.
 *
 * (c) Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AuthBucket\OAuth2\Symfony\Component\EventDispatcher;

use AuthBucket\OAuth2\Exception\ExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ExceptionListener.
 *
 * @author Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 */
class ExceptionListener implements EventSubscriberInterface
{
    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(?LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        do {
            if ($exception instanceof ExceptionInterface) {
                return $this->handleException($event, $exception);
            }
        } while (null !== $exception = $exception->getPrevious());
    }

    public static function getSubscribedEvents()
    {
        return [
            /*
             * Priority -2 is used to come after those from SecurityServiceProvider (0)
             * but before the error handlers added with Silex\EventListener\LogListener (-4)
             * and Silex\Application::error (defaults to -8)
             */
            KernelEvents::EXCEPTION => ['onKernelException', -2],
        ];
    }

    private function handleException(
        ExceptionEvent $event,
        ExceptionInterface $exception
    ) {
        if (null !== $this->logger) {
            $message = sprintf(
                '%s: %s (code %s) at %s line %s',
                get_class($exception),
                $exception->getMessage(),
                $exception->getCode(),
                $exception->getFile(),
                $exception->getLine()
            );

            if ($exception->getCode() < 500) {
                $this->logger->error($message, ['exception' => $exception]);
            } else {
                $this->logger->critical($message, ['exception' => $exception]);
            }
        }

        $message = unserialize($exception->getMessage());

        if (isset($message['redirect_uri'])) {
            $redirectUri = $message['redirect_uri'];
            unset($message['redirect_uri']);
            $redirectUri = Request::create($redirectUri, 'GET', $message)->getUri();

            $response = new RedirectResponse($redirectUri);
        } else {
            $code = $exception->getCode();

            $response = new JsonResponse($message, $code, [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
        }

        $event->setResponse($response);
    }
}
