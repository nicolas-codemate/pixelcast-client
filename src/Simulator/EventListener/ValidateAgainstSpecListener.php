<?php

declare(strict_types=1);

namespace App\Simulator\EventListener;

use App\Simulator\Controller\AbstractSimulatorController;
use App\Simulator\Controller\InspectController;
use App\Simulator\Controller\ResetController;
use App\Simulator\Logging\RequestLog;
use App\Simulator\Logging\RequestLogEntry;
use App\Simulator\Validation\OpenApiValidator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER)]
final class ValidateAgainstSpecListener
{
    public function __construct(
        private readonly OpenApiValidator $openApiValidator,
        private readonly RequestLog $requestLog,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controllerClass = $this->resolveControllerClass($event->getController());

        if (null === $controllerClass) {
            return;
        }

        if (!is_a($controllerClass, AbstractSimulatorController::class, true)) {
            return;
        }

        // Inspect and reset are simulator-only diagnostic endpoints not in the OpenAPI spec.
        if (is_a($controllerClass, InspectController::class, true)
            || is_a($controllerClass, ResetController::class, true)) {
            return;
        }

        $request = $event->getRequest();
        $validationResult = $this->openApiValidator->validate($request);

        $this->requestLog->record(new RequestLogEntry(
            method: $request->getMethod(),
            path: $request->getPathInfo(),
            body: $this->safeDecodeJson($request->getContent()),
            timestamp: new \DateTimeImmutable(),
            validationResult: $validationResult,
        ));

        if (!$validationResult->valid) {
            $errorMessage = $validationResult->errorMessage ?? 'unknown error';
            $event->setController(static fn (): JsonResponse => new JsonResponse(
                ['error' => $errorMessage],
                400,
            ));
        }
    }

    private function resolveControllerClass(mixed $controller): ?string
    {
        if (\is_array($controller) && \is_object($controller[0] ?? null)) {
            return $controller[0]::class;
        }

        if (\is_object($controller)) {
            return $controller::class;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeDecodeJson(string $content): ?array
    {
        if ('' === $content) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $stringKeyed = [];
        foreach ($decoded as $key => $value) {
            $stringKeyed[(string) $key] = $value;
        }

        return $stringKeyed;
    }
}
