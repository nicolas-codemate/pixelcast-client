<?php

declare(strict_types=1);

namespace App\Simulator\Controller;

use App\Simulator\State\BrightnessState;
use App\Simulator\State\SettingsState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SystemController extends AbstractSimulatorController
{
    private const string SIMULATED_FIRMWARE_VERSION = '0.1.0-sim';
    private const string SIMULATED_CHIP_MODEL = 'ESP32-SIM';
    private const int SIMULATED_CPU_FREQ_MHZ = 240;
    private const int SIMULATED_MAX_ALLOC_HEAP = 81_920;
    private const int DISPLAY_WIDTH_PIXELS = 64;
    private const int DISPLAY_HEIGHT_PIXELS = 8;
    private const int FILESYSTEM_TOTAL_BYTES = 1_048_576;
    private const string SIMULATED_WIFI_SSID = 'simulator';
    private const int SIMULATED_WIFI_RSSI = -45;
    private const string SIMULATED_WIFI_IP = '127.0.0.1';

    private static ?int $simulatorBootEpoch = null;

    #[Route('/stats', methods: ['GET'])]
    public function getStats(BrightnessState $brightness, SettingsState $settings): JsonResponse
    {
        self::$simulatorBootEpoch ??= time();

        $currentSettings = $settings->current();
        $autoRotateRaw = $currentSettings['autoRotate'] ?? null;
        $rotationEnabled = \is_bool($autoRotateRaw) ? $autoRotateRaw : true;

        return new JsonResponse([
            'version' => self::SIMULATED_FIRMWARE_VERSION,
            'uptime' => time() - self::$simulatorBootEpoch,
            'freeHeap' => memory_get_usage(true),
            'maxAllocHeap' => self::SIMULATED_MAX_ALLOC_HEAP,
            'brightness' => $brightness->current(),
            'wifi' => [
                'ssid' => self::SIMULATED_WIFI_SSID,
                'rssi' => self::SIMULATED_WIFI_RSSI,
                'ip' => self::SIMULATED_WIFI_IP,
            ],
            'display' => [
                'width' => self::DISPLAY_WIDTH_PIXELS,
                'height' => self::DISPLAY_HEIGHT_PIXELS,
            ],
            'mqtt' => [
                'connected' => false,
            ],
            'apps' => [
                'count' => 0,
                'current' => '',
                'rotationEnabled' => $rotationEnabled,
            ],
            'filesystem' => [
                'ready' => true,
                'total' => self::FILESYSTEM_TOTAL_BYTES,
                'used' => 0,
            ],
            'chipModel' => self::SIMULATED_CHIP_MODEL,
            'cpuFreq' => self::SIMULATED_CPU_FREQ_MHZ,
        ]);
    }

    #[Route('/settings', methods: ['GET'])]
    public function getSettings(SettingsState $settings): JsonResponse
    {
        return new JsonResponse($settings->current());
    }

    #[Route('/settings', methods: ['POST'])]
    public function postSettings(
        Request $request,
        SettingsState $settings,
        BrightnessState $brightness,
    ): JsonResponse {
        $body = $this->decodeJsonBody($request);
        $settings->patch($body);

        if (\array_key_exists('brightness', $body) && \is_int($body['brightness'])) {
            $brightness->set($body['brightness']);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/brightness', methods: ['POST'])]
    public function postBrightness(Request $request, BrightnessState $brightness): JsonResponse
    {
        $body = $this->decodeJsonBody($request);
        $brightnessValue = $body['brightness'] ?? 0;
        $brightness->set(\is_int($brightnessValue) ? $brightnessValue : 0);

        return new JsonResponse(['success' => true]);
    }

    // Restarting the process would surprise developers and break long-running
    // curl scripts; POST /__reset is the supported way to clear state.
    #[Route('/reboot', methods: ['POST'])]
    public function reboot(): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => 'Rebooting...',
        ]);
    }
}
