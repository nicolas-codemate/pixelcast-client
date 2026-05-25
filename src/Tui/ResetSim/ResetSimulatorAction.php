<?php

declare(strict_types=1);

namespace App\Tui\ResetSim;

use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioDispatcher;
use App\Tui\Scenarios\ScenarioResult;
use App\Tui\TuiMode;

final readonly class ResetSimulatorAction
{
    private const string RESET_SCENARIO_ID = 'reset-simulator';

    public function __construct(
        private ScenarioCatalog $catalog,
        private ScenarioDispatcher $dispatcher,
    ) {
    }

    public function reset(): ScenarioResult
    {
        $resetScenario = $this->catalog->findById(self::RESET_SCENARIO_ID, TuiMode::Dev);

        if (null === $resetScenario) {
            return ScenarioResult::transportFailure(
                \sprintf('"%s" scenario missing from catalog', self::RESET_SCENARIO_ID),
            );
        }

        return $this->dispatcher->dispatch($resetScenario);
    }
}
