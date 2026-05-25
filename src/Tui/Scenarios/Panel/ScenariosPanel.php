<?php

declare(strict_types=1);

namespace App\Tui\Scenarios\Panel;

use App\Tui\Scenarios\ScenarioCatalog;
use App\Tui\Scenarios\ScenarioResult;
use App\Tui\Scenarios\ScenarioResultKind;
use App\Tui\TerminalSafeText;
use App\Tui\TuiMode;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class ScenariosPanel
{
    private const string HEADER_TEXT = 'Scenarios - [Enter] dispatch  [Esc] back';
    private const string SEPARATOR_TEXT = '----------------------------------------------------------------';

    private readonly TextWidget $resultLine;
    private readonly SelectListWidget $list;
    private readonly ContainerWidget $container;

    public function __construct(ScenarioCatalog $catalog, TuiMode $mode)
    {
        $items = self::buildSelectListItems($catalog, $mode);

        $header = new TextWidget(self::HEADER_TEXT);
        $separator = new TextWidget(self::SEPARATOR_TEXT);
        $this->list = new SelectListWidget(items: $items, maxVisible: \count($items));
        $this->resultLine = new TextWidget('');

        $this->container = new ContainerWidget();
        $this->container->expandVertically(true);
        $this->container->add($header);
        $this->container->add($separator);
        $this->container->add($this->list);
        $this->container->add($this->resultLine);
    }

    public function widget(): ContainerWidget
    {
        return $this->container;
    }

    public function selectListWidget(): SelectListWidget
    {
        return $this->list;
    }

    public function showResult(ScenarioResult $result): void
    {
        $this->resultLine->setText(TerminalSafeText::stripControlBytes($this->formatResult($result)));
    }

    public function clearResult(): void
    {
        $this->resultLine->setText('');
    }

    public function currentResultText(): string
    {
        return $this->resultLine->getText();
    }

    private function formatResult(ScenarioResult $result): string
    {
        return match ($result->kind) {
            ScenarioResultKind::Success => $this->formatSuccess($result),
            ScenarioResultKind::ValidationFailure => 'VALIDATION '.$result->message,
            ScenarioResultKind::TransportFailure => null !== $result->httpStatus
                ? \sprintf('FAIL HTTP %d: %s', $result->httpStatus, $result->message)
                : 'FAIL '.$result->message,
            ScenarioResultKind::Unreachable => 'UNREACHABLE '.$result->message,
        };
    }

    private function formatSuccess(ScenarioResult $result): string
    {
        $statusOnly = 'OK '.($result->httpStatus ?? 0);

        if ('' === $result->message || 'OK' === $result->message) {
            return $statusOnly;
        }

        return $statusOnly.': '.$result->message;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private static function buildSelectListItems(ScenarioCatalog $catalog, TuiMode $mode): array
    {
        $items = [];
        foreach ($catalog->all($mode) as $scenario) {
            $items[] = [
                'value' => $scenario->id,
                'label' => $scenario->label,
                'description' => $scenario->description,
            ];
        }

        return $items;
    }
}
