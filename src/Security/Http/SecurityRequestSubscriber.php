<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Player\Http\PlayerCookieFactory;
use App\Security\Admin\AdminAccessPolicy;
use App\Security\Admin\AdminAuthentication;
use App\Security\Application\RequestRateLimiter;
use App\Security\Application\SecurityEventLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

final readonly class SecurityRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestRateLimiter $rateLimiter,
        private SecurityEventLogger $securityLog,
        private AdminAuthentication $adminAuthentication,
        private AdminAccessPolicy $adminAccessPolicy,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
        private string $adminAllowedIps,
        private string $kernelEnvironment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', -20],
            KernelEvents::RESPONSE => ['onResponse', -20],
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = (string) Uuid::v7();
        $request->attributes->set('_twenty_request_id', $requestId);

        if (str_starts_with($request->getPathInfo(), '/admin')) {
            if (!$this->adminAccessAllowed($request)) {
                $fingerprint = $this->rateLimiter->fingerprint($request->getClientIp() ?? 'unknown');
                $this->securityLog->log('ADMIN_ACCESS_DENIED', [
                    'requestId' => $requestId,
                    'route' => $request->attributes->getString('_route'),
                    'clientFingerprint' => substr($fingerprint, 0, 16),
                    'reason' => 'ip_not_allowed',
                ]);
                $event->setResponse($this->errorResponse(403, 'Accesso negato', 'Questa area non è disponibile dalla rete corrente.'));

                return;
            }

            $route = $request->attributes->getString('_route');
            if ($route !== 'admin_login') {
                $identity = $this->adminAuthentication->current();
                if ($identity === null) {
                    if ($request->isMethodSafe()) {
                        $request->getSession()->set('twenty_admin_target', $request->getRequestUri());
                    }
                    $event->setResponse(new RedirectResponse($this->urls->generate('admin_login')));

                    return;
                }

                if (!$this->adminAccessPolicy->allows($identity, $route)) {
                    $this->securityLog->log('ADMIN_AUTHORIZATION_DENIED', [
                        'requestId' => $requestId,
                        'adminId' => $identity->id,
                        'role' => $identity->role->value,
                        'route' => $route,
                    ]);
                    $event->setResponse($this->errorResponse(403, 'Operazione non autorizzata', 'Il tuo ruolo non consente questa operazione.'));

                    return;
                }
            }
        }

        foreach ($this->limitsFor($request) as $rule) {
            $decision = $this->rateLimiter->consume(
                $rule['scope'],
                $rule['subject'],
                $rule['limit'],
                $rule['window'],
            );
            if ($decision->allowed) {
                continue;
            }

            $this->securityLog->log('RATE_LIMITED', [
                'requestId' => $requestId,
                'route' => $request->attributes->getString('_route'),
                'scope' => $rule['scope'],
                'subjectFingerprint' => substr($this->rateLimiter->fingerprint($rule['subject']), 0, 16),
                'limit' => $decision->limit,
                'consumed' => $decision->consumed,
                'retryAfterSeconds' => $decision->retryAfterSeconds,
            ]);

            $response = $this->errorResponse(429, 'Troppe richieste', 'Attendi qualche secondo e riprova.');
            $response->headers->set('Retry-After', (string) $decision->retryAfterSeconds);
            $event->setResponse($response);

            return;
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $requestId = $request->attributes->getString('_twenty_request_id');
        if ($requestId !== '') {
            $response->headers->set('X-Request-Id', $requestId);
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",
        );

        if (str_starts_with($request->getPathInfo(), '/gioca') || str_starts_with($request->getPathInfo(), '/admin')) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        if ($this->kernelEnvironment === 'prod' && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $throwable = $event->getThrowable();
        $statusCode = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        if ($statusCode >= 500 || in_array($statusCode, [403, 404, 429], true)) {
            $this->securityLog->log('HTTP_EXCEPTION', [
                'requestId' => $request->attributes->getString('_twenty_request_id'),
                'route' => $request->attributes->getString('_route'),
                'statusCode' => $statusCode,
                'exceptionClass' => $throwable::class,
            ]);
        }

        if (!in_array($statusCode, [403, 404, 429, 500], true)) {
            return;
        }

        $copy = match ($statusCode) {
            403 => ['Accesso negato', 'Non hai i permessi necessari per questa operazione.'],
            404 => ['Pagina non trovata', 'La risorsa richiesta non esiste o non è più disponibile.'],
            429 => ['Troppe richieste', 'Attendi qualche secondo e riprova.'],
            default => ['Errore interno', 'Si è verificato un errore inatteso. Nessun dato sensibile è stato mostrato.'],
        };
        $event->setResponse($this->errorResponse($statusCode, $copy[0], $copy[1]));
    }

    private function adminAccessAllowed(Request $request): bool
    {
        $ip = $request->getClientIp();
        if ($ip === null) {
            return false;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $this->adminAllowedIps))));
        if ($allowed === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $allowed);
    }

    private function errorResponse(int $status, string $title, string $message): Response
    {
        return new Response($this->twig->render('error/status.html.twig', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
        ]), $status);
    }

    /** @return list<array{scope:string,subject:string,limit:int,window:int}> */
    private function limitsFor(Request $request): array
    {
        $route = $request->attributes->getString('_route');
        $ip = $request->getClientIp() ?? 'unknown';
        $cookie = (string) $request->cookies->get(PlayerCookieFactory::NAME, 'anonymous');

        return match ($route) {
            'app_play_start' => [
                ['scope' => 'play_start_ip', 'subject' => 'ip:'.$ip, 'limit' => 20, 'window' => 60],
            ],
            'app_play_show' => [
                ['scope' => 'play_view_session', 'subject' => 'player:'.$cookie, 'limit' => 120, 'window' => 60],
                ['scope' => 'play_view_ip', 'subject' => 'ip:'.$ip, 'limit' => 240, 'window' => 60],
            ],
            'app_play_choice' => [
                ['scope' => 'choice_session', 'subject' => 'player:'.$cookie, 'limit' => 40, 'window' => 60],
                ['scope' => 'choice_ip', 'subject' => 'ip:'.$ip, 'limit' => 120, 'window' => 60],
            ],
            'app_verification_receipt' => [
                ['scope' => 'verification_ip', 'subject' => 'ip:'.$ip, 'limit' => 120, 'window' => 60],
            ],
            'admin_login' => [
                ['scope' => 'admin_login_ip', 'subject' => 'ip:'.$ip, 'limit' => 12, 'window' => 300],
            ],
            'admin_round_open' => [
                ['scope' => 'admin_round_open_ip', 'subject' => 'ip:'.$ip, 'limit' => 10, 'window' => 60],
            ],
            'admin_simulation_run' => [
                ['scope' => 'admin_simulation_run_ip', 'subject' => 'ip:'.$ip, 'limit' => 6, 'window' => 60],
            ],
            'admin_choice_pair_create', 'admin_choice_pair_edit', 'admin_choice_pair_toggle', 'admin_choice_pair_delete',
            'admin_user_create', 'admin_user_edit', 'admin_user_password', 'admin_user_toggle' => [
                ['scope' => 'admin_write_ip', 'subject' => 'ip:'.$ip, 'limit' => 30, 'window' => 60],
            ],
            default => [],
        };
    }
}
