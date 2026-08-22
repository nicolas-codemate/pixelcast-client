<?php

declare(strict_types=1);

namespace App\Simulator\EventListener;

use App\Simulator\Controller\AbstractSimulatorController;
use App\Simulator\Controller\InspectController;
use App\Simulator\Controller\ResetController;
use App\Simulator\Logging\RequestLog;
use App\Simulator\Logging\RequestLogEntry;
use App\Simulator\Validation\OpenApiValidator;
use League\OpenAPIValidation\PSR7\OperationAddress;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER, method: 'validateRequest')]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'validateResponse')]
final class ValidateAgainstSpecListener
{
    // The listener is a shared service, so the matched operation travels on the request
    // instead of on a property: two requests handled by the same instance would share it.
    private const string MATCHED_OPERATION_ATTRIBUTE = '_simulator_operation';

    public function __construct(
        private readonly OpenApiValidator $openApiValidator,
        private readonly RequestLog $requestLog,
    ) {
    }

    public function validateRequest(ControllerEvent $event): void
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
        $outcome = $this->openApiValidator->validate($request);
        $validationResult = $outcome->result;

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

            return;
        }

        $request->attributes->set(self::MATCHED_OPERATION_ATTRIBUTE, $outcome->matchedOperation);
    }

    public function validateResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $matchedOperation = $request->attributes->get(self::MATCHED_OPERATION_ATTRIBUTE);

        if (!$matchedOperation instanceof OperationAddress) {
            return;
        }

        // Removed before validating so the 500 below does not go through this pass again.
        $request->attributes->remove(self::MATCHED_OPERATION_ATTRIBUTE);

        $validationResult = $this->openApiValidator->validateResponse($matchedOperation, $event->getResponse());

        if (!$validationResult->valid) {
            $event->setResponse(new JsonResponse(
                ['error' => $validationResult->errorMessage ?? 'unknown error'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
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
